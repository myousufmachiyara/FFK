@extends('layouts.app')

@section('title', 'Commission Return | Details')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">CR-{{ $return->return_no }}</h2>
        <div>
          <a href="{{ route('commission_returns.print', $return->id) }}" target="_blank" class="btn btn-outline-success"><i class="fas fa-print"></i> Print</a>
          <a href="{{ route('commission_returns.edit', $return->id) }}" class="btn btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
          <form action="{{ route('commission_returns.destroy', $return->id) }}" method="POST" style="display:inline-block"
                onsubmit="return confirm('Undo this return? Vouchers will be reversed.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger"><i class="fas fa-undo"></i> Undo Return</button>
          </form>
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3"><strong>Return Date:</strong><br>{{ \Carbon\Carbon::parse($return->return_date)->format('d-M-Y') }}</div>
          <div class="col-md-3"><strong>Against:</strong><br><a href="{{ route('commission_invoices.show', $return->commission_invoice_id) }}">CI-{{ $return->commissionInvoice->invoice_no }}</a></div>
          <div class="col-md-3"><strong>Vendor:</strong><br>{{ $return->vendor->name ?? '' }}</div>
          <div class="col-md-3"><strong>Customer:</strong><br>{{ $return->customer->name ?? '' }}</div>
        </div>

        @if($return->reason)<div class="mb-3"><strong>Reason:</strong><br>{{ $return->reason }}</div>@endif

        <h5>Returned Items</h5>
        <div class="table-responsive mb-3">
          <table class="table table-bordered table-sm">
            <thead><tr><th>Item</th><th>Variation</th><th class="text-end">Qty</th><th class="text-end">Net Wt</th><th class="text-end">Sale Value</th><th class="text-end">Vendor Comm</th><th class="text-end">Cust Comm</th></tr></thead>
            <tbody>
              @foreach($return->items as $item)
              <tr>
                <td>{{ $item->product->name ?? '-' }}</td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                <td class="text-end">{{ number_format($item->net_weight, 2) }}</td>
                <td class="text-end">{{ number_format($item->sale_value, 2) }}</td>
                <td class="text-end">{{ number_format($item->vendor_commission, 2) }}</td>
                <td class="text-end">{{ number_format($item->customer_commission, 2) }}</td>
              </tr>
              @endforeach
              <tr class="fw-bold table-light">
                <td colspan="3"></td>
                <td class="text-end">{{ number_format($return->total_weight, 2) }}</td>
                <td class="text-end">{{ number_format($return->total_sale_value, 2) }}</td>
                <td class="text-end">{{ number_format($return->total_vendor_commission, 2) }}</td>
                <td class="text-end">{{ number_format($return->total_customer_commission, 2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="text-muted small">
          <i class="fas fa-info-circle"></i> Net reduction to Customer's receivable:
          <strong>{{ number_format($return->netCustomerReduction(), 2) }}</strong>
          (goods value reversed). Vendor's payable increases by the vendor commission reversed
          (<strong>{{ number_format($return->total_vendor_commission, 2) }}</strong>) since that commission is no longer earned.
        </p>

        <h5>System-Generated Vouchers</h5>
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Date</th><th>Reference</th><th>Debit A/C</th><th>Credit A/C</th><th>Amount</th></tr></thead>
            <tbody>
              @forelse($vouchers as $v)
              <tr>
                <td>{{ $v->date }}</td>
                <td>{{ $v->reference }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_dr_sid))->name }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_cr_sid))->name }}</td>
                <td>{{ number_format($v->amount, 2) }}</td>
              </tr>
              @empty
              <tr><td colspan="5" class="text-muted text-center">No vouchers.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>
@endsection