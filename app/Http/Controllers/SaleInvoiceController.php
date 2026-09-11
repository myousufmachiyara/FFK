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
    private function resolveAvailableStock(?int $variationId): float
    {
        if (!$variationId) return 0;
        $variation = ProductVariation::find($variationId);
        return $variation ? (float) $variation->stock_quantity : 0;
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
    private function resolveUnitCost(int $productId, ?int $variationId, float $fallback): float
    {
        $query = PurchaseInvoiceItem::whereNotNull('received_net_weight')
            ->where('item_id', $productId)
            ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
            ->latest('updated_at');

        $lastReceived = $query->first();

        if ($lastReceived && (float) $lastReceived->received_net_weight > 0) {
            return $lastReceived->landedUnitCost();
        }

        Log::warning('[SI] No purchase history found for costing — using sale rate as fallback unit cost.', [
            'product_id' => $productId, 'variation_id' => $variationId,
        ]);

        return $fallback;
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
            $p->computed_stock = $p->variations->sum('stock_quantity');
        });

        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();
        // Expense payees are Vendor-type accounts only (e.g. a transporter
        // like "Suzuki wala") — not arbitrary chart-of-accounts entries.
        $payeeAccounts = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
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
            $ratePer40kg  = (float) $itemData['rate_per_40kg'];
            $discountPct  = (float) ($itemData['discount'] ?? 0);

            $calc = SaleInvoiceItem::computeLine($wtPerPacking, $qty, $netOverride, $ratePer40kg, $discountPct, $kgPerMaund);

            $unitCost = $this->resolveUnitCost((int) $itemData['product_id'], $itemData['variation_id'] ?? null, $calc['ratePerKg']);

            $invoice->items()->create([
                'product_id'      => $itemData['product_id'],
                'variation_id'    => $itemData['variation_id'] ?? null,
                'packing_unit_id' => $itemData['packing_unit_id'] ?? null,
                'wt_per_packing'  => $wtPerPacking,
                'quantity'        => $qty,
                'gross_weight'    => $calc['grossWeight'],
                'net_weight'      => $calc['netWeight'],
                'rate_per_40kg'   => $ratePer40kg,
                'sale_price'      => $calc['ratePerKg'],
                'discount'        => $discountPct,
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
            'discount'                    => 'nullable|numeric|min:0',
            'payment_account_id'          => 'nullable|exists:chart_of_accounts,id',
            'amount_received'             => 'nullable|numeric|min:0',
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'required|exists:products,id',
            'items.*.variation_id'        => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'     => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'      => 'required|numeric|min:0.001',
            'items.*.quantity'            => 'required|numeric|min:0.01',
            'items.*.net_weight'          => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'       => 'required|numeric|min:0',
            'items.*.discount'            => 'nullable|numeric|min:0|max:100',
            'expenses'                    => 'nullable|array',
            'expenses.*.expense_type'     => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'      => 'nullable|string|max:255',
            'expenses.*.amount'           => 'required_with:expenses|numeric|min:0',
            'expenses.*.payee_account_id' => 'required_with:expenses|exists:chart_of_accounts,id',
        ]);

        DB::beginTransaction();

        try {
            foreach ($request->items as $itemData) {
                $available = $this->resolveAvailableStock($itemData['variation_id'] ?? null);
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
                'discount'   => (float) ($request->discount ?? 0),
                'created_by' => auth()->id(),
            ]);

            $result = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $totals = $result['totals'];
            $totalCost = $result['total_cost'];

            $netAmount = max(0, round($totals['net_amount'] - (float) ($request->discount ?? 0), 2));
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

            // 4) Each expense — always increases what customer owes; Company
            // pays the chosen payee account (no Vendor concept on Sale).
            foreach ($invoice->expenses as $i => $expense) {
                $this->postVoucher(
                    $request->date, $invoice->account, $expense->payeeAccount, (float) $expense->amount,
                    "SI-{$invoice->id}-EXPENSE-" . ($i + 1),
                    "Sale Invoice #{$invoiceNo} — {$expense->typeLabel()}, payable to {$expense->payeeAccount->name}"
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
            $p->computed_stock = $p->variations->sum('stock_quantity');
        });

        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();
        $payeeAccounts = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
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
            'discount'                    => 'nullable|numeric|min:0',
            'payment_account_id'          => 'nullable|exists:chart_of_accounts,id',
            'amount_received'             => 'nullable|numeric|min:0', // incremental new payment
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'required|exists:products,id',
            'items.*.variation_id'        => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'     => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'      => 'required|numeric|min:0.001',
            'items.*.quantity'            => 'required|numeric|min:0.01',
            'items.*.net_weight'          => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'       => 'required|numeric|min:0',
            'items.*.discount'            => 'nullable|numeric|min:0|max:100',
            'expenses'                    => 'nullable|array',
            'expenses.*.expense_type'     => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'      => 'nullable|string|max:255',
            'expenses.*.amount'           => 'required_with:expenses|numeric|min:0',
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
                $available = $this->resolveAvailableStock($itemData['variation_id'] ?? null);
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
                'remarks'     => $request->remarks,
                'discount'    => (float) ($request->discount ?? 0),
            ]);

            $result = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $totals = $result['totals'];
            $totalCost = $result['total_cost'];

            $netAmount = max(0, round($totals['net_amount'] - (float) ($request->discount ?? 0), 2));
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
                $this->postVoucher(
                    $request->date, ChartOfAccounts::findOrFail($request->account_id), $expense->payeeAccount, (float) $expense->amount,
                    "SI-{$invoice->id}-EXPENSE-" . ($i + 1),
                    "Sale Invoice #{$invoice->invoice_no} — {$expense->typeLabel()}, payable to {$expense->payeeAccount->name} (updated)"
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

    public function print($id)
    {
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation', 'expenses.payeeAccount'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SI-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'SALE INVOICE', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Type: ' . ucfirst($invoice->type), 0, 1, 'R');
        $pdf->Ln(5);

        $custHtml = '
        <table width="50%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Customer:</b></td><td width="60%">' . ($invoice->account->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->writeHTML($custHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '
        <table border="1" cellpadding="4" style="font-size:9px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="18%">Item</th><th width="8%">Qty</th><th width="10%">Net Wt</th>
                    <th width="12%">Rate (40kg)</th><th width="10%">Rate (kg)</th>
                    <th width="10%">Disc %</th><th width="15%">Total</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $item) {
            $html .= '
                <tr>
                    <td width="18%">' . e($item->product->name ?? '-') . '</td>
                    <td width="8%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->rate_per_40kg, 2) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->sale_price, 2) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->discount, 2) . '</td>
                    <td width="15%" style="text-align:right;">' . number_format($item->total, 2) . '</td>
                </tr>';
        }

        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(3);

        if ($invoice->expenses->count()) {
            $expHtml = '<table border="1" cellpadding="4" style="font-size:9px;"><thead><tr style="background-color:#f2f2f2;font-weight:bold;"><th width="25%">Expense</th><th width="35%">Description</th><th width="20%">Payable To</th><th width="20%">Amount</th></tr></thead><tbody>';
            foreach ($invoice->expenses as $exp) {
                $expHtml .= '<tr><td width="25%">' . $exp->typeLabel() . '</td><td width="35%">' . e($exp->description) . '</td><td width="20%">' . ($exp->payeeAccount->name ?? '-') . '</td><td width="20%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
            }
            $expHtml .= '</tbody></table>';
            $pdf->writeHTML($expHtml, true, false, false, false, '');
            $pdf->Ln(3);
        }

        $summaryHtml = '
        <table width="60%" border="1" cellpadding="4" style="font-size:10px;" align="right">
            <tr><td><b>Gross Weight</b></td><td style="text-align:right;">' . number_format($invoice->total_gross_weight, 2) . ' kg</td></tr>
            <tr><td><b>Net Weight</b></td><td style="text-align:right;">' . number_format($invoice->total_weight, 2) . ' kg</td></tr>
            <tr><td><b>Total Item Amount</b></td><td style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td></tr>
            <tr><td><b>Total Expense Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Bill Amount</td><td style="text-align:right;">' . number_format($invoice->totalBillAmount(), 2) . '</td></tr>
            <tr><td>Amount Received</td><td style="text-align:right;">' . number_format($invoice->amount_received, 2) . '</td></tr>
            <tr style="font-weight:bold;color:#b30000;"><td>Balance Due</td><td style="text-align:right;">' . number_format($invoice->remainingBalance(), 2) . '</td></tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');

        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        return $pdf->Output('SI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}