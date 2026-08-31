@extends('layouts.app')
@section('title', 'Sales Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

@php
    $typeBadge = fn ($t) => $t === 'credit' ? 'badge bg-warning text-dark' : 'badge bg-success';
@endphp

<div class="tabs">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SR'   ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'SR',  'from_date'=>$from,'to_date'=>$to]) }}">
               Sales Register
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SRET' ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'SRET','from_date'=>$from,'to_date'=>$to]) }}">
               Sales Return
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='CW'   ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'CW',  'from_date'=>$from,'to_date'=>$to]) }}">
               Customer Wise
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='PW'   ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'PW',  'from_date'=>$from,'to_date'=>$to]) }}">
               Product Wise
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='OUT'  ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'OUT']) }}">
               Outstanding Receivables
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='PAY'  ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'PAY', 'from_date'=>$from,'to_date'=>$to]) }}">
               Payment Account Wise
            </a>
        </li>
    </ul>

    <div class="tab-content mt-3">

        {{-- ── SALES REGISTER ──────────────────────────────────── --}}
        <div id="SR" class="tab-pane fade {{ $tab==='SR' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="SR">
                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-3">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            @foreach($customers as $cust)
                                <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                    {{ $cust->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>Type</label>
                        <select name="type" class="form-control">
                            <option value="">All</option>
                            <option value="cash" {{ $type=='cash' ? 'selected' : '' }}>Cash</option>
                            <option value="credit" {{ $type=='credit' ? 'selected' : '' }}>Credit</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sr-table', 'Sales Register', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            @php
                $grandNet    = $sales->sum('net_amount');
                $grandCogs   = $sales->sum('cogs');
                $grandProfit = $sales->sum('profit');
            @endphp
            <div class="mb-3 text-end no-print">
                <h5>Total COGS: <span class="text-secondary">{{ number_format($grandCogs, 2) }}</span></h5>
                <h5>Total Profit: <span class="{{ $grandProfit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($grandProfit, 2) }}</span></h5>
                <h3>Total Revenue: <span class="text-primary">{{ number_format($grandNet, 2) }}</span></h3>
            </div>

            <div id="sr-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice</th><th>Customer</th><th>Type</th>
                            <th class="text-end">Net Amount</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Balance</th>
                            <th class="text-end">COGS</th>
                            <th class="text-end">Profit</th>
                            <th class="text-end">Margin %</th>
                            <th class="no-print text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sales as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>
                                <a href="{{ route('sale_invoices.show', $row->id) }}"
                                   target="_blank" class="ref-link text-primary">
                                    SI-{{ $row->invoice_no }}
                                </a>
                            </td>
                            <td>{{ $row->customer }}</td>
                            <td><span class="{{ $typeBadge($row->type) }}">{{ ucfirst($row->type) }}</span></td>
                            <td class="text-end">{{ number_format($row->net_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($row->amount_received, 2) }}</td>
                            <td class="text-end {{ $row->balance > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row->balance, 2) }}</td>
                            <td class="text-end">{{ number_format($row->cogs, 2) }}</td>
                            <td class="text-end {{ $row->profit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($row->profit, 2) }}</td>
                            <td class="text-end">{{ $row->margin }}%</td>
                            <td class="text-center no-print">
                                <a href="{{ route('sale_invoices.print', $row->id) }}"
                                   target="_blank" class="btn btn-outline-success btn-sm" title="Print">
                                    <i class="fas fa-print"></i>
                                </a>
                                <a href="{{ route('sale_invoices.edit', $row->id) }}"
                                   class="btn btn-outline-primary btn-sm ms-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-center text-muted">No sales found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($sales->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($grandNet, 2) }}</td>
                            <td class="text-end">{{ number_format($sales->sum('amount_received'), 2) }}</td>
                            <td class="text-end">{{ number_format($sales->sum('balance'), 2) }}</td>
                            <td class="text-end">{{ number_format($grandCogs, 2) }}</td>
                            <td class="text-end">{{ number_format($grandProfit, 2) }}</td>
                            <td class="text-end">{{ $grandNet > 0 ? round(($grandProfit / $grandNet) * 100, 1) : 0 }}%</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── SALES RETURN ─────────────────────────────────────── --}}
        <div id="SRET" class="tab-pane fade {{ $tab==='SRET' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="SRET">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sret-table', 'Sales Returns', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="sret-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Return No</th><th>Customer</th>
                            <th class="text-end">Total Return</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($returns as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>SR-{{ $row->invoice }}</td>
                            <td>{{ $row->customer }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No returns found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($returns->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($returns->sum('total'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── CUSTOMER WISE ────────────────────────────────────── --}}
        <div id="CW" class="tab-pane fade {{ $tab==='CW' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="CW">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-3">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            @foreach($customers as $cust)
                                <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                    {{ $cust->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('cw-table', 'Customer-wise Sales', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="cw-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer Name</th>
                            <th class="text-center">No. of Invoices</th>
                            <th class="text-end">Total Revenue</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Outstanding</th>
                            <th class="text-end">COGS</th>
                            <th class="text-end">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($customerWise as $row)
                        <tr>
                            <td>{{ $row->customer }}</td>
                            <td class="text-center">{{ $row->count }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total, 2) }}</td>
                            <td class="text-end">{{ number_format($row->total_received, 2) }}</td>
                            <td class="text-end {{ $row->total_outstanding > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row->total_outstanding, 2) }}</td>
                            <td class="text-end">{{ number_format($row->total_cogs, 2) }}</td>
                            <td class="text-end {{ $row->total_profit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($row->total_profit, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No sales data found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($customerWise->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Grand Total:</td>
                            <td class="text-end text-primary">{{ number_format($customerWise->sum('total'), 2) }}</td>
                            <td class="text-end">{{ number_format($customerWise->sum('total_received'), 2) }}</td>
                            <td class="text-end">{{ number_format($customerWise->sum('total_outstanding'), 2) }}</td>
                            <td class="text-end">{{ number_format($customerWise->sum('total_cogs'), 2) }}</td>
                            <td class="text-end">{{ number_format($customerWise->sum('total_profit'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── PRODUCT WISE (NEW) ───────────────────────────────── --}}
        <div id="PW" class="tab-pane fade {{ $tab==='PW' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="PW">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-3">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            @foreach($customers as $cust)
                                <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                    {{ $cust->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('pw-table', 'Product-wise Sales', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="pw-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">COGS</th>
                            <th class="text-end">Profit</th>
                            <th class="text-end">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($productWise as $row)
                        <tr>
                            <td>{{ $row->product }}</td>
                            <td class="text-end">{{ number_format($row->quantity, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->revenue, 2) }}</td>
                            <td class="text-end">{{ number_format($row->cogs, 2) }}</td>
                            <td class="text-end {{ $row->profit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($row->profit, 2) }}</td>
                            <td class="text-end">{{ $row->margin }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No sales data found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($productWise->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($productWise->sum('quantity'), 2) }}</td>
                            <td class="text-end">{{ number_format($productWise->sum('revenue'), 2) }}</td>
                            <td class="text-end">{{ number_format($productWise->sum('cogs'), 2) }}</td>
                            <td class="text-end">{{ number_format($productWise->sum('profit'), 2) }}</td>
                            <td class="text-end"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── OUTSTANDING RECEIVABLES (NEW) ────────────────────── --}}
        <div id="OUT" class="tab-pane fade {{ $tab==='OUT' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="OUT">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Invoice Date From <small class="text-muted">(optional)</small></label>
                        <input type="date" class="form-control" name="from_date" value="{{ request('from_date') }}">
                    </div>
                    <div class="col-md-3">
                        <label>Invoice Date To <small class="text-muted">(optional)</small></label>
                        <input type="date" class="form-control" name="to_date" value="{{ request('to_date') }}">
                    </div>
                    <div class="col-md-3">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            @foreach($customers as $cust)
                                <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                    {{ $cust->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('out-table', 'Outstanding Receivables', 'As of {{ now()->format('d-M-Y') }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print">
                <i class="fas fa-info-circle"></i> Shows every invoice with an unpaid balance, regardless of sale
                date, unless you set a date range above.
            </p>

            <div id="out-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice</th><th>Customer</th><th>Type</th>
                            <th class="text-end">Net Amount</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Balance Due</th>
                            <th class="text-end">Days Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($outstanding as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>
                                <a href="{{ route('sale_invoices.show', $row->id) }}" target="_blank" class="ref-link text-primary">
                                    SI-{{ $row->invoice_no }}
                                </a>
                            </td>
                            <td>{{ $row->customer }}</td>
                            <td><span class="{{ $typeBadge($row->type) }}">{{ ucfirst($row->type) }}</span></td>
                            <td class="text-end">{{ number_format($row->net_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($row->received, 2) }}</td>
                            <td class="text-end text-danger fw-bold">{{ number_format($row->balance, 2) }}</td>
                            <td class="text-end {{ $row->days_outstanding > 30 ? 'text-danger fw-bold' : '' }}">{{ $row->days_outstanding }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No outstanding receivables. 🎉</td></tr>
                    @endforelse
                    </tbody>
                    @if($outstanding->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($outstanding->sum('net_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($outstanding->sum('received'), 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($outstanding->sum('balance'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── PAYMENT ACCOUNT WISE (NEW) ───────────────────────── --}}
        <div id="PAY" class="tab-pane fade {{ $tab==='PAY' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="PAY">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('pay-table', 'Payment Account-wise Sales', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print">
                <i class="fas fa-info-circle"></i> Every payment actually received against a Sale Invoice (initial or
                added later), grouped by which Cash/Bank account it landed in.
            </p>

            <div id="pay-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Payment Account</th>
                            <th class="text-center">No. of Receipts</th>
                            <th class="text-end">Total Received</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($paymentAccountWise as $row)
                        <tr>
                            <td>{{ $row->account_name }}</td>
                            <td class="text-center">{{ $row->count }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">No receipts in this period.</td></tr>
                    @endforelse
                    </tbody>
                    @if($paymentAccountWise->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Grand Total:</td>
                            <td class="text-center">{{ $paymentAccountWise->sum('count') }}</td>
                            <td class="text-end text-primary">{{ number_format($paymentAccountWise->sum('total'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function exportPDF(tableId, title, period) {
    const el = document.getElementById(tableId);
    if (!el) return;
    const clone = el.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(e => e.remove());
    clone.querySelectorAll('a').forEach(a => {
        const span = document.createElement('span');
        span.textContent = a.textContent.trim();
        a.replaceWith(span);
    });
    clone.querySelectorAll('.badge').forEach(b => {
        const span = document.createElement('span');
        span.textContent = b.textContent.trim();
        b.replaceWith(span);
    });
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px}
        h2{font-size:14px;margin-bottom:4px}p{font-size:10px;color:#555;margin:0 0 10px}
        table{width:100%;border-collapse:collapse}
        th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}
        td{padding:4px 7px;border-bottom:0.5px solid #ddd}
        tr:nth-child(even) td{background:#f9f9f9}
        .text-end{text-align:right}.text-center{text-align:center}.fw-bold{font-weight:bold}
        tfoot td{background:#f0f0f0;font-weight:bold}
    </style></head><body>
    <h2>${title}</h2><p>${period}</p>
    ${clone.innerHTML}
    <script>window.onload=function(){window.print();}<\/script>
    </body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}
</script>
@endsection