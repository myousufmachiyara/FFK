<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\SaleInvoiceExpense;
use App\Models\PurchaseInvoiceItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\MeasurementUnit;
use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    use \App\Http\Controllers\Concerns\RendersInvoicePdf;

    private function resolveAccount(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("sale_accounts.{$configKey}");
        $account = ChartOfAccounts::where('account_code', $code)->first();

        if (!$account) {
            throw new \Exception("{$label} account (code {$code}) not found. Check config/sale_accounts.php and your Chart of Accounts.");
        }

        return $account;
    }

    private function salesRevenueAccount(): ChartOfAccounts { return $this->resolveAccount('sales_revenue', 'Sales Revenue'); }
    private function cogsAccount(): ChartOfAccounts         { return $this->resolveAccount('cogs', 'Cost of Goods Sold'); }
    private function inventoryAccount(): ChartOfAccounts    { return $this->resolveAccount('inventory', 'Inventory / Stock in Hand'); }

    private function kgPerMaund(): int
    {
        return (int) config('purchase_settings.kg_per_maund', 40);
    }

    /** Posts a simple DR/CR voucher, auto-flipping legs if the amount would be negative. */
    private function postVoucher(string $date, ChartOfAccounts $dr, ChartOfAccounts $cr, float $amount, string $reference, string $remarks): void
    {
        if (abs($amount) < 0.01) return;

        if ($amount < 0) {
            [$dr, $cr] = [$cr, $dr];
            $amount = abs($amount);
            Log::warning('[SI] Negative amount voucher leg flipped', ['reference' => $reference, 'amount' => $amount]);
        }

        Voucher::create([
            'date'         => $date,
            'voucher_type' => 'journal',
            'ac_dr_sid'    => $dr->id,
            'ac_cr_sid'    => $cr->id,
            'amount'       => round($amount, 2),
            'reference'    => $reference,
            'remarks'      => $remarks,
        ]);
    }

    /** Bags available — the tracked stock unit. */
    /**
     * Available stock = opening_stock + stock_quantity (bags). For a
     * variation, both live on ProductVariation directly. For a product
     * with no variations, opening_stock lives on Product itself, and
     * the transactional side has to be reconstructed from history (no
     * live column exists for that case) — same logic Inventory Report's
     * Stock In Hand uses for its no-variation branch.
     */
    private function resolveAvailableStock(?int $variationId, ?int $productId = null): float
    {
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            return $variation ? $variation->availableStock() : 0;
        }

        if (!$productId) return 0;

        $product = Product::find($productId);
        if (!$product) return 0;

        $purchased = (float) DB::table('purchase_invoice_items')
            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->where('purchase_invoice_items.item_id', $productId)
            ->where('purchase_invoices.status', 'received')
            ->whereNull('purchase_invoices.deleted_at')
            ->sum(DB::raw('COALESCE(purchase_invoice_items.received_packing_qty, purchase_invoice_items.quantity)'));

        $sold = (float) DB::table('sale_invoice_items')
            ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
            ->where('sale_invoice_items.product_id', $productId)
            ->sum('sale_invoice_items.quantity');

        return round((float) $product->opening_stock + $purchased - $sold, 3);
    }

    /**
     * Adjusts both tracked stock figures together: stock_quantity (bags —
     * the authoritative figure stock is checked/deducted against) and
     * stock_weight (kg — maintained in parallel purely for display).
     */
    private function adjustStock(?int $variationId, float $qtyDelta, float $weightDelta): void
    {
        if (!$variationId) {
            Log::warning('[SI] No variation_id on item — stock not adjusted.');
            return;
        }
        $variation = ProductVariation::find($variationId);
        if (!$variation) {
            Log::warning('[SI] Variation not found for stock adjustment', ['variation_id' => $variationId]);
            return;
        }
        if ($qtyDelta >= 0) {
            $variation->increment('stock_quantity', $qtyDelta);
        } else {
            $variation->decrement('stock_quantity', abs($qtyDelta));
        }
        if ($weightDelta >= 0) {
            $variation->increment('stock_weight', $weightDelta);
        } else {
            $variation->decrement('stock_weight', abs($weightDelta));
        }
    }

    /** Cost per KG — reuses Purchase's landed cost. Multiplied by net_weight, not quantity. */
    /**
     * FIX: previously used only the LATEST Received purchase's rate.
     * Now uses a proper weighted average across every Received purchase
     * of this product/variation, folding in the item's allocated share
     * of Other Expenses too — the same purchase can legitimately happen
     * at different rates across invoices, so COGS should reflect the
     * blend, not whatever the most recent invoice happened to say.
     */
    private function resolveUnitCost(int $productId, ?int $variationId, float $fallback): float
    {
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            if ($variation) {
                $cost = $variation->averageLandedCost();
                if ($cost > 0) return $cost;
            }
        } else {
            $cost = $this->averageLandedCostForProduct($productId);
            if ($cost > 0) return $cost;
        }

        Log::warning('[SI] No purchase history (and no opening rate) found for costing — using sale rate as fallback unit cost.', [
            'product_id' => $productId, 'variation_id' => $variationId,
        ]);

        return $fallback;
    }

    /** Same averaging as ProductVariation::averageLandedCost(), for products with no variations. */
    private function averageLandedCostForProduct(int $productId): float
    {
        $product = Product::find($productId);

        $totals = DB::table('purchase_invoice_items')
            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->where('purchase_invoice_items.item_id', $productId)
            ->where('purchase_invoices.status', 'received')
            ->whereNull('purchase_invoices.deleted_at')
            ->whereNotNull('purchase_invoice_items.received_net_weight')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight * purchase_invoice_items.price + COALESCE(purchase_invoice_items.allocated_additional_cost, 0)) as total_cost')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight) as total_weight')
            ->first();

        $totalCost   = (float) ($totals->total_cost ?? 0);
        $totalWeight = (float) ($totals->total_weight ?? 0);

        if ($product && (float) $product->opening_weight > 0 && (float) $product->opening_rate > 0) {
            $totalCost   += (float) $product->opening_weight * (float) $product->opening_rate;
            $totalWeight += (float) $product->opening_weight;
        }

        return $totalWeight > 0 ? round($totalCost / $totalWeight, 4) : 0.0;
    }

    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = SaleInvoice::with(['account', 'items']);

        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        $invoices = $query->latest()->get();

        return view('sales.index', compact('invoices'));
    }

    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $products->each(function ($p) {
            $p->computed_stock = $p->variations->sum('stock_quantity') + $p->variations->sum('opening_stock');
        });

        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();
        // Expense payees are Vendor-type accounts only (e.g. a transporter
        // like "Suzuki wala") — picked per-expense, since Sale has no
        // single invoice-level vendor to fall back to.
        $payeeAccounts = ChartOfAccounts::whereIn('account_type', ['vendor', 'cash', 'bank'])->orderBy('name')->get();
        $units = MeasurementUnit::all();
        $kgPerMaund = $this->kgPerMaund();

        return view('sales.create', compact('products', 'customers', 'paymentAccounts', 'payeeAccounts', 'units', 'kgPerMaund'));
    }

    /** Shared server-side item + expense computation. Never trust client math. */
    private function syncItemsAndExpenses(SaleInvoice $invoice, array $items, array $expenses): array
    {
        $invoice->items()->delete();
        $invoice->expenses()->delete();

        $kgPerMaund = $this->kgPerMaund();
        $totalQty = $totalWeight = $totalGrossWeight = $totalAmount = $totalCost = 0;

        foreach ($items as $itemData) {
            $qty          = (float) $itemData['quantity'];
            $wtPerPacking = (float) $itemData['wt_per_packing'];
            $netOverride  = isset($itemData['net_weight']) && $itemData['net_weight'] !== ''
                ? (float) $itemData['net_weight'] : null;
            $ratePer40kg  = (float) ($itemData['rate_per_40kg'] ?? 0);
            $ratePerKgIn  = isset($itemData['rate_per_kg']) && $itemData['rate_per_kg'] !== ''
                ? (float) $itemData['rate_per_kg'] : null;

            $calc = SaleInvoiceItem::computeLine($wtPerPacking, $qty, $netOverride, $ratePer40kg, $kgPerMaund, $ratePerKgIn);

            $unitCost = $this->resolveUnitCost((int) $itemData['product_id'], $itemData['variation_id'] ?? null, $calc['ratePerKg']);

            $invoice->items()->create([
                'product_id'      => $itemData['product_id'],
                'variation_id'    => $itemData['variation_id'] ?? null,
                'packing_unit_id' => $itemData['packing_unit_id'] ?? null,
                'wt_per_packing'  => $wtPerPacking,
                'quantity'        => $qty,
                'gross_weight'    => $calc['grossWeight'],
                'net_weight'      => $calc['netWeight'],
                'rate_per_40kg'   => $calc['ratePer40kg'],
                'sale_price'      => $calc['ratePerKg'],
                'total'           => $calc['total'],
                'unit_cost'       => $unitCost,
            ]);

            $totalQty        += $qty;
            $totalWeight      += $calc['netWeight'];
            $totalGrossWeight += $calc['grossWeight'];
            $totalAmount      += $calc['total'];
            $totalCost        += $unitCost * $calc['netWeight'];

            $this->adjustStock($itemData['variation_id'] ?? null, -$qty, -$calc['netWeight']);
        }

        $totalOtherExpenses = 0;
        foreach ($expenses as $expenseData) {
            if (empty($expenseData['amount'])) continue;
            $amount = (float) $expenseData['amount'];
            $totalOtherExpenses += $amount;

            $invoice->expenses()->create([
                'expense_type'      => $expenseData['expense_type'],
                'description'       => $expenseData['description'] ?? null,
                'amount'            => $amount,
                'paid_by'           => $expenseData['paid_by'],
                'payee_account_id'  => $expenseData['payee_account_id'],
            ]);
        }

        return [
            'totals' => [
                'total_quantity'         => $totalQty,
                'total_weight'           => round($totalWeight, 3),
                'total_gross_weight'     => round($totalGrossWeight, 3),
                'net_amount'             => round($totalAmount, 2),
                'total_other_expenses'   => round($totalOtherExpenses, 2),
            ],
            'total_cost' => round($totalCost, 2),
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'date'                        => 'required|date',
            'account_id'                  => 'required|exists:chart_of_accounts,id',
            'type'                        => 'required|in:cash,credit',
            'credit_days'                 => 'required_if:type,credit|nullable|integer|min:1',
            'remarks'                     => 'nullable|string',
            'bilty_no'                    => 'nullable|string|max:255',
            'transport_name'              => 'nullable|string|max:255',
            'payment_account_id'          => 'nullable|exists:chart_of_accounts,id',
            'amount_received'             => 'nullable|numeric|min:0',
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'required|exists:products,id',
            'items.*.variation_id'        => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'     => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'      => 'required|numeric|min:0.001',
            'items.*.quantity'            => 'required|numeric|min:0.01',
            'items.*.net_weight'          => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'       => 'required_without:items.*.rate_per_kg|nullable|numeric|min:0',
            'items.*.rate_per_kg'         => 'nullable|numeric|min:0',
            'expenses'                    => 'nullable|array',
            'expenses.*.expense_type'     => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'      => 'nullable|string|max:255',
            'expenses.*.amount'           => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'          => 'required_with:expenses|in:vendor,company',
            // Always required — Sale has no invoice-level vendor to fall
            // back to, so both "Vendor" and "Company" need their own
            // account picked directly on the expense row.
            'expenses.*.payee_account_id' => 'required_with:expenses|exists:chart_of_accounts,id',
        ]);

        DB::beginTransaction();

        try {
            foreach ($request->items as $itemData) {
                $available = $this->resolveAvailableStock($itemData['variation_id'] ?? null, $itemData['product_id'] ?? null);
                $qty = (float) $itemData['quantity'];

                if ($qty > $available) {
                    throw new \Exception("Insufficient stock. Available: {$available} bags, Requested: {$qty} bags.");
                }
            }

            $last      = SaleInvoice::orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no ?? $last->id) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = SaleInvoice::create([
                'invoice_no' => $invoiceNo,
                'date'       => $request->date,
                'account_id' => $request->account_id,
                'type'       => $request->type,
                'credit_days'=> $request->type === 'credit' ? $request->credit_days : null,
                'remarks'    => $request->remarks,
                'bilty_no'       => $request->bilty_no,
                'transport_name' => $request->transport_name,
                'created_by' => auth()->id(),
            ]);

            $result = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $totals = $result['totals'];
            $totalCost = $result['total_cost'];

            $netAmount = round($totals['net_amount'], 2);
            $totals['net_amount'] = $netAmount;

            $totalBillAmount = round($netAmount + $totals['total_other_expenses'], 2);
            $amountReceived = (float) ($request->amount_received ?? 0);

            if ($amountReceived > $totalBillAmount) {
                throw new \Exception('Amount received cannot exceed the total bill amount.');
            }
            if ($amountReceived > 0 && !$request->payment_account_id) {
                throw new \Exception('A payment account is required when an amount has been received.');
            }

            $invoice->update(array_merge($totals, ['amount_received' => $amountReceived]));

            // 1) Revenue — full item amount, against the customer's AR.
            if ($netAmount > 0) {
                $this->postVoucher(
                    $request->date, $invoice->account, $this->salesRevenueAccount(), $netAmount,
                    "SI-{$invoice->id}-REVENUE", "Sale Invoice #{$invoiceNo} — revenue recognized"
                );
            }

            // 2) Receipt — only if something was collected now.
            if ($amountReceived > 0) {
                $this->postVoucher(
                    $request->date, ChartOfAccounts::findOrFail($request->payment_account_id), $invoice->account, $amountReceived,
                    "SI-{$invoice->id}-RECEIPT-1", "Sale Invoice #{$invoiceNo} — payment received"
                );
            }

            // 3) COGS
            if ($totalCost > 0) {
                $this->postVoucher(
                    $request->date, $this->cogsAccount(), $this->inventoryAccount(), $totalCost,
                    "SI-{$invoice->id}-COGS", "Sale Invoice #{$invoiceNo} — cost of goods sold"
                );
            }

            // 4) Each expense — always increases what customer owes; credit
            // side routes to whichever account was picked directly on
            // this expense (paid_by is just a classification label here,
            // since Sale has no single invoice-level vendor to default to).
            foreach ($invoice->expenses as $i => $expense) {
                if (!$expense->payeeAccount) {
                    throw new \Exception("Expense #{$expense->id} ({$expense->typeLabel()}) has no valid payee account.");
                }

                $this->postVoucher(
                    $request->date, $invoice->account, $expense->payeeAccount, (float) $expense->amount,
                    "SI-{$invoice->id}-EXPENSE-" . ($i + 1),
                    "Sale Invoice #{$invoiceNo} — {$expense->typeLabel()}, paid by {$expense->paidByLabel()} ({$expense->payeeAccount->name})"
                );
            }

            DB::commit();
            Log::info('[SI] Stored successfully', ['invoice_id' => $invoice->id, 'net_amount' => $netAmount]);

            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice created.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SI] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation', 'items.packingUnit', 'expenses.payeeAccount'])->findOrFail($id);
        $vouchers = $invoice->vouchers();

        return view('sales.show', compact('invoice', 'vouchers'));
    }

    public function edit($id)
    {
        $invoice = SaleInvoice::with(['items', 'expenses'])->findOrFail($id);

        $products = Product::with('variations')->orderBy('name')->get();
        $products->each(function ($p) {
            $p->computed_stock = $p->variations->sum('stock_quantity') + $p->variations->sum('opening_stock');
        });

        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();
        $payeeAccounts = ChartOfAccounts::whereIn('account_type', ['vendor', 'cash', 'bank'])->orderBy('name')->get();
        $units = MeasurementUnit::all();
        $kgPerMaund = $this->kgPerMaund();

        $amountReceived = (float) $invoice->amount_received;

        return view('sales.edit', compact('invoice', 'products', 'customers', 'paymentAccounts', 'payeeAccounts', 'units', 'kgPerMaund', 'amountReceived'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'date'                        => 'required|date',
            'account_id'                  => 'required|exists:chart_of_accounts,id',
            'type'                        => 'required|in:cash,credit',
            'credit_days'                 => 'required_if:type,credit|nullable|integer|min:1',
            'remarks'                     => 'nullable|string',
            'bilty_no'                    => 'nullable|string|max:255',
            'transport_name'              => 'nullable|string|max:255',
            'payment_account_id'          => 'nullable|exists:chart_of_accounts,id',
            'amount_received'             => 'nullable|numeric|min:0', // incremental new payment
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'required|exists:products,id',
            'items.*.variation_id'        => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'     => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'      => 'required|numeric|min:0.001',
            'items.*.quantity'            => 'required|numeric|min:0.01',
            'items.*.net_weight'          => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'       => 'required_without:items.*.rate_per_kg|nullable|numeric|min:0',
            'items.*.rate_per_kg'         => 'nullable|numeric|min:0',
            'expenses'                    => 'nullable|array',
            'expenses.*.expense_type'     => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'      => 'nullable|string|max:255',
            'expenses.*.amount'           => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'          => 'required_with:expenses|in:vendor,company',
            'expenses.*.payee_account_id' => 'required_with:expenses|exists:chart_of_accounts,id',
        ]);

        DB::beginTransaction();

        try {
            $invoice = SaleInvoice::with(['items', 'expenses'])->lockForUpdate()->findOrFail($id);

            // Reverse old stock (by bags and weight) before validating new amounts.
            foreach ($invoice->items as $oldItem) {
                $this->adjustStock($oldItem->variation_id, +(float) $oldItem->quantity, +(float) $oldItem->net_weight);
            }

            foreach ($request->items as $itemData) {
                $available = $this->resolveAvailableStock($itemData['variation_id'] ?? null, $itemData['product_id'] ?? null);
                $qty = (float) $itemData['quantity'];

                if ($qty > $available) {
                    throw new \Exception("Insufficient stock. Available: {$available} bags, Requested: {$qty} bags.");
                }
            }

            $invoice->update([
                'date'        => $request->date,
                'account_id'  => $request->account_id,
                'type'        => $request->type,
                'credit_days' => $request->type === 'credit' ? $request->credit_days : null,
                'remarks'        => $request->remarks,
                'bilty_no'       => $request->bilty_no,
                'transport_name' => $request->transport_name,
            ]);

            $result = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $totals = $result['totals'];
            $totalCost = $result['total_cost'];

            $netAmount = round($totals['net_amount'], 2);
            $totals['net_amount'] = $netAmount;

            $totalBillAmount = round($netAmount + $totals['total_other_expenses'], 2);
            $newPaymentNow = (float) ($request->amount_received ?? 0);
            $priorReceived = (float) $invoice->getOriginal('amount_received');

            if (($priorReceived + $newPaymentNow) > $totalBillAmount) {
                throw new \Exception('Total amount received cannot exceed the revised total bill amount.');
            }
            if ($newPaymentNow > 0 && !$request->payment_account_id) {
                throw new \Exception('A payment account is required to record a new payment.');
            }

            $invoice->update(array_merge($totals, ['amount_received' => $priorReceived + $newPaymentNow]));

            if ($netAmount > 0) {
                Voucher::updateOrCreate(
                    ['reference' => "SI-{$invoice->id}-REVENUE", 'voucher_type' => 'journal'],
                    ['date' => $request->date, 'ac_dr_sid' => $request->account_id, 'ac_cr_sid' => $this->salesRevenueAccount()->id,
                     'amount' => $netAmount, 'remarks' => "Sale Invoice #{$invoice->invoice_no} — revenue (updated)"]
                );
            }
            if ($totalCost > 0) {
                Voucher::updateOrCreate(
                    ['reference' => "SI-{$invoice->id}-COGS", 'voucher_type' => 'journal'],
                    ['date' => $request->date, 'ac_dr_sid' => $this->cogsAccount()->id, 'ac_cr_sid' => $this->inventoryAccount()->id,
                     'amount' => round($totalCost, 2), 'remarks' => "Sale Invoice #{$invoice->invoice_no} — COGS (updated)"]
                );
            }

            Voucher::where('reference', 'like', "SI-{$invoice->id}-EXPENSE-%")->delete();
            foreach ($invoice->expenses as $i => $expense) {
                if (!$expense->payeeAccount) {
                    throw new \Exception("Expense #{$expense->id} ({$expense->typeLabel()}) has no valid payee account.");
                }

                $this->postVoucher(
                    $request->date, ChartOfAccounts::findOrFail($request->account_id), $expense->payeeAccount, (float) $expense->amount,
                    "SI-{$invoice->id}-EXPENSE-" . ($i + 1),
                    "Sale Invoice #{$invoice->invoice_no} — {$expense->typeLabel()}, paid by {$expense->paidByLabel()} ({$expense->payeeAccount->name}) (updated)"
                );
            }

            if ($newPaymentNow > 0) {
                $receiptCount = Voucher::where('reference', 'like', "SI-{$invoice->id}-RECEIPT-%")->count();
                $this->postVoucher(
                    $request->date, ChartOfAccounts::findOrFail($request->payment_account_id), ChartOfAccounts::findOrFail($request->account_id), $newPaymentNow,
                    "SI-{$invoice->id}-RECEIPT-" . ($receiptCount + 1),
                    "Sale Invoice #{$invoice->invoice_no} — additional payment received"
                );
            }

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SI] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // Full reversal (stock + all vouchers) — this IS Sale's "undo", since
    // Sale has no intermediate status stages.
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $invoice = SaleInvoice::with('items')->lockForUpdate()->findOrFail($id);

            foreach ($invoice->items as $item) {
                $this->adjustStock($item->variation_id, +(float) $item->quantity, +(float) $item->net_weight);
            }

            Voucher::where('reference', 'like', "SI-{$invoice->id}-%")->delete();

            $invoice->items()->delete();
            $invoice->expenses()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('sale_invoices.index')
                ->with('success', 'Invoice deleted and stock restored.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SI] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice: ' . $e->getMessage());
        }
    }

    /** English number-to-words, standard Million/Thousand system, whole rupees. */
    private function numberToWords(float $number): string
    {
        $number = (int) round($number);
        if ($number == 0) return 'Zero';

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $convertHundreds = function ($n) use (&$convertHundreds, $ones, $tens) {
            $str = '';
            if ($n >= 100) {
                $str .= $ones[intdiv($n, 100)] . ' Hundred ';
                $n %= 100;
            }
            if ($n >= 20) {
                $str .= $tens[intdiv($n, 10)] . ' ';
                $n %= 10;
            }
            if ($n > 0) {
                $str .= $ones[$n] . ' ';
            }
            return $str;
        };

        $parts = [];
        $billions = intdiv($number, 1000000000); $number %= 1000000000;
        $millions = intdiv($number, 1000000);    $number %= 1000000;
        $thousands = intdiv($number, 1000);       $number %= 1000;
        $rest = $number;

        if ($billions  > 0) $parts[] = trim($convertHundreds($billions))  . ' Billion';
        if ($millions  > 0) $parts[] = trim($convertHundreds($millions))  . ' Million';
        if ($thousands > 0) $parts[] = trim($convertHundreds($thousands)) . ' Thousand';
        if ($rest      > 0) $parts[] = trim($convertHundreds($rest));

        return trim(implode(' ', $parts));
    }

    public function print($id)
    {
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation', 'expenses'])->findOrFail($id);

        $pdf = $this->newInvoicePdf('Sale Invoice #' . $invoice->invoice_no);
        $this->pdfCompanyHeader($pdf);

        $paymentTermsLine = ucfirst($invoice->type);
        if ($invoice->isCredit() && $invoice->credit_days) {
            $paymentTermsLine .= ' (' . $invoice->credit_days . ' days)';
        }
        $dueDateLine = ($invoice->isCredit() && $invoice->dueDate()) ? $invoice->dueDate()->format('d-M-Y') : '—';

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Invoice No</b></td><td width="5%">:</td><td width="55%">SI-' . $invoice->invoice_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($invoice->date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Payment Terms</b></td><td>:</td><td>' . $paymentTermsLine . '</td></tr>
            <tr><td><b>Due Date</b></td><td>:</td><td>' . $dueDateLine . '</td></tr>
        </table>';
        $this->pdfTitleBar($pdf, 'SALE INVOICE', $infoHtml);

        // ── Two boxed detail sections: Customer | Transport ─────────
        $boxY = 60;
        $this->pdfSectionHeading($pdf, 10, $boxY, 90, 'Customer Details');
        $this->pdfSectionHeading($pdf, 105, $boxY, 95, 'Transport Details', true);

        $custHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="35%"><b>Customer</b></td><td width="5%">:</td><td width="60%">' . e($invoice->account->name ?? 'N/A') . '</td></tr>
        </table>';
        $transportHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="35%"><b>Bilti No</b></td><td width="5%">:</td><td width="60%">' . ($invoice->bilty_no ?: '-') . '</td></tr>
            <tr><td><b>Transport</b></td><td>:</td><td>' . ($invoice->transport_name ?: '-') . '</td></tr>
        </table>';

        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(90, 16, 10, $boxY + 7, $custHtml, 1, 0);
        $pdf->SetXY(105, $boxY + 7);
        $pdf->writeHTMLCell(95, 16, 105, $boxY + 7, $transportHtml, 1, 1);

        $pdf->SetY($boxY + 26);

        // ── Items table ─────────────────────────────────────────────
        $html = '
        <table border="1" cellpadding="3" style="font-size:9px;">
            <thead>
                <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                    <th width="26%">Description</th><th width="8%">Qty</th>
                    <th width="12%">Gross Wt</th><th width="12%">Net Wt</th>
                    <th width="13%">Rate (40kg)</th><th width="12%">Rate (kg)</th>
                    <th width="17%">Total</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $index => $item) {
            $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
            $html .= '
                <tr style="background-color:' . $rowBg . ';">
                    <td width="26%">' . e($this->itemDescription($item)) . '</td>
                    <td width="8%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->gross_weight, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="13%" style="text-align:right;">' . number_format($item->rate_per_40kg, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->sale_price, 2) . '</td>
                    <td width="17%" style="text-align:right;">' . number_format($item->total, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#F5EFDF;">
                    <td colspan="6" style="text-align:right;">Total Item Amount</td>
                    <td style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td>
                </tr>
            </tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Additional Info (expenses) | Totals — side by side ──────
        // Only the expense type and its amount are printed; which account
        // it was booked against is internal and not the customer's business.
        $sideY = $pdf->GetY();

        $expRows = '';
        foreach ($invoice->expenses as $exp) {
            $expRows .= '<tr><td width="65%">' . $exp->typeLabel() . '</td><td width="35%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
        }
        if (!$invoice->expenses->count()) {
            $expRows = '<tr><td colspan="2" style="color:#888;">No Other Expenses</td></tr>';
        }

        $leftHtml = '
        <table width="100%" cellpadding="2" style="font-size:9px;">
            <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;"><td colspan="2">  Additional Information — Expenses</td></tr>
            ' . $expRows . '
            <tr style="font-weight:bold;background-color:#F5EFDF;"><td>Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
        </table>';

        $rightRows = '
            <tr><td width="55%">Sub Total (Items)</td><td width="45%" style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td></tr>
            <tr><td>Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;font-size:11px;"><td>GRAND TOTAL</td><td style="text-align:right;">' . number_format($invoice->totalBillAmount(), 2) . '</td></tr>
            <tr><td>Amount Received</td><td style="text-align:right;">' . number_format($invoice->amount_received, 2) . '</td></tr>
            <tr style="font-weight:bold;color:#b30000;"><td>Balance Due</td><td style="text-align:right;">' . number_format($invoice->remainingBalance(), 2) . '</td></tr>';

        $rightHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">' . $rightRows . '</table>';

        $pdf->SetXY(10, $sideY);
        $pdf->writeHTMLCell(90, 0, 10, $sideY, $leftHtml, 1, 0);
        $leftEndY = $pdf->GetY();

        $pdf->SetXY(105, $sideY);
        $pdf->writeHTMLCell(95, 0, 105, $sideY, $rightHtml, 1, 1);
        $rightEndY = $pdf->GetY();

        $pdf->SetY(max($leftEndY, $rightEndY) + 4);

        // ── Amount in Words ──────────────────────────────────────────
        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Rupees in Words:</b> ' . $this->numberToWords($invoice->totalBillAmount()) . ' Only.</td>
            </tr>
        </table>';
        $pdf->writeHTML($wordsHtml, true, false, false, false, '');
        $pdf->Ln(2);

        if ($invoice->remarks) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        $this->pdfSignature($pdf);

        return $pdf->Output('SI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
