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

            $last = SaleReturn::orderByDesc('id')->first();
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

    public function print($id)
    {
        $return = SaleReturn::with(['saleInvoice', 'customer', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Sale Return #' . $return->return_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 15;
        if (file_exists($logoPath)) { $pdf->Image($logoPath, 15, 10, 24); $nameX = 42; }
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetXY($nameX, 12);
        $pdf->Cell(195 - $nameX, 8, 'FAROOQ FULARA (KARACHI)', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'BI', 9);
        $pdf->SetXY($nameX, 21);
        $pdf->Cell(160, 5, 'Farooq Fulara: 0320-2788117   |   Hamiz Farooq Fulara: 0335-0023574', 0, 1, 'L');
        $pdf->SetLineWidth(0.4);
        $pdf->Line(15, 30, 195, 30);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(15, 34);
        $pdf->Cell(90, 5, 'Return #: ' . $return->return_no, 0, 0, 'L');
        $pdf->Cell(90, 5, 'Date: ' . Carbon::parse($return->return_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(105);
        $pdf->Cell(90, 5, 'Against: SI-' . $return->saleInvoice->invoice_no, 0, 1, 'R');
        $pdf->Ln(3);

        $custHtml = '<table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Customer:</b></td><td width="60%">' . ($return->customer->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->writeHTML($custHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '<table border="1" cellpadding="4" style="font-size:9px;">
            <thead><tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                <th width="30%">Item</th><th width="15%">Variation</th>
                <th width="13%">Qty (bags)</th><th width="13%">Net Wt (kg)</th>
                <th width="12%">Rate/kg</th><th width="17%">Amount</th>
            </tr></thead><tbody>';
        foreach ($return->items as $item) {
            $html .= '<tr>
                <td width="30%">' . e($item->product->name ?? '-') . '</td>
                <td width="15%">' . e($item->variation->sku ?? '-') . '</td>
                <td width="13%" style="text-align:right;">' . number_format($item->qty, 2) . '</td>
                <td width="13%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                <td width="12%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td width="17%" style="text-align:right;">' . number_format($item->amount, 2) . '</td>
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
            'date'      => $date,
            'ac_dr_sid' => $drAccount->id,
            'ac_cr_sid' => $crAccount->id,
            'amount'    => $amount,
            'reference' => $reference,
            'remarks'   => $remarks,
        ]);
    }
}