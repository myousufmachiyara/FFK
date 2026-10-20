@extends('layouts.app')
@section('title', 'Accounting Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
.narration { font-size: 11px; color: #888; font-style: italic; }
</style>

<div class="tabs">

    <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        @foreach ([
            'general_ledger'   => 'General Ledger',
            'trial_balance'    => 'Trial Balance',
            'profit_loss'      => 'Profit & Loss',
            'balance_sheet'    => 'Balance Sheet',
            'party_ledger'     => 'Party Ledger',
            'receivables'      => 'Receivables',
            'payables'         => 'Payables',
            'cash_book'        => 'Cash Book',
            'bank_book'        => 'Bank Book',
            'journal_book'     => 'Journal / Day Book',
            'expense_analysis' => 'Expense Analysis',
            'cash_flow'        => 'Cash Flow',
        ] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                   id="{{ $key }}-tab" data-bs-toggle="tab"
                   href="#{{ $key }}" role="tab">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mt-3" id="reportTabsContent">

        @foreach ([
            'general_ledger'   => 'General Ledger',
            'trial_balance'    => 'Trial Balance',
            'profit_loss'      => 'Profit & Loss',
            'balance_sheet'    => 'Balance Sheet',
            'party_ledger'     => 'Party Ledger',
            'receivables'      => 'Receivables',
            'payables'         => 'Payables',
            'cash_book'        => 'Cash Book',
            'bank_book'        => 'Bank Book',
            'journal_book'     => 'Journal / Day Book',
            'expense_analysis' => 'Expense Analysis',
            'cash_flow'        => 'Cash Flow',
        ] as $key => $label)

        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
             id="{{ $key }}" role="tabpanel">

            {{-- Filter form --}}
            <form method="GET" action="{{ route('reports.accounts') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="{{ $key }}">
                <input type="hidden" name="tab"    value="{{ $key }}">
                <div class="col-md-2">
                    <input type="date" name="from_date"
                           value="{{ request('from_date', $from) }}" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date"
                           value="{{ request('to_date', $to) }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <select name="account_id" data-plugin-selecttwo class="form-control select2-js">
                        <option value="">-- All Accounts --</option>
                        @foreach ($chartOfAccounts as $coa)
                            <option value="{{ $coa->id }}"
                                {{ request('account_id') == $coa->id ? 'selected' : '' }}>
                                {{ $coa->account_code }} — {{ $coa->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100"
                            onclick="exportReportPDF('{{ $key }}', '{{ $label }}')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            {{-- Table --}}
            <div class="table-responsive" id="report-table-{{ $key }}">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        @if ($key === 'general_ledger')
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Voucher / Ref</th>
                                {{-- FIX 1: Narration column --}}
                                <th>Narration</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'trial_balance')
                            <tr>
                                <th>Account</th><th>Type</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                            </tr>
                        @elseif ($key === 'profit_loss')
                            <tr><th>Particulars</th><th class="text-end">Amount</th></tr>
                        @elseif ($key === 'balance_sheet')
                            <tr>
                                <th>Assets</th><th class="text-end">Amount</th>
                                <th>Liabilities &amp; Equity</th><th class="text-end">Amount</th>
                            </tr>
                        @elseif ($key === 'party_ledger')
                            <tr>
                                <th>Date</th><th>Party</th><th>Voucher / Ref</th>
                                {{-- FIX 1: Narration in party ledger --}}
                                <th>Narration</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'receivables')
                            <tr><th>Account</th><th class="text-end">Total Receivable</th></tr>
                        @elseif ($key === 'payables')
                            <tr><th>Account</th><th class="text-end">Total Payable</th></tr>
                        @elseif ($key === 'cash_book')
                            <tr>
                                <th>Date</th><th>Debit Account</th><th>Credit Account</th>
                                <th>Narration</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'bank_book')
                            <tr>
                                <th>Date</th><th>Debit Account</th><th>Credit Account</th>
                                <th>Narration</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'journal_book')
                            <tr>
                                <th>Date</th><th>Voucher</th>
                                <th>Debit Account</th><th>Credit Account</th>
                                <th>Narration</th>
                                <th class="text-end">Amount</th>
                                <th class="no-print text-center">Actions</th>
                            </tr>
                        @elseif ($key === 'expense_analysis')
                            <tr><th>Expense Head</th><th class="text-end">Amount</th></tr>
                        @elseif ($key === 'cash_flow')
                            <tr><th>Activity</th><th class="text-end">Amount</th></tr>
                        @endif
                    </thead>
                    <tbody>

                    @forelse ($reports[$key] ?? [] as $row)
                        <tr>

                        {{-- ── Custom rendering per report type ── --}}
                        @if ($key === 'general_ledger')
                            {{-- row: [date, account, voucher_ref, narration, dr, cr, balance] --}}
                            <td>{{ $row[0] }}</td>
                            <td>{{ $row[1] }}</td>
                            <td>
                                @php $ref = $row[2] ?? ''; @endphp
                                @if (str_starts_with($ref, 'Voucher #'))
                                    @php $vid = (int) str_replace('Voucher #', '', $ref); @endphp
                                    {{-- FIX 2: Only print link, no eye icon --}}
                                    <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                       target="_blank" class="ref-link">{{ $ref }}</a>
                                @elseif (str_starts_with($ref, 'PI-'))
                                    @php $pid = (int) str_replace('PI-', '', $ref); @endphp
                                    <a href="{{ route('purchase_invoices.print', $pid) }}"
                                       target="_blank" class="ref-link text-success">{{ $ref }}</a>
                                @elseif (str_starts_with($ref, 'SI-'))
                                    @php $sid = (int) str_replace('SI-', '', $ref); @endphp
                                    <a href="{{ route('sale_invoices.print', $sid) }}"
                                       target="_blank" class="ref-link text-primary">{{ $ref }}</a>
                                @else
                                    <span class="text-muted fst-italic">{{ $ref }}</span>
                                @endif
                            </td>
                            {{-- FIX 1: Narration / remarks --}}
                            <td class="narration">{{ $row[3] ?? '' }}</td>
                            <td class="text-end">{{ $row[4] ?? '' }}</td>
                            <td class="text-end">{{ $row[5] ?? '' }}</td>
                            <td class="text-end fw-bold">{{ $row[6] ?? '' }}</td>

                        @elseif ($key === 'party_ledger')
                            {{-- row: [date, party, voucher_ref, narration, dr, cr, balance] --}}
                            <td>{{ $row[0] }}</td>
                            <td>{{ $row[1] }}</td>
                            <td>
                                @php $ref = $row[2] ?? ''; @endphp
                                @if (str_starts_with($ref, 'Voucher #'))
                                    @php $vid = (int) str_replace('Voucher #', '', explode(' ', $ref)[0]); @endphp
                                    <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                       target="_blank" class="ref-link">{{ $ref }}</a>
                                @elseif (str_starts_with($ref, 'PI-'))
                                    @php $pid = (int) str_replace('PI-', '', $ref); @endphp
                                    <a href="{{ route('purchase_invoices.print', $pid) }}"
                                       target="_blank" class="ref-link text-success">{{ $ref }}</a>
                                @elseif (str_starts_with($ref, 'SI-'))
                                    @php $sid = (int) str_replace('SI-', '', $ref); @endphp
                                    <a href="{{ route('sale_invoices.print', $sid) }}"
                                       target="_blank" class="ref-link text-primary">{{ $ref }}</a>
                                @else
                                    <span class="text-muted fst-italic">{{ $ref }}</span>
                                @endif
                            </td>
                            <td class="narration">{{ $row[3] ?? '' }}</td>
                            <td class="text-end">{{ $row[4] ?? '' }}</td>
                            <td class="text-end">{{ $row[5] ?? '' }}</td>
                            <td class="text-end fw-bold">{{ $row[6] ?? '' }}</td>

                        @elseif ($key === 'cash_book' || $key === 'bank_book')
                            {{-- row: [date, dr_account, cr_account, narration, dr, cr, balance] --}}
                            <td>{{ $row[0] }}</td>
                            <td>{{ $row[1] }}</td>
                            <td>{{ $row[2] }}</td>
                            <td class="narration">{{ $row[3] ?? '' }}</td>
                            <td class="text-end">{{ $row[4] ?? '' }}</td>
                            <td class="text-end">{{ $row[5] ?? '' }}</td>
                            <td class="text-end fw-bold">{{ $row[6] ?? '' }}</td>

                        @elseif ($key === 'journal_book')
                            {{-- row: [date, voucher_ref, dr_account, cr_account, narration, amount] --}}
                            <td>{{ $row[0] }}</td>
                            <td>
                                @php
                                    $ref = $row[1] ?? '';
                                    $vid = (int) str_replace('Voucher #', '', $ref);
                                @endphp
                                @if ($vid)
                                    <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                       target="_blank" class="ref-link">{{ $ref }}</a>
                                @else
                                    {{ $ref }}
                                @endif
                            </td>
                            <td>{{ $row[2] }}</td>
                            <td>{{ $row[3] }}</td>
                            <td class="narration">{{ $row[4] ?? '' }}</td>
                            <td class="text-end fw-bold">{{ $row[5] ?? '' }}</td>
                            {{-- FIX 5: Actions use modal (same as voucher index blade) --}}
                            <td class="text-center no-print">
                                @if ($vid)
                                    <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                       target="_blank" class="text-success me-1" title="Print">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <a href="#updateModal" class="text-primary modal-with-form"
                                       onclick="loadVoucherIntoModal({{ $vid }})" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                @endif
                            </td>

                        @else
                            {{-- Default for all other reports --}}
                            @foreach ($row as $col)
                                <td class="{{ is_numeric(str_replace(',', '', $col)) && $col !== '' ? 'text-end' : '' }}
                                           {{ in_array($col, ['REVENUE','LESS: COST OF GOODS SOLD','GROSS PROFIT','OPERATING EXPENSES','NET PROFIT / LOSS']) ? 'fw-bold table-active' : '' }}">
                                    {{ $col }}
                                </td>
                            @endforeach
                        @endif

                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-3">
                                No data found for the selected period.
                            </td>
                        </tr>
                    @endforelse

                    </tbody>

                    @if (in_array($key, ['receivables', 'payables']) && count($reports[$key] ?? []) > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Total:</td>
                            <td class="text-end">
                                {{ number_format(collect($reports[$key])->sum(fn($r) => (float) str_replace(',', '', $r[1])), 2) }}
                            </td>
                        </tr>
                    </tfoot>
                    @endif

                </table>
            </div>
        </div>
        @endforeach

    </div>
</div>

{{-- ── FIX 5: Reuse the voucher edit modal from vouchers/index.blade ── --}}
{{-- This modal is identical to the one in the voucher index page.       --}}
{{-- It loads voucher data via AJAX and updates via PUT.                 --}}
<div id="updateModal" class="modal-block modal-block-primary mfp-hide">
    <section class="card">
        <form method="POST" id="reportUpdateVoucherForm" enctype="multipart/form-data"
              onkeydown="return event.key != 'Enter';">
            @csrf
            @method('PUT')
            <header class="card-header">
                <h2 class="card-title">Edit Journal Voucher</h2>
            </header>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-6 mb-2">
                        <label>Voucher ID</label>
                        <input type="text" class="form-control" id="rv_display_id" disabled>
                    </div>
                    <div class="col-lg-6 mb-2">
                        <label>Date</label>
                        <input type="date" class="form-control" name="date" id="rv_date" required>
                    </div>
                    <div class="col-lg-6 mb-2">
                        <label>Account Debit <span class="text-danger">*</span></label>
                        <select class="form-control select2-js" name="ac_dr_sid" id="rv_dr" required>
                            <option value="" disabled>Select Account</option>
                            @foreach ($chartOfAccounts as $coa)
                                <option value="{{ $coa->id }}">{{ $coa->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-6 mb-2">
                        <label>Account Credit <span class="text-danger">*</span></label>
                        <select class="form-control select2-js" name="ac_cr_sid" id="rv_cr" required>
                            <option value="" disabled>Select Account</option>
                            @foreach ($chartOfAccounts as $coa)
                                <option value="{{ $coa->id }}">{{ $coa->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-6 mb-2">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" id="rv_amount" step="any" required>
                    </div>
                    <div class="col-lg-12 mb-2">
                        <label>Remarks / Narration</label>
                        <textarea rows="3" class="form-control" name="remarks" id="rv_remarks"></textarea>
                    </div>
                </div>
            </div>
            <footer class="card-footer text-end">
                <button type="submit" class="btn btn-primary">Update Voucher</button>
                <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
            </footer>
        </form>
    </section>
</div>

<script>
// ── FIX 5: Load voucher into modal (mirrors getVoucherDetails in vouchers/index) ──
function loadVoucherIntoModal(id) {
    const form = document.getElementById('reportUpdateVoucherForm');
    form.action = '/vouchers/journal/' + id;

    fetch('/vouchers/journal/' + id)
        .then(res => res.json())
        .then(data => {
            document.getElementById('rv_display_id').value = id;
            document.getElementById('rv_date').value       = data.date;
            document.getElementById('rv_amount').value     = data.amount;
            document.getElementById('rv_remarks').value    = data.remarks || '';
            $('#rv_dr').val(data.ac_dr_sid).trigger('change');
            $('#rv_cr').val(data.ac_cr_sid).trigger('change');
        })
        .catch(() => alert('Could not load voucher data.'));
}

// ── PDF export ──────────────────────────────────────────────────
function exportReportPDF(reportKey, reportLabel) {
    const from      = document.querySelector('#' + reportKey + ' input[name="from_date"]')?.value || '{{ $from }}';
    const to        = document.querySelector('#' + reportKey + ' input[name="to_date"]')?.value   || '{{ $to }}';
    const accountEl = document.querySelector('#' + reportKey + ' select[name="account_id"]');
    const accountName = accountEl ? accountEl.options[accountEl.selectedIndex]?.text : 'All Accounts';

    const tableEl = document.getElementById('report-table-' + reportKey);
    if (!tableEl) return;

    const clone = tableEl.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(e => e.remove());
    clone.querySelectorAll('.badge').forEach(b => b.replaceWith(document.createTextNode(b.textContent.trim())));
    clone.querySelectorAll('a').forEach(a => a.replaceWith(document.createTextNode(a.textContent.trim())));

    const html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + reportLabel + '</title>'
        + '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:20px}'
        + 'h2{font-size:14px;margin-bottom:2px}p{font-size:10px;color:#666;margin:0 0 10px}'
        + 'table{width:100%;border-collapse:collapse}'
        + 'th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}'
        + 'td{padding:4px 7px;border-bottom:0.5px solid #ddd}'
        + 'tr:nth-child(even) td{background:#f9f9f9}'
        + 'tfoot td{background:#e9ecef;font-weight:bold}'
        + '.text-end{text-align:right}.fw-bold{font-weight:bold}'
        + '.table-active td{background:#dde;font-weight:bold}'
        + '.narration{color:#888;font-style:italic}</style></head><body>'
        + '<h2>' + reportLabel + '</h2>'
        + '<p>Period: ' + from + ' to ' + to + ' &nbsp;|&nbsp; Account: ' + accountName + '</p>'
        + clone.innerHTML
        + '<script>window.onload=function(){window.print();}<\/script>'
        + '</body></html>';

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}

// ── Tab activation from URL ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        let tab = urlParams.get('tab') || window.location.hash.replace('#', '');
        if (tab) {
            const el = document.querySelector('.nav-link[href="#' + tab + '"]');
            if (el && typeof bootstrap !== 'undefined') {
                new bootstrap.Tab(el).show();
            }
        }
    } catch (e) { console.error('Tab error', e); }

    // Init Select2 inside modal when it opens
    $(document).on('open.magnificPopup', function() {
        $('#rv_dr, #rv_cr').select2({ width: '100%' });
    });
});
</script>
@endsection