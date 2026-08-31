@extends('layouts.app')

@section('title', 'Purchases | All Invoices')

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
                    {{ request('view_deleted') ? 'Deleted' : 'All' }} Purchase Invoices
                </h2>
                <div>
                    @if(request('view_deleted'))
                        <a href="{{ route('purchase_invoices.index') }}" class="btn btn-default me-2">
                            <i class="fas fa-list"></i> View Active
                        </a>
                    @else
                        <a href="{{ route('purchase_invoices.index', ['view_deleted' => 1]) }}" class="btn btn-danger me-2">
                            <i class="fas fa-trash-restore"></i> View Deleted
                        </a>
                    @endif
                    <a href="{{ route('purchase_invoices.create') }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Purchase Invoice
                    </a>
                </div>
            </header>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="purchaseInvoiceTable">
                        <thead>
                            <tr>
                                <th width="4%">#</th>
                                <th>Invoice Date</th>
                                <th>Invoice #</th>
                                <th>Vendor</th>
                                <th>Vendor Bill #</th>
                                <th>Bilty #</th>
                                <th>Status</th>
                                <th>Attachments</th>
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
                                        <span class="text-muted">PUR-{{ $invoice->invoice_no ?? 'N/A' }}</span>
                                    @else
                                        <a href="{{ route('purchase_invoices.show', $invoice->id) }}" class="fw-bold" title="View Details">
                                            PUR-{{ $invoice->invoice_no ?? 'N/A' }}
                                        </a>
                                    @endif
                                </td>

                                <td>{{ $invoice->vendor->name ?? 'N/A' }}</td>
                                <td>{{ $invoice->bill_no ?? '—' }}</td>
                                <td>{{ $invoice->bilty_no ?? '—' }}</td>
                                <td><span class="{{ $invoice->statusBadgeClass() }}">{{ $invoice->statusLabel() }}</span></td>
                                <td>
                                    @if($invoice->attachments && count($invoice->attachments))
                                        @foreach ($invoice->attachments as $file)
                                            <a href="{{ asset('storage/' . $file->file_path) }}"
                                               target="_blank" class="me-1">
                                                <i class="fas fa-file"></i>
                                            </a>
                                        @endforeach
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('purchase_invoices.show', $invoice->id) }}"
                                        class="text-secondary me-1" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    @if($invoice->status === 'pending' && !$invoice->trashed())
                                        <a href="{{ route('purchase_invoices.edit', $invoice->id) }}"
                                            class="text-primary me-1" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif

                                    <a href="{{ route('purchase_invoices.print', $invoice->id) }}"
                                        target="_blank" class="text-success me-1" title="Print">
                                        <i class="fas fa-print"></i>
                                    </a>

                                    @if($invoice->status === 'pending' && !$invoice->trashed())
                                        <form action="{{ route('purchase_invoices.destroy', $invoice->id) }}"
                                                method="POST" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-link p-0 text-danger"
                                                    onclick="return confirm('Move to trash?')" title="Delete">
                                                <i class="fa fa-trash-alt"></i>
                                            </button>
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
    $(document).ready(function() {
        $('#purchaseInvoiceTable').DataTable({
            pageLength: 50,
            order: [[0, 'desc']],
        });
    });
</script>
@endsection
