<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ProductVariation;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::with(['saleInvoice', 'customer']);

        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('return_date', [$request->from_date, $request->to_date]);
        }

        $returns = $query->orderByDesc('return_date')->paginate(20);
        $customers = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();

        return view('sale_returns.index', compact('returns', 'customers'));
    }

    /**
     * Per-kg cost to reverse for a given original sale item — derived
     * from the ACTUAL SI-{id}-COGS voucher(s) already posted for that
     * invoice, not a freshly recomputed average. This is the only way
     * a return's COGS reversal guarantees it nets back to exactly zero
     * for the returned portion — the cost basis may have drifted since
     * the original sale (more purchases at different rates), but the
     * reversal must undo what was ACTUALLY booked, not today's rate.
     */
    private function resolveOriginalUnitCost(SaleInvoice $invoice): float
    {
        $totalCogsPosted = (float) Voucher::where('reference', 'like', "SI-{$invoice->id}-COGS%")
            ->whereNull('deleted_at')
            ->sum('amount');

        $totalWeight = (float) $invoice->total_weight;

        return $totalWeight > 0 ? round($totalCogsPosted / $totalWeight, 4) : 0;
    }

    public function create($saleInvoiceId)
    {
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation'])->findOrFail($saleInvoiceId);

        $unitCost = $this->resolveOriginalUnitCost($invoice);

        $items = $invoice->items->map(function ($item) use ($unitCost) {
            $alreadyReturnedQty = (float) SaleReturnItem::where('sale_invoice_item_id', $item->id)->sum('qty');
            $alreadyReturnedWt  = (float) SaleReturnItem::where('sale_invoice_item_id', $item->id)->sum('net_weight');

            $item->remaining_qty    = max((float) $item->quantity - $alreadyReturnedQty, 0);
            $item->remaining_weight = max((float) $item->net_weight - $alreadyReturnedWt, 0);
            $item->original_unit_cost = $unitCost;

            return $item;
        })->filter(fn ($item) => $item->remaining_qty > 0);

        return view('sale_returns.create', compact('invoice', 'items'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'sale_invoice_id' => 'required|exists:sale_invoices,id',
            'return_date'     => 'required|date',
            'reason'          => 'nullable|string',
            'items'           => 'required|array|min:1',
            'items.*.sale_invoice_item_id' => 'required|exists:sale_invoice_items,id',
            'items.*.qty'         => 'required|numeric|min:0.01',
            'items.*.net_weight'  => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = SaleInvoice::with('account')->lockForUpdate()->findOrFail($request->sale_invoice_id);
            $unitCost = $this->resolveOriginalUnitCost($invoice);

            $last = SaleReturn::withTrashed()->orderByDesc('id')->first();
            $returnNo = str_pad($last ? $last->id + 1 : 1, 6, '0', STR_PAD_LEFT);

            $return = SaleReturn::create([
                'sale_invoice_id' => $invoice->id,
                'return_no'       => $returnNo,
                'return_date'     => $request->return_date,
                'customer_id'     => $invoice->account_id,
                'reason'          => $request->reason,
                'created_by'      => auth()->id(),
            ]);

            $totalAmount = 0;
            $totalWeight = 0;
            $totalCogs   = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\SaleInvoiceItem::findOrFail($itemInput['sale_invoice_item_id']);

                $alreadyReturnedQty = (float) SaleReturnItem::where('sale_invoice_item_id', $originalItem->id)->sum('qty');
                $alreadyReturnedWt  = (float) SaleReturnItem::where('sale_invoice_item_id', $originalItem->id)->sum('net_weight');
                $remainingQty = (float) $originalItem->quantity - $alreadyReturnedQty;
                $remainingWt  = (float) $originalItem->net_weight - $alreadyReturnedWt;

                $qty = (float) $itemInput['qty'];
                $wt  = (float) $itemInput['net_weight'];

                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }
                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }

                $rate      = (float) $originalItem->sale_price;
                $amount    = round($wt * $rate, 2);
                $cogsAmount = round($wt * $unitCost, 2);

                SaleReturnItem::create([
                    'sale_return_id'       => $return->id,
                    'sale_invoice_item_id' => $originalItem->id,
                    'product_id'           => $originalItem->product_id,
                    'variation_id'         => $originalItem->variation_id,
                    'qty'                  => $qty,
                    'net_weight'           => $wt,
                    'price'                => $rate,
                    'amount'               => $amount,
                    'unit_cost'            => $unitCost,
                    'cogs_amount'          => $cogsAmount,
                ]);

                $totalAmount += $amount;
                $totalWeight += $wt;
                $totalCogs   += $cogsAmount;

                // Reverse the stock Sale originally deducted.
                if ($originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        $variation->increment('stock_weight', $wt);
                    } else {
                        Log::warning('[SaleReturn] Variation not found on return', ['variation_id' => $originalItem->variation_id]);
                    }
                }
            }

            $return->update([
                'total_amount' => $totalAmount,
                'total_weight' => $totalWeight,
                'total_cogs'   => $totalCogs,
            ]);

            $revenueAccount   = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Sales%')->first()
                ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
            $cogsAccount      = ChartOfAccounts::where('account_type', 'cogs')->firstOrFail();
            $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

            // Reverse the original DR Customer / CR Sales Revenue —
            // customer owes less, revenue shrinks.
            $this->postVoucher(
                $request->return_date, $revenueAccount, $invoice->account, $totalAmount,
                "SR-{$return->id}-REVENUE",
                "Sale Return #{$returnNo} against SI-{$invoice->invoice_no}"
            );

            // Reverse the original DR COGS / CR Inventory — goods are
            // physically back, so inventory value goes back up and the
            // cost previously recognized is un-recognized.
            if ($totalCogs > 0) {
                $this->postVoucher(
                    $request->return_date, $inventoryAccount, $cogsAccount, $totalCogs,
                    "SR-{$return->id}-COGS",
                    "Sale Return #{$returnNo} — COGS reversal against SI-{$invoice->invoice_no}"
                );
            }

            DB::commit();

            return redirect()->route('sale_returns.show', $return->id)
                ->with('success', 'Sale Return recorded — stock and customer balance updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleReturn] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $return = SaleReturn::with(['saleInvoice', 'customer', 'items.product', 'items.variation'])->findOrFail($id);
        $vouchers = $return->vouchers();

        return view('sale_returns.show', compact('return', 'vouchers'));
    }

    public function edit($id)
    {
        $return = SaleReturn::with('items')->findOrFail($id);
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation'])->findOrFail($return->sale_invoice_id);
        $unitCost = $this->resolveOriginalUnitCost($invoice);

        $thisReturnByItem = $return->items->keyBy('sale_invoice_item_id');

        $items = $invoice->items->map(function ($item) use ($thisReturnByItem, $unitCost) {
            $alreadyReturnedQty = (float) SaleReturnItem::where('sale_invoice_item_id', $item->id)->sum('qty');
            $alreadyReturnedWt  = (float) SaleReturnItem::where('sale_invoice_item_id', $item->id)->sum('net_weight');

            $thisLine = $thisReturnByItem->get($item->id);
            $thisQty = $thisLine ? (float) $thisLine->qty : 0;
            $thisWt  = $thisLine ? (float) $thisLine->net_weight : 0;

            $item->remaining_qty    = max((float) $item->quantity - $alreadyReturnedQty + $thisQty, 0);
            $item->remaining_weight = max((float) $item->net_weight - $alreadyReturnedWt + $thisWt, 0);
            $item->current_qty      = $thisQty;
            $item->current_weight   = $thisWt;
            $item->is_in_return     = (bool) $thisLine;
            $item->original_unit_cost = $unitCost;

            return $item;
        })->filter(fn ($item) => $item->remaining_qty > 0 || $item->is_in_return);

        return view('sale_returns.edit', compact('return', 'invoice', 'items'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'return_date' => 'required|date',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.sale_invoice_item_id' => 'required|exists:sale_invoice_items,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.net_weight' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $return  = SaleReturn::with('items')->lockForUpdate()->findOrFail($id);
            $invoice = SaleInvoice::with('account')->findOrFail($return->sale_invoice_id);
            $unitCost = $this->resolveOriginalUnitCost($invoice);

            // Reverse this return's OLD stock effect first.
            foreach ($return->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $variation = ProductVariation::find($oldItem->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $oldItem->qty);
                        $variation->decrement('stock_weight', $oldItem->net_weight);
                    }
                }
            }

            $return->items()->delete();
            Voucher::where('reference', 'like', "SR-{$return->id}-%")->delete();

            $return->update([
                'return_date' => $request->return_date,
                'reason'      => $request->reason,
            ]);

            $totalAmount = 0; $totalWeight = 0; $totalCogs = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\SaleInvoiceItem::findOrFail($itemInput['sale_invoice_item_id']);

                $alreadyReturnedQty = (float) SaleReturnItem::where('sale_invoice_item_id', $originalItem->id)->sum('qty');
                $alreadyReturnedWt  = (float) SaleReturnItem::where('sale_invoice_item_id', $originalItem->id)->sum('net_weight');
                $remainingQty = (float) $originalItem->quantity - $alreadyReturnedQty;
                $remainingWt  = (float) $originalItem->net_weight - $alreadyReturnedWt;

                $qty = (float) $itemInput['qty'];
                $wt  = (float) $itemInput['net_weight'];

                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }
                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }

                $rate       = (float) $originalItem->sale_price;
                $amount     = round($wt * $rate, 2);
                $cogsAmount = round($wt * $unitCost, 2);

                SaleReturnItem::create([
                    'sale_return_id'       => $return->id,
                    'sale_invoice_item_id' => $originalItem->id,
                    'product_id'           => $originalItem->product_id,
                    'variation_id'         => $originalItem->variation_id,
                    'qty'                  => $qty,
                    'net_weight'           => $wt,
                    'price'                => $rate,
                    'amount'               => $amount,
                    'unit_cost'            => $unitCost,
                    'cogs_amount'          => $cogsAmount,
                ]);

                $totalAmount += $amount; $totalWeight += $wt; $totalCogs += $cogsAmount;

                if ($originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        $variation->increment('stock_weight', $wt);
                    }
                }
            }

            $return->update(['total_amount' => $totalAmount, 'total_weight' => $totalWeight, 'total_cogs' => $totalCogs]);

            $revenueAccount   = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Sales%')->first()
                ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
            $cogsAccount      = ChartOfAccounts::where('account_type', 'cogs')->firstOrFail();
            $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

            $this->postVoucher(
                $request->return_date, $revenueAccount, $invoice->account, $totalAmount,
                "SR-{$return->id}-REVENUE",
                "Sale Return #{$return->return_no} against SI-{$invoice->invoice_no} (updated)"
            );

            if ($totalCogs > 0) {
                $this->postVoucher(
                    $request->return_date, $inventoryAccount, $cogsAccount, $totalCogs,
                    "SR-{$return->id}-COGS",
                    "Sale Return #{$return->return_no} — COGS reversal (updated)"
                );
            }

            DB::commit();
            return redirect()->route('sale_returns.show', $return->id)->with('success', 'Sale Return updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleReturn] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
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
        $return = SaleReturn::with(['saleInvoice', 'customer', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Sale Return #' . $return->return_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        // ── Header band (navy, full width) ─────────────────────────
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, 0, 210, 32, 'F');

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 12;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 5, 22);
            $nameX = 38;
        }

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetXY($nameX, 8);
        $pdf->Cell(120, 8, 'FAROOQ FULARA', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY($nameX, 17);
        $pdf->SetTextColor(201, 162, 75);
        $pdf->Cell(120, 6, '(KARACHI)', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(120, 8);
        $pdf->Cell(80, 5, 'Farooq Fulara: 0320-2788117', 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->Cell(80, 5, 'Hamiz Farooq Fulara: 0335-0023574', 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // ── Title bar: gold label + return info box ─────────────────
        $pdf->SetFillColor(201, 162, 75);
        $pdf->Rect(10, 38, 90, 12, 'F');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 40.5);
        $pdf->Cell(90, 7, '  SALE RETURN', 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Return No</b></td><td width="5%">:</td><td width="55%">SR-' . $return->return_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($return->return_date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Against Invoice</b></td><td>:</td><td>SI-' . $return->saleInvoice->invoice_no . '</td></tr>
        </table>';
        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);

        // ── Customer Details box ───────────────────────────────────
        $boxY = 55;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(190, 7, '  Customer Details', 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $custHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="15%"><b>Customer</b></td><td width="3%">:</td><td width="82%">' . e($return->customer->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(190, 10, 10, $boxY + 7, $custHtml, 1, 1);

        $pdf->SetY($boxY + 20);

        // ── Items table ─────────────────────────────────────────────
        $html = '
        <table border="1" cellpadding="3" style="font-size:9px;">
            <thead>
                <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                    <th width="20%">Item</th><th width="17%">Variation</th>
                    <th width="14%">Qty (bags)</th><th width="14%">Net Wt (kg)</th>
                    <th width="13%">Rate/kg</th><th width="22%">Amount</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($return->items as $index => $item) {
            $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
            $skuLabel = $item->variation?->sku ?? $item->product->sku ?? '-';
            $html .= '
                <tr style="background-color:' . $rowBg . ';">
                    <td width="20%">' . e($item->product->name ?? '-') . '</td>
                    <td width="17%">' . e($skuLabel) . '</td>
                    <td width="14%" style="text-align:right;">' . number_format($item->qty, 2) . '</td>
                    <td width="14%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="13%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                    <td width="22%" style="text-align:right;">' . number_format($item->amount, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#F5EFDF;">
                    <td colspan="5" style="text-align:right;">Total Return Amount</td>
                    <td style="text-align:right;">' . number_format($return->total_amount, 2) . '</td>
                </tr>
            </tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Summary ────────────────────────────────────────────────
        $summaryHtml = '<table width="95%" cellpadding="2" style="font-size:9px;" align="right">
            <tr><td width="70%">Total Net Weight</td><td width="30%" style="text-align:right;">' . number_format($return->total_weight, 2) . ' kg</td></tr>
            <tr><td>COGS Reversed</td><td style="text-align:right;">' . number_format($return->total_cogs, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;font-size:11px;">
                <td>TOTAL RETURN AMOUNT</td><td style="text-align:right;">' . number_format($return->total_amount, 2) . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Amount in Words ──────────────────────────────────────────
        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Rupees in Words:</b> ' . $this->numberToWords((float) $return->total_amount) . ' Only.</td>
            </tr>
        </table>';
        $pdf->writeHTML($wordsHtml, true, false, false, false, '');
        $pdf->Ln(2);

        if ($return->reason) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Reason: ' . $return->reason, 0, 'L');
        }

        // ── Signature ───────────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 20;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }

        $pdf->Line(140, $ySign, 195, $ySign);
        $pdf->SetXY(140, $ySign + 1);
        $pdf->Cell(55, 5, 'Authorized Signature', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(140);
        $pdf->Cell(55, 5, 'FAROOQ FULARA (KARACHI)', 0, 0, 'C');

        // ── Footer band ───────────────────────────────────────────────
        $footY = 282;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, $footY, 210, 15, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(10, $footY + 4);
        $pdf->Cell(190, 5, 'Farooq Fulara: 0320-2788117   |   Hamiz Farooq Fulara: 0335-0023574   |   Karachi, Pakistan', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        return $pdf->Output('SR_' . $return->return_no . '.pdf', 'I');
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = SaleReturn::with('items')->lockForUpdate()->findOrFail($id);

            foreach ($return->items as $item) {
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $item->qty);
                        $variation->decrement('stock_weight', $item->net_weight);
                    }
                }
            }

            Voucher::where('reference', 'like', "SR-{$return->id}-%")->delete();
            $return->items()->delete();
            $return->delete();

            DB::commit();
            return redirect()->route('sale_returns.index')->with('success', 'Sale Return deleted — stock and vouchers reversed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleReturn] Destroy error', ['message' => $e->getMessage()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    private function postVoucher($date, $drAccount, $crAccount, $amount, $reference, $remarks)
    {
        if ($amount <= 0) return;

        Voucher::create([
            'date'         => $date,
            'voucher_type' => 'journal',
            'ac_dr_sid'    => $drAccount->id,
            'ac_cr_sid'    => $crAccount->id,
            'amount'       => $amount,
            'reference'    => $reference,
            'remarks'      => $remarks,
        ]);
    }
}