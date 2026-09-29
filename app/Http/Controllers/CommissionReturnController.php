<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CommissionInvoice;
use App\Models\CommissionReturn;
use App\Models\CommissionReturnItem;
use App\Models\ChartOfAccounts;
use App\Models\ProductVariation;
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
            'return_type'           => 'required|in:vendor,stock_in',
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

            $last = CommissionReturn::withTrashed()->orderByDesc('id')->first();
            $returnNo = str_pad($last ? $last->id + 1 : 1, 6, '0', STR_PAD_LEFT);

            $return = CommissionReturn::create([
                'commission_invoice_id' => $invoice->id,
                'return_no'             => $returnNo,
                'return_date'           => $request->return_date,
                'return_type'           => $request->return_type,
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

                // Stock-in scenario ONLY: goods land in FFK's own stock,
                // so increment it exactly like a real receipt would.
                // Commission normally never touches stock at all — this
                // is the one deliberate exception.
                if ($request->return_type === 'stock_in' && $originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        $variation->increment('stock_weight', $wt);
                    } else {
                        Log::warning('[CommissionReturn] Variation not found for stock-in', ['variation_id' => $originalItem->variation_id]);
                    }
                }
            }

            $return->update([
                'total_weight'               => $totalWeight,
                'total_sale_value'           => $totalSaleValue,
                'total_vendor_commission'    => $totalVendorComm,
                'total_customer_commission'  => $totalCustComm,
            ]);

            $residualReversal = round($totalSaleValue - $totalCustComm, 2);

            if ($request->return_type === 'vendor') {
                // ── VENDOR SCENARIO — everything reverses, exactly as before. ──
                $commissionIncomeAccount = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Commission%')->first()
                    ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
                $clearingAccount = ChartOfAccounts::where('account_type', 'clearing')->firstOrFail();

                // Reverse the original DR Vendor / CR Commission Income.
                if ($totalVendorComm > 0) {
                    $this->postVoucher(
                        $request->return_date, $commissionIncomeAccount, $invoice->vendor, $totalVendorComm,
                        "CR-{$return->id}-VENDOR-COMMISSION",
                        "Commission Return #{$returnNo} (to Vendor) — vendor commission reversal against CI-{$invoice->invoice_no}"
                    );
                }

                // Reverse the original DR Customer / CR Commission Income.
                if ($totalCustComm > 0) {
                    $this->postVoucher(
                        $request->return_date, $commissionIncomeAccount, $invoice->customer, $totalCustComm,
                        "CR-{$return->id}-CUSTOMER-COMMISSION",
                        "Commission Return #{$returnNo} (to Vendor) — customer commission reversal against CI-{$invoice->invoice_no}"
                    );
                }

                // Reverse the RESIDUAL (goods-value portion of customer's receivable).
                if ($residualReversal > 0) {
                    $this->postVoucher(
                        $request->return_date, $clearingAccount, $invoice->customer, $residualReversal,
                        "CR-{$return->id}-RESIDUAL",
                        "Commission Return #{$returnNo} (to Vendor) — goods value reversal against CI-{$invoice->invoice_no}"
                    );
                }

            } else {
                // ── STOCK-IN SCENARIO — only the customer's receivable
                // moves. Vendor Payable and Commission Income (both sides)
                // are deliberately left untouched — the vendor still
                // delivered and still earned their commission; only the
                // customer's portion of the deal fell through. The
                // residual value becomes FFK's own inventory instead of
                // going back to the vendor.
                $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

                if ($residualReversal > 0) {
                    $this->postVoucher(
                        $request->return_date, $inventoryAccount, $invoice->customer, $residualReversal,
                        "CR-{$return->id}-STOCKIN",
                        "Commission Return #{$returnNo} (Stock In) — goods retained by FFK against CI-{$invoice->invoice_no}"
                    );
                }
            }

            DB::commit();

            return redirect()->route('commission_returns.show', $return->id)
                ->with('success', 'Commission Return recorded.');

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
            'return_type' => 'required|in:vendor,stock_in',
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

            // Reverse this return's OLD stock effect first, if it was a
            // stock-in return — mirrors Purchase/Sale Return's update().
            if ($return->isStockInReturn()) {
                foreach ($return->items as $oldItem) {
                    if ($oldItem->variation_id) {
                        $variation = ProductVariation::find($oldItem->variation_id);
                        if ($variation) {
                            $variation->decrement('stock_quantity', $oldItem->qty);
                            $variation->decrement('stock_weight', $oldItem->net_weight);
                        }
                    }
                }
            }

            // Old vouchers and items are cleared entirely — the store-like
            // block below rebuilds both from scratch off the NEW inputs,
            // exactly like Purchase/Sale Return's update() does.
            Voucher::where('reference', 'like', "CR-{$return->id}-%")->delete();
            $return->items()->delete();

            $return->update([
                'return_date' => $request->return_date,
                'return_type' => $request->return_type,
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

                if ($request->return_type === 'stock_in' && $originalItem->variation_id) {
                    $variation = ProductVariation::find($originalItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        $variation->increment('stock_weight', $wt);
                    }
                }
            }

            $return->update([
                'total_weight'               => $totalWeight,
                'total_sale_value'           => $totalSaleValue,
                'total_vendor_commission'    => $totalVendorComm,
                'total_customer_commission'  => $totalCustComm,
            ]);

            $residualReversal = round($totalSaleValue - $totalCustComm, 2);

            if ($request->return_type === 'vendor') {
                $commissionIncomeAccount = ChartOfAccounts::where('account_type', 'revenue')->where('name', 'like', '%Commission%')->first()
                    ?? ChartOfAccounts::where('account_type', 'revenue')->firstOrFail();
                $clearingAccount = ChartOfAccounts::where('account_type', 'clearing')->firstOrFail();

                if ($totalVendorComm > 0) {
                    $this->postVoucher(
                        $request->return_date, $commissionIncomeAccount, $invoice->vendor, $totalVendorComm,
                        "CR-{$return->id}-VENDOR-COMMISSION",
                        "Commission Return #{$return->return_no} (to Vendor) — vendor commission reversal against CI-{$invoice->invoice_no} (updated)"
                    );
                }

                if ($totalCustComm > 0) {
                    $this->postVoucher(
                        $request->return_date, $commissionIncomeAccount, $invoice->customer, $totalCustComm,
                        "CR-{$return->id}-CUSTOMER-COMMISSION",
                        "Commission Return #{$return->return_no} (to Vendor) — customer commission reversal against CI-{$invoice->invoice_no} (updated)"
                    );
                }

                if ($residualReversal > 0) {
                    $this->postVoucher(
                        $request->return_date, $clearingAccount, $invoice->customer, $residualReversal,
                        "CR-{$return->id}-RESIDUAL",
                        "Commission Return #{$return->return_no} (to Vendor) — goods value reversal against CI-{$invoice->invoice_no} (updated)"
                    );
                }

            } else {
                $inventoryAccount = ChartOfAccounts::where('account_type', 'inventory')->firstOrFail();

                if ($residualReversal > 0) {
                    $this->postVoucher(
                        $request->return_date, $inventoryAccount, $invoice->customer, $residualReversal,
                        "CR-{$return->id}-STOCKIN",
                        "Commission Return #{$return->return_no} (Stock In) — goods retained by FFK against CI-{$invoice->invoice_no} (updated)"
                    );
                }
            }

            DB::commit();
            return redirect()->route('commission_returns.show', $return->id)->with('success', 'Commission Return updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CommissionReturn] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
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
        $return = CommissionReturn::with(['commissionInvoice', 'vendor', 'customer', 'items.product', 'items.variation'])->findOrFail($id);
        $isStockIn = $return->isStockInReturn();

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Commission Return #' . $return->return_no);
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
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 40.5);
        $pdf->Cell(90, 7, '  COMMISSION RETURN', 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Return No</b></td><td width="5%">:</td><td width="55%">CR-' . $return->return_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($return->return_date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Against Invoice</b></td><td>:</td><td>CI-' . $return->commissionInvoice->invoice_no . '</td></tr>
            <tr><td><b>Return Type</b></td><td>:</td><td>' . e($return->returnTypeLabel()) . '</td></tr>
        </table>';
        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);

        // ── Vendor | Customer details box ────────────────────────────
        $boxY = 60;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(90, 7, '  Vendor Details', 1, 0, 'L', true);
        $pdf->SetXY(105, $boxY);
        $pdf->Cell(95, 7, '  Customer Details', 1, 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $vendorHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Vendor</b></td><td width="5%">:</td><td width="65%">' . e($return->vendor->name ?? 'N/A') . '</td></tr>
        </table>';
        $custHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Customer</b></td><td width="5%">:</td><td width="65%">' . e($return->customer->name ?? 'N/A') . '</td></tr>
        </table>';

        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(90, 10, 10, $boxY + 7, $vendorHtml, 1, 0);
        $pdf->SetXY(105, $boxY + 7);
        $pdf->writeHTMLCell(95, 10, 105, $boxY + 7, $custHtml, 1, 1);

        $pdf->SetY($boxY + 20);

        // ── Items table — columns adapt to return type ────────────────
        if ($isStockIn) {
            // Stock-in: commission columns are irrelevant (nothing
            // reversed on that side) — show only what actually changed.
            $html = '
            <table border="1" cellpadding="3" style="font-size:9px;">
                <thead>
                    <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                        <th width="26%">Item</th><th width="18%">Variation</th>
                        <th width="14%">Qty (bags)</th><th width="14%">Net Wt (kg)</th>
                        <th width="28%">Goods Value</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($return->items as $index => $item) {
                $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
                $skuLabel = $item->variation?->sku ?? $item->product->sku ?? '-';
                $html .= '
                    <tr style="background-color:' . $rowBg . ';">
                        <td width="26%">' . e($item->product->name ?? '-') . '</td>
                        <td width="18%">' . e($skuLabel) . '</td>
                        <td width="14%" style="text-align:right;">' . number_format($item->qty, 2) . '</td>
                        <td width="14%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                        <td width="28%" style="text-align:right;">' . number_format($item->sale_value - $item->customer_commission, 2) . '</td>
                    </tr>';
            }

            $residual = round((float) $return->total_sale_value - (float) $return->total_customer_commission, 2);
            $html .= '
                    <tr style="font-weight:bold;background-color:#F5EFDF;">
                        <td colspan="4" style="text-align:right;">Total Goods Value (to FFK Stock)</td>
                        <td style="text-align:right;">' . number_format($residual, 2) . '</td>
                    </tr>
                </tbody></table>';

        } else {
            $html = '
            <table border="1" cellpadding="3" style="font-size:8px;">
                <thead>
                    <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                        <th width="20%">Item</th><th width="12%">Variation</th>
                        <th width="9%">Qty</th><th width="9%">Net Wt</th>
                        <th width="14%">Sale Value</th><th width="18%">Vendor Comm</th><th width="18%">Cust Comm</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($return->items as $index => $item) {
                $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
                $skuLabel = $item->variation?->sku ?? $item->product->sku ?? '-';
                $html .= '
                    <tr style="background-color:' . $rowBg . ';">
                        <td width="20%">' . e($item->product->name ?? '-') . '</td>
                        <td width="12%">' . e($skuLabel) . '</td>
                        <td width="9%" style="text-align:right;">' . number_format($item->qty, 2) . '</td>
                        <td width="9%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                        <td width="14%" style="text-align:right;">' . number_format($item->sale_value, 2) . '</td>
                        <td width="18%" style="text-align:right;">' . number_format($item->vendor_commission, 2) . '</td>
                        <td width="18%" style="text-align:right;">' . number_format($item->customer_commission, 2) . '</td>
                    </tr>';
            }

            $html .= '
                    <tr style="font-weight:bold;background-color:#F5EFDF;">
                        <td colspan="4" style="text-align:right;">Total</td>
                        <td style="text-align:right;">' . number_format($return->total_sale_value, 2) . '</td>
                        <td style="text-align:right;">' . number_format($return->total_vendor_commission, 2) . '</td>
                        <td style="text-align:right;">' . number_format($return->total_customer_commission, 2) . '</td>
                    </tr>
                </tbody></table>';
        }

        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Summary ────────────────────────────────────────────────
        if ($isStockIn) {
            $residual = round((float) $return->total_sale_value - (float) $return->total_customer_commission, 2);
            $summaryHtml = '<table width="95%" cellpadding="2" style="font-size:9px;" align="right">
                <tr><td width="70%">Customer Receivable Reduced By</td><td width="30%" style="text-align:right;">' . number_format($residual, 2) . '</td></tr>
                <tr><td>Vendor Payable</td><td style="text-align:right;">No Change</td></tr>
                <tr><td>Commission Income</td><td style="text-align:right;">No Change</td></tr>
                <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;font-size:11px;">
                    <td>GOODS RECEIVED INTO FFK STOCK</td><td style="text-align:right;">' . number_format($residual, 2) . '</td>
                </tr>
            </table>';
        } else {
            $residual = round((float) $return->total_sale_value - (float) $return->total_customer_commission, 2);
            $summaryHtml = '<table width="95%" cellpadding="2" style="font-size:9px;" align="right">
                <tr><td width="70%">Vendor Commission Reversed</td><td width="30%" style="text-align:right;">' . number_format($return->total_vendor_commission, 2) . '</td></tr>
                <tr><td>Customer Commission Reversed</td><td style="text-align:right;">' . number_format($return->total_customer_commission, 2) . '</td></tr>
                <tr><td>Goods Value Reversed</td><td style="text-align:right;">' . number_format($residual, 2) . '</td></tr>
                <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;font-size:11px;">
                    <td>TOTAL CUSTOMER RECEIVABLE REDUCED</td><td style="text-align:right;">' . number_format($return->total_sale_value, 2) . '</td>
                </tr>
            </table>';
        }
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Amount in Words ──────────────────────────────────────────
        $wordsAmount = $isStockIn
            ? round((float) $return->total_sale_value - (float) $return->total_customer_commission, 2)
            : (float) $return->total_sale_value;

        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Rupees in Words:</b> ' . $this->numberToWords($wordsAmount) . ' Only.</td>
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

        return $pdf->Output('CR_' . $return->return_no . '.pdf', 'I');
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = CommissionReturn::with('items')->lockForUpdate()->findOrFail($id);

            // Reverse the stock this return added, if it was a stock-in
            // return — Commission normally never touches stock, so this
            // undo step only applies to that one scenario.
            if ($return->isStockInReturn()) {
                foreach ($return->items as $item) {
                    if ($item->variation_id) {
                        $variation = ProductVariation::find($item->variation_id);
                        if ($variation) {
                            $variation->decrement('stock_quantity', $item->qty);
                            $variation->decrement('stock_weight', $item->net_weight);
                        }
                    }
                }
            }

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