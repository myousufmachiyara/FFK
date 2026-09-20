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

            $last = PurchaseReturn::orderByDesc('id')->first();
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

    public function print($id)
    {
        $return = PurchaseReturn::with(['purchaseInvoice', 'vendor', 'items.item', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Purchase Return #' . $return->return_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 15;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 10, 24);
            $nameX = 42;
        }
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetXY($nameX, 12);
        $pdf->Cell(195 - $nameX, 8, 'FAROOQ FULARA (KARACHI)', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'BI', 9);
        $pdf->SetXY($nameX, 21);
        $pdf->Cell(80, 5, 'Farooq Fulara   0320-2788117', 0, 0, 'L');
        $pdf->Cell(80, 5, 'Hamiz Farooq Fulara   0335-0023574', 0, 1, 'L');
        $pdf->SetLineWidth(0.4);
        $pdf->Line(15, 30, 195, 30);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(15, 34);
        $pdf->Cell(90, 5, 'Return #: ' . $return->return_no, 0, 0, 'L');
        $pdf->Cell(90, 5, 'Date: ' . Carbon::parse($return->return_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(105);
        $pdf->Cell(90, 5, 'Against: PI-' . $return->purchaseInvoice->invoice_no, 0, 1, 'R');
        $pdf->Ln(3);

        $vendorHtml = '<table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Vendor:</b></td><td width="60%">' . ($return->vendor->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '<table border="1" cellpadding="4" style="font-size:9px;">
            <thead><tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                <th width="30%">Item</th><th width="15%">Variation</th>
                <th width="15%">Qty (bags)</th><th width="15%">Net Wt (kg)</th>
                <th width="12%">Rate/kg</th><th width="13%">Amount</th>
            </tr></thead><tbody>';
        foreach ($return->items as $item) {
            $html .= '<tr>
                <td width="30%">' . e($item->item->name ?? '-') . '</td>
                <td width="15%">' . e($item->variation->sku ?? '-') . '</td>
                <td width="15%" style="text-align:right;">' . number_format($item->quantity, 2) . '</td>
                <td width="15%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                <td width="12%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td width="13%" style="text-align:right;">' . number_format($item->amount, 2) . '</td>
            </tr>';
        }
        $html .= '<tr style="font-weight:bold;background-color:#fafafa;">
            <td colspan="5" style="text-align:right;">Total</td>
            <td style="text-align:right;">' . number_format($return->total_amount, 2) . '</td>
        </tr></tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');

        if ($return->reason) {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Reason: ' . $return->reason, 0, 'L');
        }

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
            'date'       => $date,
            'ac_dr_sid'  => $drAccount->id,
            'ac_cr_sid'  => $crAccount->id,
            'amount'     => $amount,
            'reference'  => $reference,
            'remarks'    => $remarks,
        ]);
    }
}