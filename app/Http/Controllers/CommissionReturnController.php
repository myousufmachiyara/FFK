<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CommissionInvoice;
use App\Models\CommissionReturn;
use App\Models\CommissionReturnItem;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CommissionReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = CommissionReturn::with(['commissionInvoice', 'vendor', 'customer']);

        if ($request->filled('vendor_id')) $query->where('vendor_id', $request->vendor_id);
        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('return_date', [$request->from_date, $request->to_date]);
        }

        $returns = $query->orderByDesc('return_date')->paginate(20);
        $vendors = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();

        return view('commission_returns.index', compact('returns', 'vendors', 'customers'));
    }

    // Commission never touches stock — a return here only makes sense
    // once goods were actually Delivered (that's when the commission and
    // residual vouchers this return needs to reverse were posted).
    public function create($commissionInvoiceId)
    {
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation'])->findOrFail($commissionInvoiceId);

        if (!$invoice->isDelivered()) {
            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('error', 'Only Delivered invoices can have items returned — nothing has been recognized as commission income yet.');
        }

        $items = $invoice->items->map(function ($item) {
            $alreadyReturnedWt = (float) CommissionReturnItem::where('commission_invoice_item_id', $item->id)->sum('net_weight');
            $alreadyReturnedQty = (float) CommissionReturnItem::where('commission_invoice_item_id', $item->id)->sum('qty');

            $item->remaining_qty    = max((float) $item->quantity - $alreadyReturnedQty, 0);
            $item->remaining_weight = max((float) $item->net_weight - $alreadyReturnedWt, 0);

            return $item;
        })->filter(fn ($item) => $item->remaining_weight > 0);

        return view('commission_returns.create', compact('invoice', 'items'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'commission_invoice_id' => 'required|exists:commission_invoices,id',
            'return_date'           => 'required|date',
            'reason'                => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.commission_invoice_item_id' => 'required|exists:commission_invoice_items,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.net_weight' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::with(['vendor', 'customer'])->lockForUpdate()->findOrFail($request->commission_invoice_id);

            if (!$invoice->isDelivered()) {
                throw new \Exception('Only Delivered invoices can have items returned.');
            }

            $last = CommissionReturn::orderByDesc('id')->first();
            $returnNo = str_pad($last ? $last->id + 1 : 1, 6, '0', STR_PAD_LEFT);

            $return = CommissionReturn::create([
                'commission_invoice_id' => $invoice->id,
                'return_no'             => $returnNo,
                'return_date'           => $request->return_date,
                'vendor_id'             => $invoice->vendor_id,
                'customer_id'           => $invoice->customer_id,
                'reason'                => $request->reason,
                'created_by'            => auth()->id(),
            ]);

            $totalWeight = 0; $totalSaleValue = 0; $totalVendorComm = 0; $totalCustComm = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\CommissionInvoiceItem::findOrFail($itemInput['commission_invoice_item_id']);

                $alreadyReturnedWt  = (float) CommissionReturnItem::where('commission_invoice_item_id', $originalItem->id)->sum('net_weight');
                $alreadyReturnedQty = (float) CommissionReturnItem::where('commission_invoice_item_id', $originalItem->id)->sum('qty');
                $remainingWt  = (float) $originalItem->net_weight - $alreadyReturnedWt;
                $remainingQty = (float) $originalItem->quantity - $alreadyReturnedQty;

                $qty = (float) $itemInput['qty'];
                $wt  = (float) $itemInput['net_weight'];

                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }
                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }

                // Proportional share of this item's original commission
                // figures, based on the fraction of weight being returned.
                $fraction = (float) $originalItem->net_weight > 0
                    ? $wt / (float) $originalItem->net_weight
                    : 0;

                $saleValue = round((float) $originalItem->sale_total * $fraction, 2);
                $vendorCommission = round((float) $originalItem->vendor_commission_amount * $fraction, 2);
                $customerCommission = round((float) $originalItem->customer_commission_amount * $fraction, 2);

                CommissionReturnItem::create([
                    'commission_return_id'       => $return->id,
                    'commission_invoice_item_id' => $originalItem->id,
                    'product_id'                 => $originalItem->product_id,
                    'variation_id'               => $originalItem->variation_id,
                    'qty'                        => $qty,
                    'net_weight'                 => $wt,
                    'sale_value'                 => $saleValue,
                    'vendor_commission'          => $vendorCommission,
                    'customer_commission'        => $customerCommission,
                ]);

                $totalWeight     += $wt;
                $totalSaleValue  += $saleValue;
                $totalVendorComm += $vendorCommission;
                $totalCustComm   += $customerCommission;
            }

            $return->update([
                'total_weight'               => $totalWeight,
                'total_sale_value'           => $totalSaleValue,
                'total_vendor_commission'    => $totalVendorComm,
                'total_customer_commission'  => $totalCustComm,
            ]);

            $commissionIncomeAccount = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Commission%')->first()
                ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
            $clearingAccount = ChartOfAccounts::where('account_type', 'clearing')->firstOrFail();

            // Reverse the original DR Vendor / CR Commission Income.
            if ($totalVendorComm > 0) {
                $this->postVoucher(
                    $request->return_date, $commissionIncomeAccount, $invoice->vendor, $totalVendorComm,
                    "CR-{$return->id}-VENDOR-COMMISSION",
                    "Commission Return #{$returnNo} — vendor commission reversal against CI-{$invoice->invoice_no}"
                );
            }

            // Reverse the original DR Customer / CR Commission Income.
            if ($totalCustComm > 0) {
                $this->postVoucher(
                    $request->return_date, $commissionIncomeAccount, $invoice->customer, $totalCustComm,
                    "CR-{$return->id}-CUSTOMER-COMMISSION",
                    "Commission Return #{$returnNo} — customer commission reversal against CI-{$invoice->invoice_no}"
                );
            }

            // Reverse the original RESIDUAL (DR Customer / CR Clearing) —
            // the goods-value portion of the customer's receivable.
            $residualReversal = round($totalSaleValue - $totalCustComm, 2);
            if ($residualReversal > 0) {
                $this->postVoucher(
                    $request->return_date, $clearingAccount, $invoice->customer, $residualReversal,
                    "CR-{$return->id}-RESIDUAL",
                    "Commission Return #{$returnNo} — goods value reversal against CI-{$invoice->invoice_no}"
                );
            }

            DB::commit();

            return redirect()->route('commission_returns.show', $return->id)
                ->with('success', 'Commission Return recorded — vendor and customer balances updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CommissionReturn] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $return = CommissionReturn::with(['commissionInvoice', 'vendor', 'customer', 'items.product', 'items.variation'])->findOrFail($id);
        $vouchers = $return->vouchers();

        return view('commission_returns.show', compact('return', 'vouchers'));
    }

    // Edit form shows every item from the original invoice, with
    // "remaining returnable" calculated as if THIS return's own current
    // weight was given back to the pool first — same technique as
    // Purchase/Sale Return's edit, so the proportional math never
    // blocks itself against its own prior values.
    public function edit($id)
    {
        $return = CommissionReturn::with('items')->findOrFail($id);
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation'])->findOrFail($return->commission_invoice_id);

        $thisReturnByItem = $return->items->keyBy('commission_invoice_item_id');

        $items = $invoice->items->map(function ($item) use ($thisReturnByItem) {
            $alreadyReturnedWt  = (float) CommissionReturnItem::where('commission_invoice_item_id', $item->id)->sum('net_weight');
            $alreadyReturnedQty = (float) CommissionReturnItem::where('commission_invoice_item_id', $item->id)->sum('qty');

            $thisLine = $thisReturnByItem->get($item->id);
            $thisQty = $thisLine ? (float) $thisLine->qty : 0;
            $thisWt  = $thisLine ? (float) $thisLine->net_weight : 0;

            $item->remaining_qty    = max((float) $item->quantity - $alreadyReturnedQty + $thisQty, 0);
            $item->remaining_weight = max((float) $item->net_weight - $alreadyReturnedWt + $thisWt, 0);
            $item->current_qty      = $thisQty;
            $item->current_weight   = $thisWt;
            $item->is_in_return     = (bool) $thisLine;

            return $item;
        })->filter(fn ($item) => $item->remaining_weight > 0 || $item->is_in_return);

        return view('commission_returns.edit', compact('return', 'invoice', 'items'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'return_date' => 'required|date',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.commission_invoice_item_id' => 'required|exists:commission_invoice_items,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.net_weight' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $return  = CommissionReturn::with('items')->lockForUpdate()->findOrFail($id);
            $invoice = CommissionInvoice::with(['vendor', 'customer'])->findOrFail($return->commission_invoice_id);

            if (!$invoice->isDelivered()) {
                throw new \Exception('This invoice is no longer Delivered — cannot adjust a return against it.');
            }

            // Old vouchers and items are cleared entirely — the store-like
            // block below rebuilds both from scratch off the NEW inputs,
            // exactly like Purchase/Sale Return's update() does.
            Voucher::where('reference', 'like', "CR-{$return->id}-%")->delete();
            $return->items()->delete();

            $return->update([
                'return_date' => $request->return_date,
                'reason'      => $request->reason,
            ]);

            $totalWeight = 0; $totalSaleValue = 0; $totalVendorComm = 0; $totalCustComm = 0;

            foreach ($request->items as $itemInput) {
                $originalItem = \App\Models\CommissionInvoiceItem::findOrFail($itemInput['commission_invoice_item_id']);

                $alreadyReturnedWt  = (float) CommissionReturnItem::where('commission_invoice_item_id', $originalItem->id)->sum('net_weight');
                $alreadyReturnedQty = (float) CommissionReturnItem::where('commission_invoice_item_id', $originalItem->id)->sum('qty');
                $remainingWt  = (float) $originalItem->net_weight - $alreadyReturnedWt;
                $remainingQty = (float) $originalItem->quantity - $alreadyReturnedQty;

                $qty = (float) $itemInput['qty'];
                $wt  = (float) $itemInput['net_weight'];

                if ($wt > $remainingWt + 0.001) {
                    throw new \Exception("Cannot return {$wt} kg for item #{$originalItem->id} — only {$remainingWt} kg remain returnable.");
                }
                if ($qty > $remainingQty + 0.001) {
                    throw new \Exception("Cannot return {$qty} bags for item #{$originalItem->id} — only {$remainingQty} bags remain returnable.");
                }

                // Proportional share, always computed against the
                // ORIGINAL item's totals — never against a prior return's
                // amounts, so editing never compounds rounding drift.
                $fraction = (float) $originalItem->net_weight > 0
                    ? $wt / (float) $originalItem->net_weight
                    : 0;

                $saleValue = round((float) $originalItem->sale_total * $fraction, 2);
                $vendorCommission = round((float) $originalItem->vendor_commission_amount * $fraction, 2);
                $customerCommission = round((float) $originalItem->customer_commission_amount * $fraction, 2);

                CommissionReturnItem::create([
                    'commission_return_id'       => $return->id,
                    'commission_invoice_item_id' => $originalItem->id,
                    'product_id'                 => $originalItem->product_id,
                    'variation_id'               => $originalItem->variation_id,
                    'qty'                        => $qty,
                    'net_weight'                 => $wt,
                    'sale_value'                 => $saleValue,
                    'vendor_commission'          => $vendorCommission,
                    'customer_commission'        => $customerCommission,
                ]);

                $totalWeight     += $wt;
                $totalSaleValue  += $saleValue;
                $totalVendorComm += $vendorCommission;
                $totalCustComm   += $customerCommission;
            }

            $return->update([
                'total_weight'               => $totalWeight,
                'total_sale_value'           => $totalSaleValue,
                'total_vendor_commission'    => $totalVendorComm,
                'total_customer_commission'  => $totalCustComm,
            ]);

            $commissionIncomeAccount = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Commission%')->first()
                ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
            $clearingAccount = ChartOfAccounts::where('account_type', 'clearing')->firstOrFail();

            if ($totalVendorComm > 0) {
                $this->postVoucher(
                    $request->return_date, $commissionIncomeAccount, $invoice->vendor, $totalVendorComm,
                    "CR-{$return->id}-VENDOR-COMMISSION",
                    "Commission Return #{$return->return_no} — vendor commission reversal against CI-{$invoice->invoice_no} (updated)"
                );
            }

            if ($totalCustComm > 0) {
                $this->postVoucher(
                    $request->return_date, $commissionIncomeAccount, $invoice->customer, $totalCustComm,
                    "CR-{$return->id}-CUSTOMER-COMMISSION",
                    "Commission Return #{$return->return_no} — customer commission reversal against CI-{$invoice->invoice_no} (updated)"
                );
            }

            $residualReversal = round($totalSaleValue - $totalCustComm, 2);
            if ($residualReversal > 0) {
                $this->postVoucher(
                    $request->return_date, $clearingAccount, $invoice->customer, $residualReversal,
                    "CR-{$return->id}-RESIDUAL",
                    "Commission Return #{$return->return_no} — goods value reversal against CI-{$invoice->invoice_no} (updated)"
                );
            }

            DB::commit();
            return redirect()->route('commission_returns.show', $return->id)->with('success', 'Commission Return updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CommissionReturn] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function print($id)
    {
        $return = CommissionReturn::with(['commissionInvoice', 'vendor', 'customer', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Commission Return #' . $return->return_no);
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
        $pdf->Cell(90, 5, 'Against: CI-' . $return->commissionInvoice->invoice_no, 0, 1, 'R');
        $pdf->Ln(3);

        $partiesHtml = '<table width="100%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="50%"><b>Vendor:</b> ' . ($return->vendor->name ?? 'N/A') . '</td>
                <td width="50%"><b>Customer:</b> ' . ($return->customer->name ?? 'N/A') . '</td></tr>
        </table>';
        $pdf->writeHTML($partiesHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '<table border="1" cellpadding="4" style="font-size:9px;">
            <thead><tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                <th width="24%">Item</th><th width="12%">Variation</th>
                <th width="10%">Qty</th><th width="10%">Net Wt</th>
                <th width="14%">Sale Value</th><th width="15%">Vendor Comm</th><th width="15%">Cust Comm</th>
            </tr></thead><tbody>';
        foreach ($return->items as $item) {
            $html .= '<tr>
                <td width="24%">' . e($item->product->name ?? '-') . '</td>
                <td width="12%">' . e($item->variation->sku ?? '-') . '</td>
                <td width="10%" style="text-align:right;">' . number_format($item->qty, 2) . '</td>
                <td width="10%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                <td width="14%" style="text-align:right;">' . number_format($item->sale_value, 2) . '</td>
                <td width="15%" style="text-align:right;">' . number_format($item->vendor_commission, 2) . '</td>
                <td width="15%" style="text-align:right;">' . number_format($item->customer_commission, 2) . '</td>
            </tr>';
        }
        $html .= '<tr style="font-weight:bold;background-color:#fafafa;">
            <td colspan="4" style="text-align:right;">Total</td>
            <td style="text-align:right;">' . number_format($return->total_sale_value, 2) . '</td>
            <td style="text-align:right;">' . number_format($return->total_vendor_commission, 2) . '</td>
            <td style="text-align:right;">' . number_format($return->total_customer_commission, 2) . '</td>
        </tr></tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');

        if ($return->reason) {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Reason: ' . $return->reason, 0, 'L');
        }

        return $pdf->Output('CR_' . $return->return_no . '.pdf', 'I');
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = CommissionReturn::with('items')->lockForUpdate()->findOrFail($id);

            Voucher::where('reference', 'like', "CR-{$return->id}-%")->delete();
            $return->items()->delete();
            $return->delete();

            DB::commit();
            return redirect()->route('commission_returns.index')->with('success', 'Commission Return deleted — vouchers reversed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CommissionReturn] Destroy error', ['message' => $e->getMessage()]);
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