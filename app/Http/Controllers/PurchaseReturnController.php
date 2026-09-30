<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\ProductVariation;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseReturn::with(['purchaseInvoice', 'vendor']);

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('return_date', [$request->from_date, $request->to_date]);
        }

        $returns = $query->orderByDesc('return_date')->paginate(20);
        $vendors = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();

        return view('purchase_returns.index', compact('returns', 'vendors'));
    }

    // Starting point is always a specific Received Purchase Invoice —
    // a return only makes sense against stock that's actually here.
    public function create($purchaseInvoiceId)
    {
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])->findOrFail($purchaseInvoiceId);

        if (!$invoice->isReceived()) {
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('error', 'Only Received invoices can have items returned — nothing has physically arrived to return yet.');
        }

        // For each item, how much has already been returned against it in
        // prior return records, so the form can show/enforce the true
        // remaining returnable amount rather than the full received amount.
        $items = $invoice->items->map(function ($item) {
            $alreadyReturnedQty = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $item->id)->sum('quantity');
            $alreadyReturnedWt  = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $item->id)->sum('net_weight');

            $item->remaining_qty    = max((float) $item->received_packing_qty - $alreadyReturnedQty, 0);
            $item->remaining_weight = max((float) $item->received_net_weight - $alreadyReturnedWt, 0);

            return $item;
        })->filter(fn ($item) => $item->remaining_qty > 0);

        return view('purchase_returns.create', compact('invoice', 'items'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'purchase_invoice_id' => 'required|exists:purchase_invoices,id',
            'return_date'         => 'required|date',
            'reason'              => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.purchase_invoice_item_id' => 'required|exists:purchase_invoice_items,id',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.net_weight'  => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('vendor')->lockForUpdate()->findOrFail($request->purchase_invoice_id);

            if (!$invoice->isReceived()) {
                throw new \Exception('Only Received invoices can have items returned.');
            }

            $last = PurchaseReturn::withTrashed()->orderByDesc('id')->first();
            $returnNo = str_pad($last ? $last->id + 1 : 1, 6, '0', STR_PAD_LEFT);

            $return = PurchaseReturn::create([
                'purchase_invoice_id' => $invoice->id,
                'return_no'           => $returnNo,
                'return_date'         => $request->return_date,
                'vendor_id'           => $invoice->vendor_id,
                'reason'              => $request->reason,
                'created_by'          => auth()->id(),
            ]);

            $totalAmount = 0;
            $totalWeight = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\PurchaseInvoiceItem::findOrFail($itemInput['purchase_invoice_item_id']);

                // Re-check remaining returnable amount server-side — never
                // trust the client-side pre-fill alone.
                $alreadyReturnedQty = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $originalItem->id)->sum('quantity');
                $alreadyReturnedWt  = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $originalItem->id)->sum('net_weight');
                $remainingQty = (float) $originalItem->received_packing_qty - $alreadyReturnedQty;
                $remainingWt  = (float) $originalItem->received_net_weight - $alreadyReturnedWt;

                $qty = (float) $itemInput['quantity'];
                $wt  = (float) $itemInput['net_weight'];

                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }
                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }

                // Same per-kg rate as the original purchase — a return
                // doesn't renegotiate price, it reverses what was actually
                // booked.
                $rate   = (float) $originalItem->price;
                $amount = round($wt * $rate, 2);

                PurchaseReturnItem::create([
                    'purchase_return_id'       => $return->id,
                    'purchase_invoice_item_id' => $originalItem->id,
                    'item_id'                  => $originalItem->item_id,
                    'variation_id'             => $originalItem->variation_id,
                    'quantity'                 => $qty,
                    'net_weight'               => $wt,
                    'price'                    => $rate,
                    'amount'                   => $amount,
                ]);

                $totalAmount += $amount;
                $totalWeight += $wt;

                // Reverse the stock that Receive originally added — same
                // dual bag/weight tracking as everywhere else in this app.
                if ($originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $qty);
                        $variation->decrement('stock_weight', $wt);
                    } else {
                        Log::warning('[PurchaseReturn] Variation not found on return', ['variation_id' => $originalItem->variation_id]);
                    }
                }
            }

            $return->update([
                'total_amount' => $totalAmount,
                'total_weight' => $totalWeight,
            ]);

            // Reverse the original DR Inventory / CR Vendor booked at
            // Receive — the vendor owes us less (or we owe them less),
            // and our inventory asset shrinks by the returned value.
            $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

            $this->postVoucher(
                $request->return_date, $invoice->vendor, $inventoryAccount, $totalAmount,
                "PR-{$return->id}-RETURN",
                "Purchase Return #{$returnNo} against PI-{$invoice->invoice_no}"
            );

            DB::commit();

            return redirect()->route('purchase_returns.show', $return->id)
                ->with('success', 'Purchase Return recorded — stock and vendor balance updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $return = PurchaseReturn::with(['purchaseInvoice', 'vendor', 'items.item', 'items.variation'])->findOrFail($id);
        $vouchers = $return->vouchers();

        return view('purchase_returns.show', compact('return', 'vouchers'));
    }

    // Edit form shows every item from the original invoice (same as
    // create), but "remaining returnable" is calculated as if THIS
    // return's own quantities were given back to the pool first — so
    // the person can freely adjust this return's amounts up or down
    // within what's genuinely available, without being blocked by its
    // own prior values.
    public function edit($id)
    {
        $return = PurchaseReturn::with('items')->findOrFail($id);
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])->findOrFail($return->purchase_invoice_id);

        $thisReturnByItem = $return->items->keyBy('purchase_invoice_item_id');

        $items = $invoice->items->map(function ($item) use ($thisReturnByItem) {
            $alreadyReturnedQty = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $item->id)->sum('quantity');
            $alreadyReturnedWt  = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $item->id)->sum('net_weight');

            $thisReturnLine = $thisReturnByItem->get($item->id);
            $thisQty = $thisReturnLine ? (float) $thisReturnLine->quantity : 0;
            $thisWt  = $thisReturnLine ? (float) $thisReturnLine->net_weight : 0;

            // Add this return's own amounts back before computing what's
            // "remaining" — otherwise this return's existing quantities
            // would count against themselves.
            $item->remaining_qty    = max((float) $item->received_packing_qty - $alreadyReturnedQty + $thisQty, 0);
            $item->remaining_weight = max((float) $item->received_net_weight - $alreadyReturnedWt + $thisWt, 0);
            $item->current_qty      = $thisQty;
            $item->current_weight   = $thisWt;
            $item->is_in_return     = (bool) $thisReturnLine;

            return $item;
        })->filter(fn ($item) => $item->remaining_qty > 0 || $item->is_in_return);

        return view('purchase_returns.edit', compact('return', 'invoice', 'items'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'return_date' => 'required|date',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.purchase_invoice_item_id' => 'required|exists:purchase_invoice_items,id',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.net_weight' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $return = PurchaseReturn::with('items')->lockForUpdate()->findOrFail($id);
            $invoice = PurchaseInvoice::with('vendor')->findOrFail($return->purchase_invoice_id);

            // Reverse this return's OLD stock effect before validating and
            // applying the new amounts — same pattern Sale invoice's
            // update() uses.
            foreach ($return->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $variation = ProductVariation::find($oldItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $oldItem->quantity);
                        $variation->increment('stock_weight', $oldItem->net_weight);
                    }
                }
            }

            $oldItemIds = $return->items->pluck('purchase_invoice_item_id')->toArray();
            $return->items()->delete();
            Voucher::where('reference', 'like', "PR-{$return->id}-%")->delete();

            $return->update([
                'return_date' => $request->return_date,
                'reason'      => $request->reason,
            ]);

            $totalAmount = 0;
            $totalWeight = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\PurchaseInvoiceItem::findOrFail($itemInput['purchase_invoice_item_id']);

                // Remaining returnable EXCLUDING this same return (already
                // zeroed out above by deleting its old items first, so a
                // plain sum here is now correct without special-casing).
                $alreadyReturnedQty = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $originalItem->id)->sum('quantity');
                $alreadyReturnedWt  = (float) PurchaseReturnItem::where('purchase_invoice_item_id', $originalItem->id)->sum('net_weight');
                $remainingQty = (float) $originalItem->received_packing_qty - $alreadyReturnedQty;
                $remainingWt  = (float) $originalItem->received_net_weight - $alreadyReturnedWt;

                $qty = (float) $itemInput['quantity'];
                $wt  = (float) $itemInput['net_weight'];

                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }
                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }

                $rate   = (float) $originalItem->price;
                $amount = round($wt * $rate, 2);

                PurchaseReturnItem::create([
                    'purchase_return_id'       => $return->id,
                    'purchase_invoice_item_id' => $originalItem->id,
                    'item_id'                  => $originalItem->item_id,
                    'variation_id'             => $originalItem->variation_id,
                    'quantity'                 => $qty,
                    'net_weight'               => $wt,
                    'price'                    => $rate,
                    'amount'                   => $amount,
                ]);

                $totalAmount += $amount;
                $totalWeight += $wt;

                if ($originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $qty);
                        $variation->decrement('stock_weight', $wt);
                    }
                }
            }

            $return->update([
                'total_amount' => $totalAmount,
                'total_weight' => $totalWeight,
            ]);

            $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

            $this->postVoucher(
                $request->return_date, $invoice->vendor, $inventoryAccount, $totalAmount,
                "PR-{$return->id}-RETURN",
                "Purchase Return #{$return->return_no} against PI-{$invoice->invoice_no} (updated)"
            );

            DB::commit();

            return redirect()->route('purchase_returns.show', $return->id)
                ->with('success', 'Purchase Return updated — stock and vendor balance adjusted.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
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
        $return = PurchaseReturn::with(['purchaseInvoice', 'vendor', 'items.item', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Purchase Return #' . $return->return_no);
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
        $pdf->Cell(90, 7, '  PURCHASE RETURN', 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Return No</b></td><td width="5%">:</td><td width="55%">PR-' . $return->return_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($return->return_date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Against Invoice</b></td><td>:</td><td>PI-' . $return->purchaseInvoice->invoice_no . '</td></tr>
        </table>';
        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);

        // ── Vendor Details box ───────────────────────────────────────
        $boxY = 55;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(190, 7, '  Vendor Details', 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $vendorHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="15%"><b>Vendor</b></td><td width="3%">:</td><td width="82%">' . e($return->vendor->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(190, 10, 10, $boxY + 7, $vendorHtml, 1, 1);

        $pdf->SetY($boxY + 20);

        // ── Items table ─────────────────────────────────────────────
        $html = '
        <table border="1" cellpadding="3" style="font-size:9px;">
            <thead>
                <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                    <th width="37%">Description</th>
                    <th width="15%">Qty (bags)</th><th width="15%">Net Wt (kg)</th>
                    <th width="14%">Rate/kg</th><th width="19%">Amount</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($return->items as $index => $item) {
            $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
            $skuLabel = $item->variation?->sku ?? $item->item->sku ?? '-';
            $html .= '
                <tr style="background-color:' . $rowBg . ';">
                    <td width="37%">' . e($skuLabel) . '</td>
                    <td width="15%" style="text-align:right;">' . number_format($item->quantity, 2) . '</td>
                    <td width="15%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="14%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                    <td width="19%" style="text-align:right;">' . number_format($item->amount, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#F5EFDF;">
                    <td colspan="4" style="text-align:right;">Total Return Amount</td>
                    <td style="text-align:right;">' . number_format($return->total_amount, 2) . '</td>
                </tr>
            </tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Summary ────────────────────────────────────────────────
        $summaryHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="70%">Total Net Weight</td><td width="30%" style="text-align:right;">' . number_format($return->total_weight, 2) . ' kg</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;font-size:11px;">
                <td>TOTAL RETURN AMOUNT</td><td style="text-align:right;">' . number_format($return->total_amount, 2) . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Amount in Words ──────────────────────────────────────────
        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Rupees in Words:</b> ' . $this->numberToWords($return->total_amount) . ' Only.</td>
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

        return $pdf->Output('PR_' . $return->return_no . '.pdf', 'I');
    }

    // Full reversal — undo the return entirely (re-add stock, remove voucher).
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = PurchaseReturn::with('items')->lockForUpdate()->findOrFail($id);

            foreach ($return->items as $item) {
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $item->quantity);
                        $variation->increment('stock_weight', $item->net_weight);
                    }
                }
            }

            Voucher::where('reference', 'like', "PR-{$return->id}-%")->delete();
            $return->items()->delete();
            $return->delete();

            DB::commit();
            return redirect()->route('purchase_returns.index')->with('success', 'Purchase Return deleted — stock and vouchers reversed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Destroy error', ['message' => $e->getMessage()]);
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