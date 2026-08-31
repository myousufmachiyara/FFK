<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; // at the top of the controller

class VoucherController extends Controller
{
    /**
     * Display all vouchers of a specific type.
     */
    public function index($type)
    {
        $vouchers = Voucher::with(['debitAccount', 'creditAccount'])
            ->where('voucher_type', $type)
            ->get();

        $accounts = ChartOfAccounts::all();

        return view('vouchers.index', [
            'vouchers' => $vouchers,
            'accounts' => $accounts,
            'type' => $type,
        ]);
    }

    /**
     * Show form to create a voucher of a specific type.
     */
    public function create($type)
    {
        $accounts = ChartOfAccounts::all();
        return view('vouchers.create', compact('accounts', 'type'));
    }

    /**
     * Show a single voucher.
     */
    public function show($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);
        return response()->json($voucher);
    }

    /**
     * Show form to edit a voucher.
     */
    public function edit($type, $id)
    {
        $voucher = Voucher::findOrFail($id);
        $accounts = ChartOfAccounts::all();
        return view('vouchers.edit', compact('voucher', 'accounts', 'type'));
    }

    public function store(Request $request, $type)
    {
        try {
            $data = $request->validate([
                'date' => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount' => 'required|numeric|min:1',
                'remarks' => 'nullable|string',
                'att.*' => 'nullable|file|max:2048',
            ]);

            $attachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            Voucher::create([
                'voucher_type' => $type,
                'date' => $data['date'],
                'ac_dr_sid' => $data['ac_dr_sid'],
                'ac_cr_sid' => $data['ac_cr_sid'],
                'amount' => $data['amount'],
                'remarks' => $data['remarks'],
                'attachments' => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher added successfully!');

        } catch (\Throwable $e) {
            Log::error("Error storing {$type} voucher: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return back()->with('error', 'Something went wrong while adding the voucher. Check logs.');
        }
    }

    public function update(Request $request, $type, $id)
    {
        try {
            $data = $request->validate([
                'date' => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount' => 'required|numeric|min:1',
                'remarks' => 'nullable|string',
                'att.*' => 'nullable|file|max:2048',
            ]);

            $voucher = Voucher::findOrFail($id);
            $attachments = $voucher->attachments ?? [];

            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            $voucher->update([
                'date' => $data['date'],
                'ac_dr_sid' => $data['ac_dr_sid'],
                'ac_cr_sid' => $data['ac_cr_sid'],
                'amount' => $data['amount'],
                'remarks' => $data['remarks'],
                'attachments' => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher updated successfully!');

        } catch (\Throwable $e) {
            Log::error("Error updating {$type} voucher ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return back()->with('error', 'Something went wrong while updating the voucher. Check logs.');
        }
    }

    public function destroy($type, $id)
    {
        try {
            $voucher = Voucher::findOrFail($id);

            if (!empty($voucher->attachments)) {
                foreach ($voucher->attachments as $file) {
                    if (Storage::disk('public')->exists($file)) {
                        Storage::disk('public')->delete($file);
                    }
                }
            }

            $voucher->delete();

            return back()->with('success', ucfirst($type) . ' voucher deleted successfully.');

        } catch (\Throwable $e) {
            Log::error("Error deleting {$type} voucher ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Something went wrong while deleting the voucher. Check logs.');
        }
    }

    /**
     * Print a voucher as PDF.
     */
    public function print($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Your App');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle(ucfirst($type) . ' Voucher #' . $voucher->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // --- Company Header ---
        $logoPath = public_path('assets/img/logo.png');

        // Logo (Top Left)
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        // Purchase INVOICE (Top Right)
        $pdf->SetFont('helvetica', 'B', 14);

        // Page width = 210 (A4) - margins (10+10)
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, ucfirst($type) . ' Voucher', 0, 1, 'R');
        
         // --- Customer + Invoice Info ---
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="40%">
            <tr>
                <td>
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr>
                            <td width="30%"><b>Voucher #</b></td>
                            <td width="40%">'.$voucher->id.'</td>
                        </tr>
                        <tr>
                            <td width="30%"><b>Date</b></td>
                            <td width="40%">'.\Carbon\Carbon::parse($voucher->date)->format('d-m-Y').'</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        // Details Table
        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="36%">Debit Account</th>
                <th width="36%">Credit Account</th>
                <th width="20%">Amount</th>
            </tr>';

        $html .= '<tr>
            <td>1</td>
            <td>' . ($voucher->debitAccount->name ?? '-') . '</td>
            <td>' . ($voucher->creditAccount->name ?? '-') . '</td>
            <td align="right">' . number_format($voucher->amount, 2) . '</td>
        </tr>';

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="3" align="right"><b>Total</b></td>
                <td align="right"><b>' . number_format($voucher->amount, 2) . '</b></td>
            </tr>';

        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        // Remarks
        if (!empty($voucher->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br($voucher->remarks) . '</span>', true, false, true, false, '');
        }

        // Signatures
        $pdf->Ln(20);
        $yPos = $pdf->GetY();
        $lineWidth = 40;

        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);

        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Prepared By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output(strtolower($type) . '_voucher_' . $voucher->id . '.pdf', 'I');
    }
}
