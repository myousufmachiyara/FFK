<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChartOfAccounts;
use App\Models\SubHeadOfAccounts;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class COAController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Canonical list of valid account types.
    // Must match the blade dropdown values exactly.
    // ─────────────────────────────────────────────────────────────
    private const ACCOUNT_TYPES = [
        'customer',
        'vendor',
        'cash',
        'bank',
        'inventory',
        'liability',
        'equity',
        'revenue',
        'cogs',
        'expenses',
        'receivable',   // ← add: accounts that owe you money (loans given out)
        'payable',      // ← add: accounts you owe money to (loans taken)
    ];

    public function index(Request $request)
    {
        $subHeadOfAccounts = SubHeadOfAccounts::with('headOfAccount')->orderBy('id')->get();

        $query = ChartOfAccounts::with('subHeadOfAccount');

        if ($request->filled('subhead') && $request->subhead !== 'all') {
            $query->where('shoa_id', $request->subhead);
        }

        $chartOfAccounts = $query->latest()->get();

        return view('accounts.coa', compact('chartOfAccounts', 'subHeadOfAccounts'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[COA] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'shoa_id'      => 'required|exists:sub_head_of_accounts,id',
                'name'         => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('chart_of_accounts')->whereNull('deleted_at'),
                ],
                // FIX: validate against canonical list so junk values can't be saved
                'account_type' => ['nullable', 'string', Rule::in(self::ACCOUNT_TYPES)],
                'receivables'  => 'required|numeric',
                'payables'     => 'required|numeric',
                'credit_limit' => 'required|numeric',
                'opening_date' => 'required|date',
                'remarks'      => 'nullable|string|max:800',
                'address'      => 'nullable|string|max:250',
                'contact_no'   => 'nullable|string|max:250',
            ]);

            // ── Auto-generate account code ────────────────────────
            $subHead  = SubHeadOfAccounts::findOrFail($request->shoa_id);
            $prefix   = $subHead->hoa_id . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);

            $existingCodes = ChartOfAccounts::withTrashed()
                ->where('account_code', 'like', $prefix . '%')
                ->pluck('account_code')
                ->map(fn($code) => intval(substr($code, strlen($prefix))))
                ->sort()
                ->values();

            // FIX: handle empty collection — last() returns null on empty
            $nextNumber  = ($existingCodes->isEmpty() ? 0 : $existingCodes->last()) + 1;
            $accountCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            Log::info('[COA] Generated account code', ['code' => $accountCode]);

            $account = ChartOfAccounts::create([
                'shoa_id'      => $request->shoa_id,
                'account_code' => $accountCode,
                'name'         => $request->name,
                'account_type' => $request->account_type,
                'receivables'  => $request->receivables,
                'payables'     => $request->payables,
                'credit_limit' => $request->credit_limit,
                'opening_date' => $request->opening_date,
                'remarks'      => $request->remarks,
                'address'      => $request->address,
                'contact_no'   => $request->contact_no,
                'created_by'   => auth()->id(),
                'updated_by'   => auth()->id(),
            ]);

            Log::info('[COA] Account created', ['id' => $account->id, 'code' => $accountCode]);

            return redirect()->route('coa.index')
                ->with('success', 'Account created successfully.');

        } catch (\Exception $e) {
            Log::error('[COA] Store error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // Returns JSON for the edit modal AJAX call
    public function edit($id)
    {
        $account = ChartOfAccounts::with('subHeadOfAccount')->findOrFail($id);
        return response()->json($account);
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'shoa_id'      => 'required|exists:sub_head_of_accounts,id',
                'name'         => [
                    'required',
                    'string',
                    'max:255',
                    // FIX: ignore the current record when checking uniqueness
                    Rule::unique('chart_of_accounts')->ignore($id)->whereNull('deleted_at'),
                ],
                'account_type' => ['nullable', 'string', Rule::in(self::ACCOUNT_TYPES)],
                'receivables'  => 'required|numeric',
                'payables'     => 'required|numeric',
                'credit_limit' => 'required|numeric',
                'opening_date' => 'required|date',
                'remarks'      => 'nullable|string|max:800',
                'address'      => 'nullable|string|max:250',
                'contact_no'   => 'nullable|string|max:250',
            ]);

            $account = ChartOfAccounts::findOrFail($id);

            $account->update([
                'shoa_id'      => $request->shoa_id,
                'name'         => $request->name,
                'account_type' => $request->account_type,
                'receivables'  => $request->receivables,
                'payables'     => $request->payables,
                'credit_limit' => $request->credit_limit,
                'opening_date' => $request->opening_date,
                'remarks'      => $request->remarks,
                'address'      => $request->address,
                'contact_no'   => $request->contact_no,
                'updated_by'   => auth()->id(),
            ]);

            Log::info('[COA] Account updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('coa.index')
                ->with('success', 'Account updated successfully.');

        } catch (\Exception $e) {
            Log::error('[COA] Update error', ['message' => $e->getMessage()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $account = ChartOfAccounts::with('subHeadOfAccount')->findOrFail($id);
        return response()->json($account);
    }

    public function destroy($id)
    {
        try {
            $account = ChartOfAccounts::findOrFail($id);

            // Guard: block deletion of core system accounts
            $systemCodes = ['101001','102001','104001','201001','202001','301001','401001','402001','501001'];
            if (in_array($account->account_code, $systemCodes)) {
                return redirect()->back()
                    ->with('error', 'System account "' . $account->name . '" cannot be deleted.');
            }

            $account->delete();
            return redirect()->route('coa.index')->with('success', 'Account deleted successfully.');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}