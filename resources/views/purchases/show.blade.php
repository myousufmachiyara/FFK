@extends('layouts.app')

@section('title', 'Purchase | Details')

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
          PUR-{{ $invoice->invoice_no }}
          <span class="{{ $invoice->statusBadgeClass() }} ms-2">{{ $invoice->statusLabel() }}</span>
        </h2>
        <div>
          <a href="{{ route('purchase_invoices.print', $invoice->id) }}" target="_blank" class="btn btn-outline-success">
            <i class="fas fa-print"></i> Print
          </a>

          @if($invoice->isPending())
            <a href="{{ route('purchase_invoices.edit', $invoice->id) }}" class="btn btn-outline-primary">
              <i class="fas fa-edit"></i> Edit
            </a>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#inTransitModal">
              <i class="fas fa-truck"></i> Move to In Transit
            </button>
          @endif

          @if($invoice->isInTransit())
            <a href="{{ route('purchase_invoices.receiveForm', $invoice->id) }}" class="btn btn-success">
              <i class="fas fa-box-open"></i> Receive Goods
            </a>
          @endif
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3"><strong>Invoice Date:</strong><br>{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d-M-Y') }}</div>
          <div class="col-md-3"><strong>Vendor:</strong><br>{{ $invoice->vendor->name ?? 'N/A' }}</div>
          <div class="col-md-2"><strong>Vendor Bill #:</strong><br>{{ $invoice->bill_no ?? '—' }}</div>
          <div class="col-md-2"><strong>Bilty #:</strong><br>{{ $invoice->bilty_no ?? '—' }}</div>
          <div class="col-md-2"><strong>Ref #:</strong><br>{{ $invoice->ref_no ?? '—' }}</div>
        </div>

        <div class="table-responsive mb-4">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>#</th><th>Item</th><th>Variation</th>
                <th>Ordered Qty</th><th>Dispatched</th><th>Received</th><th>Short</th>
                <th>Rate</th><th>Line Total</th><th>Landed Unit Cost</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $i => $item)
              <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product->name ?? '-' }}</td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td>{{ number_format($item->quantity, 2) }}</td>
                <td>{{ $item->dispatched_quantity !== null ? number_format($item->dispatched_quantity, 2) : '—' }}</td>
                <td>{{ $item->received_quantity !== null ? number_format($item->received_quantity, 2) : '—' }}</td>
                <td>{{ $item->short_quantity > 0 ? number_format($item->short_quantity, 2) : '—' }}</td>
                <td>{{ number_format($item->price, 2) }}</td>
                <td>{{ number_format($item->quantity * $item->price, 2) }}</td>
                <td>{{ $item->received_quantity ? number_format($item->landedUnitCost(), 2) : '—' }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if($invoice->isReceived())
        <div class="row mb-4">
          <div class="col-md-3"><strong>Bilty Charges:</strong><br>{{ number_format($invoice->bilty_charges, 2) }}</div>
          <div class="col-md-3"><strong>Labor Charges:</strong><br>{{ number_format($invoice->labor_charges, 2) }}</div>
          <div class="col-md-3"><strong>Other Charges:</strong><br>{{ number_format($invoice->other_charges, 2) }}</div>
          <div class="col-md-3"><strong>Received Date:</strong><br>{{ optional($invoice->received_at)->format('d-M-Y') }}</div>
        </div>
        @endif

        <h5>System-Generated Vouchers</h5>
        <div class="table-responsive mb-4">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Date</th><th>Reference</th><th>Debit A/C</th><th>Credit A/C</th><th>Amount</th><th>Remarks</th></tr></thead>
            <tbody>
              @forelse($vouchers as $v)
              <tr>
                <td>{{ $v->date }}</td>
                <td>{{ $v->reference }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_dr_sid))->name }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_cr_sid))->name }}</td>
                <td>{{ number_format($v->amount, 2) }}</td>
                <td>{{ $v->remarks }}</td>
              </tr>
              @empty
              <tr><td colspan="6" class="text-muted text-center">No vouchers generated yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <h5>Status History</h5>
        <div class="table-responsive mb-2">
          <table class="table table-sm table-bordered">
            <thead><tr><th>From</th><th>To</th><th>Changed By</th><th>At</th><th>Remarks</th></tr></thead>
            <tbody>
              @forelse($invoice->statusHistories as $h)
              <tr>
                <td>{{ $h->from_status ?? '—' }}</td>
                <td>{{ $h->to_status }}</td>
                <td>{{ optional($h->changedBy)->name ?? '—' }}</td>
                <td>{{ $h->created_at->format('d-M-Y H:i') }}</td>
                <td>{{ $h->remarks }}</td>
              </tr>
              @empty
              <tr><td colspan="5" class="text-muted text-center">No history yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<!-- Move to In Transit modal -->
<div class="modal fade" id="inTransitModal" tabindex="-1">
  <div class="modal-dialog">
    <form action="{{ route('purchase_invoices.moveToInTransit', $invoice->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Move to In Transit</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label>Vendor Bill Number *</label>
            <input type="text" name="bill_no" class="form-control" value="{{ $invoice->bill_no }}" required>
          </div>
          <div class="mb-3">
            <label>Bilty Number *</label>
            <input type="text" name="bilty_no" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Attachment (dispatch proof) *</label>
            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.zip"
                   {{ $invoice->attachments->count() ? '' : 'required' }}>
            @if($invoice->attachments->count())
              <small class="text-muted">An attachment already exists on this invoice; upload a new one only if needed.</small>
            @endif
          </div>
          <div class="mb-3">
            <label>Remarks</label>
            <textarea name="remarks" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Confirm Dispatch</button>
        </div>
      </div>
    </form>
  </div>
</div>
@endsection
