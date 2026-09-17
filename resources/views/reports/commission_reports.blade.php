@extends('layouts.app')

@section('title', 'Reports | Commission')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Commission Reports</h2></header>

      <div class="card-body">
        {{-- Filter bar --}}
        <form method="GET" action="{{ route('reports.commission') }}" class="row g-2 mb-3 align-items-end">
          <input type="hidden" name="tab" value="{{ $tab }}">
          <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="from_date" class="form-control" value="{{ $from }}">
          </div>
          <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="to_date" class="form-control" value="{{ $to }}">
          </div>
          <div class="col-md-2">
            <label class="form-label small">Vendor</label>
            <select name="vendor_id" class="select2-report-filter form-control">
              <option value="">All Vendors</option>
              @foreach($vendors as $v)
                <option value="{{ $v->id }}" {{ $vendorId == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Customer</label>
            <select name="customer_id" class="select2-report-filter form-control">
              <option value="">All Customers</option>
              @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ $customerId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Status</label>
            <select name="status" class="form-control">
              <option value="">All</option>
              <option value="pending" {{ $status == 'pending' ? 'selected' : '' }}>Pending</option>
              <option value="in_transit" {{ $status == 'in_transit' ? 'selected' : '' }}>In Transit</option>
              <option value="delivered" {{ $status == 'delivered' ? 'selected' : '' }}>Delivered</option>
            </select>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
          </div>
        </form>

        {{-- Tabs --}}
        <ul class="nav nav-tabs mb-3">
          @foreach([
            'CR'  => 'Commission Register',
            'VW'  => 'Vendor Wise',
            'CW'  => 'Customer Wise',
            'STA' => 'Status Overview',
            'OUT' => 'Outstanding',
            'EXP' => 'Other Expenses',
          ] as $key => $label)
          <li class="nav-item">
            <a class="nav-link {{ $tab == $key ? 'active' : '' }}"
               href="{{ route('reports.commission', array_merge(request()->query(), ['tab' => $key])) }}">
              {{ $label }}
            </a>
          </li>
          @endforeach
        </ul>

        @php
          $statusBadge = fn($s) => match($s) {
            'pending' => 'badge bg-secondary',
            'in_transit' => 'badge bg-warning text-dark',
            'delivered' => 'badge bg-success',
            default => 'badge bg-light text-dark',
          };
        @endphp

        {{-- ═══════════ COMMISSION REGISTER ═══════════ --}}
        @if($tab === 'CR')
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Date</th><th>Invoice</th><th>Vendor</th><th>Customer</th><th>Status</th>
                <th class="text-end">Purchase Amt</th><th class="text-end">Sale Amt</th>
                <th class="text-end">Vendor Comm</th><th class="text-end">Cust Comm</th>
                <th class="text-end">Other Exp</th>
                <th class="text-end">Vendor Payable</th><th class="text-end">Cust Receivable</th>
              </tr>
            </thead>
            <tbody>
              @forelse($commissionRegister as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row->date)->format('d-M-Y') }}</td>
                <td><a href="{{ route('commission_invoices.show', $row->id) }}" class="ref-link text-primary">CI-{{ $row->invoice_no }}</a></td>
                <td>{{ $row->vendor_name }}</td>
                <td>{{ $row->customer_name }}</td>
                <td><span class="{{ $statusBadge($row->status) }}">{{ ucwords(str_replace('_',' ',$row->status)) }}</span></td>
                <td class="text-end">{{ number_format($row->total_purchase_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_sale_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->vendor_commission, 2) }}</td>
                <td class="text-end">{{ number_format($row->customer_commission, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_other_expenses, 2) }}</td>
                <td class="text-end text-danger fw-bold">{{ number_format($row->vendor_payable, 2) }}</td>
                <td class="text-end text-primary fw-bold">{{ number_format($row->customer_receivable, 2) }}</td>
              </tr>
              @empty
              <tr><td colspan="12" class="text-center text-muted">No commission invoices in this range.</td></tr>
              @endforelse
            </tbody>
            @if($commissionRegister->count())
            <tfoot>
              <tr class="fw-bold table-light">
                <td colspan="5" class="text-end">Totals</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('total_purchase_amount'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('total_sale_amount'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('vendor_commission'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('customer_commission'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('total_other_expenses'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('vendor_payable'), 2) }}</td>
                <td class="text-end">{{ number_format($commissionRegister->sum('customer_receivable'), 2) }}</td>
              </tr>
            </tfoot>
            @endif
          </table>
        </div>
        @endif

        {{-- ═══════════ VENDOR WISE ═══════════ --}}
        @if($tab === 'VW')
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Vendor</th><th class="text-center">Invoices</th>
                <th class="text-end">Total Purchase</th><th class="text-end">Vendor Commission</th>
                <th class="text-end">Net Vendor Payable</th>
              </tr>
            </thead>
            <tbody>
              @forelse($vendorWise as $row)
              <tr>
                <td>{{ $row->vendor_name }}</td>
                <td class="text-center">{{ $row->count }}</td>
                <td class="text-end">{{ number_format($row->total_purchase_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_vendor_commission, 2) }}</td>
                <td class="text-end text-danger fw-bold">{{ number_format($row->total_vendor_payable, 2) }}</td>
              </tr>
              @empty
              <tr><td colspan="5" class="text-center text-muted">No data in this range.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @endif

        {{-- ═══════════ CUSTOMER WISE ═══════════ --}}
        @if($tab === 'CW')
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Customer</th><th class="text-center">Invoices</th>
                <th class="text-end">Total Sale</th><th class="text-end">Customer Commission</th>
                <th class="text-end">Other Expenses</th><th class="text-end">Net Receivable</th>
              </tr>
            </thead>
            <tbody>
              @forelse($customerWise as $row)
              <tr>
                <td>{{ $row->customer_name }}</td>
                <td class="text-center">{{ $row->count }}</td>
                <td class="text-end">{{ number_format($row->total_sale_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_customer_commission, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_other_expenses, 2) }}</td>
                <td class="text-end text-primary fw-bold">{{ number_format($row->total_customer_receivable, 2) }}</td>
              </tr>
              @empty
              <tr><td colspan="6" class="text-center text-muted">No data in this range.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @endif

        {{-- ═══════════ STATUS OVERVIEW ═══════════ --}}
        @if($tab === 'STA')
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Status</th><th class="text-center">Count</th>
                <th class="text-end">Total Purchase</th><th class="text-end">Total Sale</th>
                <th class="text-end">Gross Wt.</th><th class="text-end">Vendor Commission</th>
              </tr>
            </thead>
            <tbody>
              @foreach($statusOverview as $row)
              <tr>
                <td><span class="{{ $statusBadge($row->status) }}">{{ $row->label }}</span></td>
                <td class="text-center">{{ $row->count }}</td>
                <td class="text-end">{{ number_format($row->total_purchase_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_sale_amount, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_gross_weight, 2) }} kg</td>
                <td class="text-end">{{ number_format($row->total_vendor_commission, 2) }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @endif

        {{-- ═══════════ OUTSTANDING ═══════════ --}}
        @if($tab === 'OUT')
        <p class="text-muted small">
          <i class="fas fa-info-circle"></i> Commission has no built-in partial-payment tracking — Vendor Payable
          and Customer Receivable here are the full invoice figures, settled via your general Payment Voucher
          system outside this report.
        </p>
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Delivered</th><th>Invoice</th><th>Vendor</th><th>Customer</th>
                <th class="text-end">Vendor Payable</th><th class="text-end">Cust Receivable</th>
                <th>Due Date</th><th class="text-center">Days Since Delivery</th>
              </tr>
            </thead>
            <tbody>
              @forelse($outstanding as $row)
              <tr>
                <td>{{ $row->delivered_at ? \Carbon\Carbon::parse($row->delivered_at)->format('d-M-Y') : '—' }}</td>
                <td><a href="{{ route('commission_invoices.show', $row->id) }}" class="ref-link text-primary">CI-{{ $row->invoice_no }}</a></td>
                <td>{{ $row->vendor_name }}</td>
                <td>{{ $row->customer_name }}</td>
                <td class="text-end text-danger fw-bold">{{ number_format($row->vendor_payable, 2) }}</td>
                <td class="text-end text-primary fw-bold">{{ number_format($row->customer_receivable, 2) }}</td>
                <td class="{{ $row->due_date && now()->greaterThan($row->due_date) ? 'text-danger fw-bold' : '' }}">
                  {{ $row->due_date ? $row->due_date->format('d-M-Y') : '—' }}
                </td>
                <td class="text-center {{ $row->days_since_delivery > 30 ? 'text-danger fw-bold' : '' }}">{{ $row->days_since_delivery ?? '—' }}</td>
              </tr>
              @empty
              <tr><td colspan="8" class="text-center text-muted">No delivered invoices in this range.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @endif

        {{-- ═══════════ OTHER EXPENSES ═══════════ --}}
        @if($tab === 'EXP')
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped">
            <thead>
              <tr>
                <th>Date</th><th>Invoice</th><th>Type</th><th>Description</th>
                <th class="text-end">Amount</th><th>Paid By</th><th>Payable To</th>
              </tr>
            </thead>
            <tbody>
              @forelse($expenseReport as $row)
              <tr>
                <td>{{ $row->date ? \Carbon\Carbon::parse($row->date)->format('d-M-Y') : '—' }}</td>
                <td>CI-{{ $row->invoice_no }}</td>
                <td>{{ $row->type }}</td>
                <td>{{ $row->description }}</td>
                <td class="text-end">{{ number_format($row->amount, 2) }}</td>
                <td><span class="badge {{ $row->paid_by === 'Vendor' ? 'bg-secondary' : 'bg-info text-dark' }}">{{ $row->paid_by }}</span></td>
                <td>{{ $row->payable_to }}</td>
              </tr>
              @empty
              <tr><td colspan="7" class="text-center text-muted">No expenses in this range.</td></tr>
              @endforelse
            </tbody>
            @if($expenseReport->count())
            <tfoot>
              <tr class="fw-bold table-light">
                <td colspan="4" class="text-end">Total</td>
                <td class="text-end">{{ number_format($expenseReport->sum('amount'), 2) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
            @endif
          </table>
        </div>
        @endif

      </div>
    </section>
  </div>
</div>

<script>
$(document).ready(function () {
    $('.select2-report-filter').select2({ width: '100%' });
});
</script>
@endsection