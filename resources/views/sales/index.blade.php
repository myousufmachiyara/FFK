@extends('layouts.app')

@section('title', 'Sale Invoices')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Sale Invoices</h2>
        <a href="{{ route('sale_invoices.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Sale Invoice</a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover datatable">
            <thead class="thead-dark">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Account</th>
                <th>Type</th>
                <th>Total Amount</th>
                <th>Received</th>
                <th>Balance</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            @foreach ($invoices as $invoice)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $invoice->date }}</td>
                <td>{{ $invoice->account->name ?? 'POS Customer' }}</td>
                <td>
                    <span class="badge {{ $invoice->type === 'credit' ? 'bg-warning' : 'bg-success' }}">{{ ucfirst($invoice->type) }}</span>
                </td>
                <td>{{ number_format($invoice->net_amount, 2) }}</td>
                <td>{{ number_format($invoice->amount_received, 2) }}</td>
                <td class="{{ $invoice->remainingBalance() > 0 ? 'text-danger fw-bold' : '' }}">
                    {{ number_format($invoice->remainingBalance(), 2) }}
                </td>
                <td>
                  <a href="{{ route('sale_invoices.edit', $invoice->id) }}" class="text-primary"><i class="fas fa-edit"></i></a>
                  <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                  <form action="{{ route('sale_invoices.destroy', $invoice->id) }}" method="POST" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <button class="text-danger" style="border:none;background:none" onclick="return confirm('This will restore stock and remove all accounting entries for this invoice. Are you sure?')"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
            </tr>
            @endforeach
            </tbody>

          </table>
        </div>
      </div>
    </section>
  </div>
</div>
<script>
  $(document).ready(function () {
    $('.datatable').DataTable();
  });
</script>
@endsection
