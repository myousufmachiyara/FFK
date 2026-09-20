@extends('layouts.app')

@section('title', 'Purchase Return | Details')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">PR-{{ $return->return_no }}</h2>
        <div>
          <a href="{{ route('purchase_returns.print', $return->id) }}" target="_blank" class="btn btn-outline-success">
            <i class="fas fa-print"></i> Print
          </a>
          <a href="{{ route('purchase_returns.edit', $return->id) }}" class="btn btn-outline-primary">
            <i class="fas fa-edit"></i> Edit
          </a>
          <form action="{{ route('purchase_returns.destroy', $return->id) }}" method="POST" style="display:inline-block"
                onsubmit="return confirm('Undo this return? Stock will be re-added and the voucher reversed.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger"><i class="fas fa-undo"></i> Undo Return</button>
          </form>
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3"><strong>Return Date:</strong><br>{{ \Carbon\Carbon::parse($return->return_date)->format('d-M-Y') }}</div>
          <div class="col-md-3"><strong>Against:</strong><br>
            <a href="{{ route('purchase_invoices.show', $return->purchase_invoice_id) }}">PI-{{ $return->purchaseInvoice->invoice_no }}</a>
          </div>
          <div class="col-md-3"><strong>Vendor:</strong><br>{{ $return->vendor->name ?? '' }}</div>
        </div>

        @if($return->reason)
        <div class="mb-3"><strong>Reason:</strong><br>{{ $return->reason }}</div>
        @endif

        <h5>Returned Items</h5>
        <div class="table-responsive mb-4">
          <table class="table table-bordered table-sm">
            <thead>
              <tr><th>Item</th><th>Variation</th><th class="text-end">Qty (bags)</th><th class="text-end">Net Wt (kg)</th><th class="text-end">Rate/kg</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
              @foreach($return->items as $item)
              <tr>
                <td>{{ $item->item->name ?? '-' }}</td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-end">{{ number_format($item->net_weight, 2) }}</td>
                <td class="text-end">{{ number_format($item->price, 2) }}</td>
                <td class="text-end">{{ number_format($item->amount, 2) }}</td>
              </tr>
              @endforeach
              <tr class="fw-bold table-light">
                <td colspan="3"></td>
                <td class="text-end">{{ number_format($return->total_weight, 2) }}</td>
                <td></td>
                <td class="text-end">{{ number_format($return->total_amount, 2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

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