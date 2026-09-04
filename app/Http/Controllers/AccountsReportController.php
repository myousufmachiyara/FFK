<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Carbon\Carbon;
use DB;

class AccountsReportController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Account nature constants — single source of truth.
    // Every method that needs to know debit vs credit nature
    // reads from here. Adding a new type only requires changing
    // one of these two arrays.
    //
    // DEBIT-NATURED  = assets & expenses (increase with DR)
    //   customer               — trade receivables
    //   receivable             — loan given / other receivables
    //   cash / bank            — physical money
    //   inventory              — stock in hand
    //   inventory_in_transit   — Purchase: goods dispatched, not yet received (NEW)
    //   commission_in_transit  — Commission: goods dispatched, not yet delivered (NEW)
    //   clearing               — Commission Clearing / suspense (NEW — see note below)
    //   expenses               — operational costs
    //   cogs                   — cost of goods sold
    //
    // CREDIT-NATURED = liabilities, equity & revenue (increase with CR)
    //   vendor      — trade payables
    //   payable     — loan taken / other payables
    //   liability   — general liabilities (includes Purchase Expenses Payable)
    //   equity      — owner capital / investor accounts
    //   revenue     — sales revenue / other income / commission income
    //
    // Note on 'clearing': this account's balance can legitimately swing
    // either way depending on data. Classifying it debit-natured only
    // sets its DEFAULT display column — trialBalance() already flips a
    // debit-natured account's net-credit balance into the credit column
    // automatically, so this classification doesn't hide anything.
    // ─────────────────────────────────────────────────────────────
    private const DEBIT_NATURE  = [
        'customer', 'receivable', 'cash', 'bank', 'inventory',
        'inventory_in_transit', 'commission_in_transit', 'clearing',
        'expenses', 'cogs',
    ];
    private const CREDIT_NATURE = ['vendor', 'payable', 'liability', 'equity', 'revenue'];

    // ─────────────────────────────────────────────────────────────
    // MAIN ENTRY POINT
    // ─────────────────────────────────────────────────────────────
    public function accounts(Request $request)
    {
        $from      = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to        = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = $request->account_id;

        $chartOfAccounts = ChartOfAccounts::orderBy('name')->get();

        $reports = [
            'general_ledger'   => $this->generalLedger($accountId, $from, $to),
            'trial_balance'    => $this->trialBalance($from, $to),
            'profit_loss'      => $this->profitLoss($from, $to),
            'balance_sheet'    => $this->balanceSheet($from, $to),
            'party_ledger'     => $this->partyLedger($from, $to, $accountId),
            'receivables'      => $this->receivables($from, $to),
            'payables'         => $this->payables($from, $to),
            'cash_book'        => $this->cashBook($from, $to),
            'bank_book'        => $this->bankBook($from, $to),
            'journal_book'     => $this->journalBook($from, $to),
            'expense_analysis' => $this->expenseAnalysis($from, $to),
            'cash_flow'        => $this->cashFlow($from, $to),
        ];

        return view('reports.accounts_reports', compact('reports', 'from', 'to', 'chartOfAccounts'));
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER — format number
    // ─────────────────────────────────────────────────────────────
    private function fmt($v): string
    {
        return number_format((float) $v, 2);
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER — is this account debit-natured?
    // ─────────────────────────────────────────────────────────────
    private function isDebitNature(?string $accountType): bool
    {
        return in_array($accountType ?? '', self::DEBIT_NATURE);
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER — turn a voucher's reference into a readable, linkable label.
    //
    // The Purchase/Sale/Commission modules all generate system vouchers
    // with references like "PI-42-INTRANSIT", "SI-17-RECEIPT-1",
    // "CI-9-DELIVERED-COMMISSION" — this parses that pattern into
    // something a person can read ("PI-42 (In Transit)") while keeping
    // the leading "PI-{id}" / "SI-{id}" / "CI-{id}" intact so the blade's
    // existing prefix-based linking (str_starts_with + int cast) keeps
    // working without needing to know the exact suffix format.
    //
    // Manual journal vouchers (no reference) fall back to "Voucher #{id}"
    // exactly as before.
    // ─────────────────────────────────────────────────────────────
    private function describeVoucherReference(?string $reference, int $voucherId): string
    {
        if (!$reference) {
            return "Voucher #{$voucherId}";
        }

        if (preg_match('/^(PI|SI|CI)-(\d+)-(.+)$/', $reference, $m)) {
            $stageLabel = ucwords(strtolower(str_replace(['-', '_'], ' ', $m[3])));
            return "{$m[1]}-{$m[2]} ({$stageLabel})";
        }

        // Unrecognized format (e.g. legacy PR-/SR- style references) — show as-is.
        return $reference;
    }

    // ─────────────────────────────────────────────────────────────
    // CORE BALANCE CALCULATOR
    //
    // Two modes depending on what you pass:
    //
    // Mode A — cumulative balance UP TO a date (for ledgers/opening bal):
    //   Pass $asOfDate. $from is ignored.
    //   Result = COA opening balance + all vouchers up to $asOfDate
    //
    // Mode B — period balance BETWEEN two dates (for P&L, expense report):
    //   Pass $from and $to, leave $asOfDate null.
    //   Result = only vouchers between $from and $to (no opening bal)
    // ─────────────────────────────────────────────────────────────
    private function getAccountBalance(
        $accountId,
        $from,
        $to,
        $asOfDate = null
    ): array {
        $account = ChartOfAccounts::find($accountId);
        if (!$account) return ['debit' => 0, 'credit' => 0];

        if ($asOfDate) {
            // Mode A: cumulative — include COA opening balance
            $openingDr = (float) $account->receivables;
            $openingCr = (float) $account->payables;

            $vDr = Voucher::where('ac_dr_sid', $accountId)
                ->where('date', '<=', $asOfDate)
                ->whereNull('deleted_at')
                ->sum('amount');

            $vCr = Voucher::where('ac_cr_sid', $accountId)
                ->where('date', '<=', $asOfDate)
                ->whereNull('deleted_at')
                ->sum('amount');
        } else {
            // Mode B: period only — no opening balance
            $openingDr = 0;
            $openingCr = 0;

            $vDr = Voucher::where('ac_dr_sid', $accountId)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');

            $vCr = Voucher::where('ac_cr_sid', $accountId)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');
        }

        return [
            'debit'  => $openingDr + $vDr,
            'credit' => $openingCr + $vCr,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // GENERAL LEDGER
    // Shows opening balance + every voucher movement for one account
    // ─────────────────────────────────────────────────────────────
    private function generalLedger($accountId, $from, $to)
    {
        if (!$accountId) return collect();

        $account = ChartOfAccounts::find($accountId);
        if (!$account) return collect();

        // Opening balance = everything before $from
        $opData     = $this->getAccountBalance($accountId, null, null, Carbon::parse($from)->subDay()->toDateString());
        $isDebitNat = $this->isDebitNature($account->account_type);
        $runningBal = $isDebitNat
            ? ($opData['debit'] - $opData['credit'])
            : ($opData['credit'] - $opData['debit']);

        $rows = collect();
        $rows->push([
            $from,
            $account->name,
            'Opening Balance',
            '',                                  // narration
            $this->fmt($opData['debit']),
            $this->fmt($opData['credit']),
            $this->fmt($runningBal),
        ]);

        $vouchers = Voucher::whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('ac_dr_sid', $accountId)->orWhere('ac_cr_sid', $accountId))
            ->orderBy('date')
            ->get();

        foreach ($vouchers as $v) {
            $dr = ($v->ac_dr_sid == $accountId) ? $v->amount : 0;
            $cr = ($v->ac_cr_sid == $accountId) ? $v->amount : 0;

            $runningBal += $isDebitNat ? ($dr - $cr) : ($cr - $dr);

            $rows->push([
                $v->date,
                $account->name,
                $this->describeVoucherReference($v->reference, $v->id),
                $v->remarks ?? '',               // narration
                $this->fmt($dr),
                $this->fmt($cr),
                $this->fmt($runningBal),
            ]);
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // PARTY LEDGER
    // Same as general ledger but designed for a customer/vendor/loan
    // account — shows "Opening Balance" label and running balance
    // ─────────────────────────────────────────────────────────────
    private function partyLedger($from, $to, $accountId = null)
    {
        if (!$accountId) return collect();
 
        $account = ChartOfAccounts::find($accountId);
        if (!$account) return collect();
 
        $opData     = $this->getAccountBalance($accountId, null, null, Carbon::parse($from)->subDay()->toDateString());
        $isDebitNat = $this->isDebitNature($account->account_type);
        $runningBal = $isDebitNat
            ? ($opData['debit'] - $opData['credit'])
            : ($opData['credit'] - $opData['debit']);
 
        $rows = collect();
        $rows->push([
            $from,
            $account->name,
            'Opening Balance',
            '',                                   // narration
            $this->fmt($opData['debit']),
            $this->fmt($opData['credit']),
            $this->fmt($runningBal),
        ]);
 
        $vouchers = Voucher::whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('ac_dr_sid', $accountId)->orWhere('ac_cr_sid', $accountId))
            ->orderBy('date')
            ->get();
 
        $movements = $vouchers->map(function ($v) use ($accountId, $isDebitNat, &$runningBal) {
            $isDr     = $v->ac_dr_sid == $accountId;
            $drAmount = $isDr ? $v->amount : 0;
            $crAmount = $isDr ? 0 : $v->amount;
 
            $runningBal += $isDebitNat
                ? ($drAmount - $crAmount)
                : ($crAmount - $drAmount);
 
            $refLabel = $this->describeVoucherReference($v->reference, $v->id);
 
            return [
                $v->date,
                '',
                $refLabel . ' — ' . ($isDr ? 'Debit' : 'Credit'),
                $v->remarks ?? '',                // narration
                $this->fmt($drAmount),
                $this->fmt($crAmount),
                $this->fmt($runningBal),
            ];
        });
 
        return $rows->concat($movements);
    }

    // ─────────────────────────────────────────────────────────────
    // PROFIT & LOSS
    // Period-only (Mode B) — revenue, COGS, expenses.
    // Revenue automatically includes Sales Revenue, Other Income, AND
    // Commission Income (all account_type = 'revenue'). Expenses
    // automatically includes Inventory Shortage/Loss alongside
    // Salaries/Rent/Utilities/Misc (all account_type = 'expenses').
    // No code change needed here for the new modules — this was
    // already generic by account_type, not hardcoded to specific accounts.
    // ─────────────────────────────────────────────────────────────
    private function profitLoss($from, $to)
    {
        $revenue = ChartOfAccounts::where('account_type', 'revenue')->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['credit'] - $bal['debit']];
            })->filter(fn($r) => $r[1] != 0);

        $cogs = ChartOfAccounts::whereIn('account_type', ['cogs', 'cost_of_sales'])->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['debit'] - $bal['credit']];
            })->filter(fn($r) => $r[1] != 0);

        $expenses = ChartOfAccounts::where('account_type', 'expenses')->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['debit'] - $bal['credit']];
            })->filter(fn($r) => $r[1] != 0);

        $totalRev    = $revenue->sum(fn($r) => $r[1]);
        $totalCogs   = $cogs->sum(fn($r) => $r[1]);
        $grossProfit = $totalRev - $totalCogs;
        $totalExp    = $expenses->sum(fn($r) => $r[1]);
        $netProfit   = $grossProfit - $totalExp;

        $data = collect([['REVENUE', '']]);
        $data = $data->concat($revenue);
        $data->push(['Total Revenue', $this->fmt($totalRev)]);

        $data->push(['LESS: COST OF GOODS SOLD', '']);
        $data = $data->concat($cogs);
        $data->push(['GROSS PROFIT', $this->fmt($grossProfit)]);

        $data->push(['OPERATING EXPENSES', '']);
        $data = $data->concat($expenses);
        $data->push(['NET PROFIT / LOSS', $this->fmt($netProfit)]);

        return $data;
    }

    // ─────────────────────────────────────────────────────────────
    // RECEIVABLES
    // account_type='customer' already aggregates BOTH Sale and
    // Commission receivables automatically — a customer used in both
    // modules shares one Chart of Accounts entry, and this sums every
    // voucher touching that account id regardless of which module
    // posted it. No change needed for the new modules.
    // ─────────────────────────────────────────────────────────────
    private function receivables($from, $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['customer', 'receivable'])->get()
            ->map(function ($a) use ($to) {
                $bal   = $this->getAccountBalance($a->id, null, null, $to);
                $total = $bal['debit'] - $bal['credit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────
    // PAYABLES
    // Same reasoning as Receivables — a vendor used in both Purchase
    // and Commission automatically nets correctly here. Purchase
    // Expenses Payable (account_type='liability') is intentionally
    // NOT included — it's a control account, not a named party, so it
    // doesn't belong in a per-vendor payables list.
    // ─────────────────────────────────────────────────────────────
    private function payables($from, $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['vendor', 'payable'])->get()
            ->map(function ($a) use ($to) {
                $bal   = $this->getAccountBalance($a->id, null, null, $to);
                $total = $bal['credit'] - $bal['debit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────
    // TRIAL BALANCE
    // Uses DEBIT_NATURE constant — the 3 new account types
    // (inventory_in_transit, commission_in_transit, clearing) are now
    // automatically classified correctly without any other change here.
    // ─────────────────────────────────────────────────────────────
    private function trialBalance($from, $to)
    {
        return ChartOfAccounts::all()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, null, null, $to);

                if ($this->isDebitNature($a->account_type)) {
                    $diff   = $bal['debit'] - $bal['credit'];
                    $debit  = $diff > 0 ? $diff : 0;
                    $credit = $diff < 0 ? abs($diff) : 0;
                } else {
                    $diff   = $bal['credit'] - $bal['debit'];
                    $credit = $diff > 0 ? $diff : 0;
                    $debit  = $diff < 0 ? abs($diff) : 0;
                }

                return [$a->name, $a->account_type, $this->fmt($debit), $this->fmt($credit)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[2]) != 0 || (float) str_replace(',', '', $r[3]) != 0);
    }

    // ─────────────────────────────────────────────────────────────
    // BALANCE SHEET
    // FIX: assets side now includes 'inventory_in_transit',
    // 'commission_in_transit', and 'clearing' — without this, goods
    // currently in transit (real company money tied up in Purchase
    // orders, or the Commission clearing balance) would silently
    // vanish from the balance sheet instead of showing as an asset.
    // ─────────────────────────────────────────────────────────────
    private function balanceSheet($from, $to)
    {
        $trial       = $this->trialBalance($from, $to);
        $assets      = collect();
        $liabilities = collect();

        $assetTypes = [
            'customer', 'receivable', 'cash', 'bank', 'inventory',
            'inventory_in_transit', 'commission_in_transit', 'clearing',
        ];
        $liabilityTypes = ['vendor', 'payable', 'liability', 'equity'];

        foreach ($trial as $r) {
            $type   = $r[1];
            $debit  = (float) str_replace(',', '', $r[2]);
            $credit = (float) str_replace(',', '', $r[3]);

            if (in_array($type, $assetTypes)) {
                $val = $debit - $credit;
                if ($val != 0) $assets->push([$r[0], $this->fmt($val)]);

            } elseif (in_array($type, $liabilityTypes)) {
                $val = $credit - $debit;
                if ($val != 0) $liabilities->push([$r[0], $this->fmt($val)]);
            }
            // 'expenses', 'cogs', 'revenue' intentionally excluded —
            // they flow through P&L into Retained Earnings, not directly on balance sheet
        }

        $max  = max($assets->count(), $liabilities->count(), 1);
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                $assets[$i][0]      ?? '',
                $assets[$i][1]      ?? '',
                $liabilities[$i][0] ?? '',
                $liabilities[$i][1] ?? '',
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // CASH BOOK
    // ─────────────────────────────────────────────────────────────
    private function cashBook($from, $to)
    {
        $cashIds = ChartOfAccounts::where('account_type', 'cash')->pluck('id');
        return $this->bookHelper($cashIds, $from, $to);
    }

    // ─────────────────────────────────────────────────────────────
    // BANK BOOK
    // ─────────────────────────────────────────────────────────────
    private function bankBook($from, $to)
    {
        $bankIds = ChartOfAccounts::where('account_type', 'bank')->pluck('id');
        return $this->bookHelper($bankIds, $from, $to);
    }

    private function bookHelper($ids, $from, $to)
    {
        if ($ids->isEmpty()) return collect();

        $vouchers = Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->whereIn('ac_dr_sid', $ids)->orWhereIn('ac_cr_sid', $ids))
            ->orderBy('date')
            ->get();

        $bal     = 0;
        $idsArr  = $ids->toArray();

        return $vouchers->map(function ($v) use ($idsArr, &$bal) {
            $dr   = in_array($v->ac_dr_sid, $idsArr) ? $v->amount : 0;
            $cr   = in_array($v->ac_cr_sid, $idsArr) ? $v->amount : 0;
            $bal += ($dr - $cr);

            return [
                $v->date,
                $v->debitAccount->name  ?? '—',
                $v->creditAccount->name ?? '—',
                $v->remarks ?? '',               // narration
                $this->fmt($dr),
                $this->fmt($cr),
                $this->fmt($bal),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────
    // EXPENSE ANALYSIS
    // Automatically includes the new Inventory Shortage/Loss account
    // (account_type='expenses') alongside Salaries/Rent/Utilities/Misc.
    // ─────────────────────────────────────────────────────────────
    private function expenseAnalysis($from, $to)
    {
        return ChartOfAccounts::where('account_type', 'expenses')
            ->get()
            ->map(function ($a) use ($from, $to) {
                $bal   = $this->getAccountBalance($a->id, $from, $to);
                $total = $bal['debit'] - $bal['credit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) != 0);
    }

    // ─────────────────────────────────────────────────────────────
    // JOURNAL / DAY BOOK
    // Excludes every system-generated voucher from Purchase/Sale/
    // Commission (they all set 'reference'), showing only manual
    // journal entries — unchanged, already correct for the new modules.
    // ─────────────────────────────────────────────────────────────
    private function journalBook($from, $to)
    {
        return Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->whereNull('reference')          // exclude system entries (PI-, SI-, CI-, PR-, SR-)
            ->orderBy('date')
            ->get()
            ->map(fn($v) => [
                $v->date,
                "Voucher #{$v->id}",
                $v->debitAccount->name  ?? 'N/A',
                $v->creditAccount->name ?? 'N/A',
                $v->remarks ?? '',               // narration
                $this->fmt($v->amount),
            ]);
    }

    // ─────────────────────────────────────────────────────────────
    // CASH FLOW (simplified — operating activities only)
    // ─────────────────────────────────────────────────────────────
    private function cashFlow($from, $to)
    {
        $cashBankIds = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id');

        $inflow  = Voucher::whereIn('ac_dr_sid', $cashBankIds)->whereBetween('date', [$from, $to])->whereNull('deleted_at')->sum('amount');
        $outflow = Voucher::whereIn('ac_cr_sid', $cashBankIds)->whereBetween('date', [$from, $to])->whereNull('deleted_at')->sum('amount');

        return [
            ['Total Cash Inflow (Receipts)',       $this->fmt($inflow)],
            ['Total Cash Outflow (Payments)',       $this->fmt($outflow)],
            ['Net Increase / Decrease in Cash',    $this->fmt($inflow - $outflow)],
        ];
    }
}