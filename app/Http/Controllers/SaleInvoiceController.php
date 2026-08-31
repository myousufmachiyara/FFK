<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\PurchaseInvoiceItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Account resolution — never hardcode IDs, always via config +
    // Chart of Accounts lookup.
    // ─────────────────────────────────────────────────────────────
    private function resolveAccount(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("sale_accounts.{$configKey}");
        $account = ChartOfAccounts::where('account_code', $code)->first();

        if (!$account) {
            throw new \Exception("{$label} account (code {$code}) not found. Check config/sale_accounts.php and your Chart of Accounts.");
        }

        return $account;
    }

    private function salesRevenueAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('sales_revenue', 'Sales Revenue');
    }

    private function cogsAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('cogs', 'Cost of Goods Sold');
    }

    private function inventoryAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('inventory', 'Inventory / Stock in Hand');
    }

    // ─────────────────────────────────────────────────────────────
    // Stock helpers
    // ─────────────────────────────────────────────────────────────

    /** Server-side source of truth for available stock — never trust the frontend. */
    private function resolveAvailableStock(int $productId, ?int $variationId): float
    {
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            return $variation ? (float) $variation->stock_quantity : 0;
        }

        $product = Product::find($productId);
        if (!$product) return 0;

        // Prefer a real_time_stock accessor if the Product model defines one;
        // otherwise fall back to summing all variations' stock.
        if (isset($product->real_time_stock)) {
            return (float) $product->real_time_stock;
        }

        return (float) ProductVariation::where('product_id', $productId)->sum('stock_quantity');
    }

    private function adjustStock(int $productId, ?int $variationId, float $delta): void
    {
        // Positive delta = add back to stock, negative = deduct.
        if (!$variationId) {
            Log::warning('[SI] No variation_id on item — stock not adjusted (product-level stock only).', ['product_id' => $productId]);
            return;
        }

        $variation = ProductVariation::find($variationId);
        if (!$variation) {
            Log::warning('[SI] Variation not found for stock adjustment', ['variation_id' => $variationId]);
            return;
        }

        if ($delta >= 0) {
            $variation->increment('stock_quantity', $delta);
        } else {
            $variation->decrement('stock_quantity', abs($delta));
        }
    }

    /**
     * Unit cost for COGS — uses the last RECEIVED Purchase Invoice item's
     * landed cost for this variation/product (reuses the Purchase module's
     * costing rather than inventing a new methodology). Falls back to the
     * sale price itself (0 margin) only if no purchase history exists yet,
     * and logs a warning so it's visible in reporting.
     */
    private function resolveUnitCost(int $productId, ?int $variationId, float $fallback): float
    {
        $query = PurchaseInvoiceItem::whereNotNull('received_quantity')
            ->where('item_id', $productId)
            ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
            ->latest('updated_at');

        $lastReceived = $query->first();

        if ($lastReceived && (float) $lastReceived->received_quantity > 0) {
            return $lastReceived->landedUnitCost();
        }

        Log::warning('[SI] No purchase history found for costing — using sale price as fallback unit cost.', [
            'product_id' => $productId, 'variation_id' => $variationId,
        ]);

        return $fallback;
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();

        return view('sales.create', [
            'products'        => $products,
            'customers'       => $customers,
            'paymentAccounts' => $paymentAccounts,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // STORE
    //
    // DR Customer (AR)                CR Sales Revenue      (net_amount)
    // DR Payment Account (if any)     CR Customer (AR)      (amount_received)
    // DR COGS                         CR Inventory          (total cost)
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'date'                     => 'required|date',
            'account_id'               => 'required|exists:chart_of_accounts,id',
            'type'                     => 'required|in:cash,credit',
            'remarks'                  => 'nullable|string',
            'discount'                 => 'nullable|numeric|min:0',
            'payment_account_id'       => 'nullable|exists:chart_of_accounts,id',
            'amount_received'          => 'nullable|numeric|min:0',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variation_id'     => 'nullable|exists:product_variations,id',
            'items.*.sale_price'       => 'required|numeric|min:0',
            'items.*.quantity'         => 'required|numeric|min:0.01',
            'items.*.discount'         => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();

        try {
            // ── Server-side stock validation (never trust the frontend) ──
            foreach ($request->items as $itemData) {
                $available = $this->resolveAvailableStock((int) $itemData['product_id'], $itemData['variation_id'] ?? null);
                $requested = (float) $itemData['quantity'];
                if ($requested > $available) {
                    throw new \Exception("Insufficient stock for the selected item. Available: {$available}, Requested: {$requested}.");
                }
            }

            $last      = SaleInvoice::withTrashed()->orderByDesc('id')->first() ?? SaleInvoice::orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no ?? $last->id) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = SaleInvoice::create([
                'invoice_no' => $invoiceNo,
                'date'       => $request->date,
                'account_id' => $request->account_id,
                'type'       => $request->type,
                'remarks'    => $request->remarks,
                'discount'   => (float) ($request->discount ?? 0),
                'created_by' => auth()->id(),
            ]);

            $grossTotal = 0;
            $totalCost  = 0;

            foreach ($request->items as $itemData) {
                $qty       = (float) $itemData['quantity'];
                $price     = (float) $itemData['sale_price'];
                $discPct   = (float) ($itemData['discount'] ?? 0);
                $lineTotal = round(($price - ($price * $discPct / 100)) * $qty, 2);
                $grossTotal += $lineTotal;

                $unitCost = $this->resolveUnitCost((int) $itemData['product_id'], $itemData['variation_id'] ?? null, $price);
                $totalCost += $unitCost * $qty;

                $invoice->items()->create([
                    'product_id'   => $itemData['product_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'sale_price'   => $price,
                    'quantity'     => $qty,
                    'discount'     => $discPct,
                    'total'        => $lineTotal,
                    'unit_cost'    => $unitCost,
                ]);

                // Deduct stock immediately — Sales has no in-transit workflow.
                $this->adjustStock((int) $itemData['product_id'], $itemData['variation_id'] ?? null, -$qty);
            }

            $netAmount      = max(0, round($grossTotal - (float) ($request->discount ?? 0), 2));
            $amountReceived = (float) ($request->amount_received ?? 0);

            if ($amountReceived > $netAmount) {
                throw new \Exception('Amount received cannot exceed the invoice total.');
            }
            if ($amountReceived > 0 && !$request->payment_account_id) {
                throw new \Exception('A payment account is required when an amount has been received.');
            }

            $invoice->update([
                'net_amount'      => $netAmount,
                'amount_received' => $amountReceived,
            ]);

            // 1) Revenue — always booked in full against the customer's AR account.
            if ($netAmount > 0) {
                Voucher::create([
                    'date'         => $request->date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $request->account_id,
                    'ac_cr_sid'    => $this->salesRevenueAccount()->id,
                    'amount'       => $netAmount,
                    'reference'    => "SI-{$invoice->id}-REVENUE",
                    'remarks'      => "Sale Invoice #{$invoiceNo} — revenue recognized",
                ]);
            }

            // 2) Receipt — only if something was actually received now.
            if ($amountReceived > 0) {
                Voucher::create([
                    'date'         => $request->date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $request->payment_account_id,
                    'ac_cr_sid'    => $request->account_id,
                    'amount'       => $amountReceived,
                    'reference'    => "SI-{$invoice->id}-RECEIPT-1",
                    'remarks'      => "Sale Invoice #{$invoiceNo} — payment received",
                ]);
            }

            // 3) COGS
            if ($totalCost > 0) {
                Voucher::create([
                    'date'         => $request->date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $this->cogsAccount()->id,
                    'ac_cr_sid'    => $this->inventoryAccount()->id,
                    'amount'       => round($totalCost, 2),
                    'reference'    => "SI-{$invoice->id}-COGS",
                    'remarks'      => "Sale Invoice #{$invoiceNo} — cost of goods sold",
                ]);
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

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $invoice = SaleInvoice::with(['items.product', 'items.variation'])->findOrFail($id);

        $products = Product::with('variations')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('sale_accounts.customer_account_type'))
            ->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', config('sale_accounts.payment_account_types'))
            ->orderBy('name')->get();

        $amountReceived = (float) $invoice->amount_received;

        return view('sales.edit', [
            'invoice'         => $invoice,
            'products'        => $products,
            'customers'       => $customers,
            'paymentAccounts' => $paymentAccounts,
            'amountReceived'  => $amountReceived,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE
    //
    // Re-syncs items/stock/revenue/COGS to whatever the form now says.
    // 'amount_received' on THIS request is treated as a NEW incremental
    // payment (matching the edit UI's "Add New Payment" section) — it is
    // added on top of whatever was already received, never overwrites it.
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $request->validate([
            'date'                     => 'required|date',
            'account_id'               => 'required|exists:chart_of_accounts,id',
            'type'                     => 'required|in:cash,credit',
            'remarks'                  => 'nullable|string',
            'discount'                 => 'nullable|numeric|min:0',
            'payment_account_id'       => 'nullable|exists:chart_of_accounts,id',
            'amount_received'          => 'nullable|numeric|min:0', // incremental new payment
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variation_id'     => 'nullable|exists:product_variations,id',
            'items.*.sale_price'       => 'required|numeric|min:0',
            'items.*.quantity'         => 'required|numeric|min:0.01',
            'items.*.discount'         => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();

        try {
            $invoice = SaleInvoice::with('items')->lockForUpdate()->findOrFail($id);

            // ── Reverse old stock first ──
            foreach ($invoice->items as $oldItem) {
                $this->adjustStock($oldItem->product_id, $oldItem->variation_id, +$oldItem->quantity);
            }

            // ── Validate new stock requirements against post-reversal availability ──
            foreach ($request->items as $itemData) {
                $available = $this->resolveAvailableStock((int) $itemData['product_id'], $itemData['variation_id'] ?? null);
                $requested = (float) $itemData['quantity'];
                if ($requested > $available) {
                    throw new \Exception("Insufficient stock for the selected item. Available: {$available}, Requested: {$requested}.");
                }
            }

            $invoice->update([
                'date'       => $request->date,
                'account_id' => $request->account_id,
                'type'       => $request->type,
                'remarks'    => $request->remarks,
                'discount'   => (float) ($request->discount ?? 0),
            ]);

            $invoice->items()->delete();

            $grossTotal = 0;
            $totalCost  = 0;

            foreach ($request->items as $itemData) {
                $qty       = (float) $itemData['quantity'];
                $price     = (float) $itemData['sale_price'];
                $discPct   = (float) ($itemData['discount'] ?? 0);
                $lineTotal = round(($price - ($price * $discPct / 100)) * $qty, 2);
                $grossTotal += $lineTotal;

                $unitCost = $this->resolveUnitCost((int) $itemData['product_id'], $itemData['variation_id'] ?? null, $price);
                $totalCost += $unitCost * $qty;

                $invoice->items()->create([
                    'product_id'   => $itemData['product_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'sale_price'   => $price,
                    'quantity'     => $qty,
                    'discount'     => $discPct,
                    'total'        => $lineTotal,
                    'unit_cost'    => $unitCost,
                ]);

                $this->adjustStock((int) $itemData['product_id'], $itemData['variation_id'] ?? null, -$qty);
            }

            $netAmount    = max(0, round($grossTotal - (float) ($request->discount ?? 0), 2));
            $newPaymentNow = (float) ($request->amount_received ?? 0);
            $priorReceived = (float) $invoice->getOriginal('amount_received');

            if (($priorReceived + $newPaymentNow) > $netAmount) {
                throw new \Exception('Total amount received cannot exceed the revised invoice total.');
            }
            if ($newPaymentNow > 0 && !$request->payment_account_id) {
                throw new \Exception('A payment account is required to record a new payment.');
            }

            $invoice->update([
                'net_amount'      => $netAmount,
                'amount_received' => $priorReceived + $newPaymentNow,
            ]);

            // Re-sync REVENUE voucher to the (possibly changed) invoice total.
            if ($netAmount > 0) {
                Voucher::updateOrCreate(
                    ['reference' => "SI-{$invoice->id}-REVENUE", 'voucher_type' => 'journal'],
                    [
                        'date'      => $request->date,
                        'ac_dr_sid' => $request->account_id,
                        'ac_cr_sid' => $this->salesRevenueAccount()->id,
                        'amount'    => $netAmount,
                        'remarks'   => "Sale Invoice #{$invoice->invoice_no} — revenue (updated)",
                    ]
                );
            }

            // Re-sync COGS voucher.
            if ($totalCost > 0) {
                Voucher::updateOrCreate(
                    ['reference' => "SI-{$invoice->id}-COGS", 'voucher_type' => 'journal'],
                    [
                        'date'      => $request->date,
                        'ac_dr_sid' => $this->cogsAccount()->id,
                        'ac_cr_sid' => $this->inventoryAccount()->id,
                        'amount'    => round($totalCost, 2),
                        'remarks'   => "Sale Invoice #{$invoice->invoice_no} — COGS (updated)",
                    ]
                );
            }

            // New payment now = its own settlement voucher (does NOT touch revenue).
            if ($newPaymentNow > 0) {
                $receiptCount = Voucher::where('reference', 'like', "SI-{$invoice->id}-RECEIPT-%")->count();
                Voucher::create([
                    'date'         => $request->date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $request->payment_account_id,
                    'ac_cr_sid'    => $request->account_id,
                    'amount'       => $newPaymentNow,
                    'reference'    => "SI-{$invoice->id}-RECEIPT-" . ($receiptCount + 1),
                    'remarks'      => "Sale Invoice #{$invoice->invoice_no} — additional payment received",
                ]);
            }

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SI] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY — full reversal (stock + all vouchers), hard delete.
    // ─────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $invoice = SaleInvoice::with('items')->lockForUpdate()->findOrFail($id);

            foreach ($invoice->items as $item) {
                $this->adjustStock($item->product_id, $item->variation_id, +$item->quantity);
            }

            Voucher::where('reference', 'like', "SI-{$invoice->id}-%")->delete();

            $invoice->items()->delete();
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

    // ─────────────────────────────────────────────────────────────
    // PRINT (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SI-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

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
        <table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th>
                    <th width="30%">Item</th>
                    <th width="15%">Variation</th>
                    <th width="10%">Qty</th>
                    <th width="15%">Price</th>
                    <th width="10%">Disc %</th>
                    <th width="15%">Total</th>
                </tr>
            </thead>
            <tbody>';

        $gross = 0;
        foreach ($invoice->items as $index => $item) {
            $variationName = $item->variation->sku ?? '-';
            $lineTotal = $item->total ?? $item->lineTotal();
            $gross += $lineTotal;

            $html .= '
                <tr>
                    <td width="5%"  style="text-align:center;">' . ($index + 1) . '</td>
                    <td width="30%">' . e($item->product->name ?? '-') . '</td>
                    <td width="15%" style="text-align:center;">' . e($variationName) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($item->sale_price, 2) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->discount, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($lineTotal, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;">
                    <td colspan="6" style="text-align:right;">Gross Total</td>
                    <td style="text-align:right;">' . number_format($gross, 2) . '</td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align:right;">Discount</td>
                    <td style="text-align:right;">' . number_format($invoice->discount, 2) . '</td>
                </tr>
                <tr style="font-weight:bold;background-color:#fafafa;">
                    <td colspan="6" style="text-align:right;">Net Amount</td>
                    <td style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align:right;">Amount Received</td>
                    <td style="text-align:right;">' . number_format($invoice->amount_received, 2) . '</td>
                </tr>
                <tr style="font-weight:bold;color:#b30000;">
                    <td colspan="6" style="text-align:right;">Balance Due</td>
                    <td style="text-align:right;">' . number_format($invoice->remainingBalance(), 2) . '</td>
                </tr>
            </tbody>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        return $pdf->Output('SI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
