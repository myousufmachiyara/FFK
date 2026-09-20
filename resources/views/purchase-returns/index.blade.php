@extends('layouts.app')

@section('title', 'Purchase Returns')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Purchase Returns</h2></header>
      <div class="card-body">

        <form method="GET" class="row g-2 mb-3">
          <div class="col-md-3">
            <select name="vendor_id" class="form-control select2-js">
              <option value="">All Vendors</option>
              @foreach($vendors as $v)
                <option value="{{ $v->id }}" {{ request('vendor_id') == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}"></div>
          <div class="col-md-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}"></div>
          <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
        </form>

        <table class="table table-bordered table-striped">
          <thead class="table-dark">
            <tr><th>Return #</th><th>Date</th><th>Against PI</th><th>Vendor</th><th class="text-end">Amount</th><th class="text-center">Actions</th></tr>
          </thead>
          <tbody>
            @forelse($returns as $r)
            <tr>
              <td>PR-{{ $r->return_no }}</td>
              <td>{{ \Carbon\Carbon::parse($r->return_date)->format('d-M-Y') }}</td>
              <td><a href="{{ route('purchase_invoices.show', $r->purchase_invoice_id) }}">PI-{{ $r->purchaseInvoice->invoice_no ?? '-' }}</a></td>
              <td>{{ $r->vendor->name ?? '' }}</td>
              <td class="text-end">{{ number_format($r->total_amount, 2) }}</td>
              <td class="text-center">
                <a href="{{ route('purchase_returns.show', $r->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                <a href="{{ route('purchase_returns.print', $r->id) }}" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i></a>
              </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted">No returns recorded yet.</td></tr>
            @endforelse
          </tbody>
        </table>

        {{ $returns->links() }}
      </div>
    </section>
  </div>
</div>
@endsections