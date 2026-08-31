@extends('layouts.app')

@section('title', 'Commission Invoices')

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
        <h2 class="card-title">
            {{ request('view_deleted') ? 'Deleted' : 'All' }} Commission Invoices
        </h2>
        <div>
          @if(request('view_deleted'))
            <a href="{{ route('commission_invoices.index') }}" class="btn btn-default me-2">
                <i class="fas fa-list"></i> View Active
            </a>
          @else
            <a href="{{ route('commission_invoices.index', ['view_deleted' => 1]) }}" class="btn btn-danger me-2">
                <i class="fas fa-trash-restore"></i> View Deleted
            </a>
          @endif
          <a href="{{ route('commission_invoices.create') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Commission Invoice
          </a>
        </div>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover" id="commissionTable">
            <thead class="thead-dark">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Invoice #</th>
                <th>Vendor</th>
                <th>Customer</th>
                <th>Transport</th>
                <th>Status</th>
                <th>Vendor Payable</th>
                <th>Customer Receivable</th>
                <th>Commission</th>
                <th width="12%">Actions</th>
              </tr>
            </thead>
            <tbody>
            @foreach ($invoices as $index => $invoice)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d-M-Y') }}</td>
                <td>
                  @if($invoice->trashed())
                    <span class="text-muted">CI-{{ $invoice->invoice_no }}</span>
                  @else
                    <a href="{{ route('commission_invoices.show', $invoice->id) }}" class="fw-bold">CI-{{ $invoice->invoice_no }}</a>
                  @endif
                </td>
                <td>{{ $invoice->vendor->name ?? 'N/A' }}</td>
                <td>{{ $invoice->customer->name ?? 'N/A' }}</td>
                <td>{{ $invoice->transport_name ?? '—' }}</td>
                <td><span class="{{ $invoice->statusBadgeClass() }}">{{ $invoice->statusLabel() }}</span></td>
                <td>{{ number_format($invoice->totalVendorPayable(), 2) }}</td>
                <td>{{ number_format($invoice->totalCustomerReceivable(), 2) }}</td>
                <td>{{ number_format($invoice->total_commission_amount, 2) }}</td>
                <td>
                  <a href="{{ route('commission_invoices.show', $invoice->id) }}" class="text-secondary me-1" title="View"><i class="fas fa-eye"></i></a>

                  @if($invoice->status === 'pending' && !$invoice->trashed())
                    <a href="{{ route('commission_invoices.edit', $invoice->id) }}" class="text-primary me-1" title="Edit"><i class="fas fa-edit"></i></a>
                  @endif

                  <a href="{{ route('commission_invoices.print', $invoice->id) }}" target="_blank" class="text-success me-1" title="Print"><i class="fas fa-print"></i></a>

                  @if($invoice->status === 'pending' && !$invoice->trashed())
                    <form action="{{ route('commission_invoices.destroy', $invoice->id) }}" method="POST" style="display:inline;">
                      @csrf
                      @method('DELETE')
                      <button class="btn btn-link p-0 text-danger" onclick="return confirm('Move to trash?')" title="Delete"><i class="fa fa-trash-alt"></i></button>
                    </form>
                  @endif
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
    $('#commissionTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
  });
</script>
@endsection
