@extends('layouts.app')

@section('title', 'Commission Invoice | Details')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif
      @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">
          CI-{{ $invoice->invoice_no }}
          <span class="{{ $invoice->statusBadgeClass() }} ms-2">{{ $invoice->statusLabel() }}</span>
        </h2>
        <div>
          <a href="{{ route('commission_invoices.print', $invoice->id) }}" target="_blank" class="btn btn-outline-success"><i class="fas fa-print"></i> Print</a>
          @if($invoice->isPending())
            <a href="{{ route('commission_invoices.edit', $invoice->id) }}" class="btn btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#inTransitModal"><i class="fas fa-truck"></i> Move to In Transit</button>
          @endif
          @if($invoice->isInTransit())
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#deliverModal"><i class="fas fa-box-open"></i> Mark as Delivered</button>
          @endif
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-2"><strong>Date:</strong><br>{{ $invoice->invoice_date->format('d-M-Y') }}</div>
          <div class="col-md-2"><strong>Vendor:</strong><br>{{ $invoice->vendor->name ?? 'N/A' }}</div>
          <div class="col-md-2"><strong>Customer:</strong><br>{{ $invoice->customer->name ?? 'N/A' }}</div>
          <div class="col-md-2"><strong>Transport:</strong><br>{{ $invoice->transport_name ?? '—' }}</div>
          <div class="col-md-2"><strong>Bilty #:</strong><br>{{ $invoice->bilty_no ?? '—' }}</div>
          <div class="col-md-2"><strong>Vendor Bill #:</strong><br>{{ $invoice->vendor_bill_no ?? '—' }}</div>
        </div>

        <div class="table-responsive mb-3">
          <table class="table table-bordered table-sm">
            <thead>
              <tr>
                <th>#</th><th>Item</th><th>Qty</th><th>Net Wt</th>
                <th>Pur Rate/kg</th><th>Pur Total</th>
                <th>Sale Rate/kg</th><th>Sale Total</th>
                <th>Vendor Comm %</th><th>Vendor Comm</th>
                <th>Cust Comm %</th><th>Cust Comm</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $i => $item)
              <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product->name ?? '-' }}</td>
                <td>{{ number_format($item->quantity, 0) }}</td>
                <td>{{ number_format($item->net_weight, 2) }}</td>
                <td>{{ number_format($item->purchase_price, 4) }}</td>
                <td>{{ number_format($item->purchase_total, 2) }}</td>
                <td>{{ number_format($item->sale_price, 4) }}</td>
                <td>{{ number_format($item->sale_total, 2) }}</td>
                <td>{{ number_format($item->vendor_commission_percentage, 2) }}</td>
                <td>{{ number_format($item->vendor_commission_amount, 2) }}</td>
                <td>{{ number_format($item->customer_commission_percentage, 2) }}</td>
                <td>{{ number_format($item->customer_commission_amount, 2) }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if($invoice->expenses->count())
        <h5>Other Expenses</h5>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Type</th><th>Description</th><th>Amount</th><th>Paid By</th><th>Payee</th></tr></thead>
            <tbody>
              @foreach($invoice->expenses as $exp)
              <tr>
                <td>{{ $exp->typeLabel() }}</td>
                <td>{{ $exp->description }}</td>
                <td>{{ number_format($exp->amount, 2) }}</td>
                <td><span class="badge {{ $exp->paid_by === 'vendor' ? 'bg-secondary' : 'bg-info text-dark' }}">{{ $exp->paidByLabel() }}</span></td>
                <td>{{ $exp->paid_by === 'vendor' ? ($invoice->vendor->name ?? '-') : ($exp->payeeAccount->name ?? '—') }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @endif

        <div class="row mb-4 text-center">
          <div class="col"><small class="text-muted d-block">Total Purchase</small><strong>{{ number_format($invoice->total_purchase_amount, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Total Sale</small><strong>{{ number_format($invoice->total_sale_amount, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Vendor Commission</small><strong>{{ number_format($invoice->total_vendor_commission_amount, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Customer Commission</small><strong>{{ number_format($invoice->total_customer_commission_amount, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Other Expenses</small><strong>{{ number_format($invoice->total_other_expenses, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Vendor Payable</small><strong class="text-danger">{{ number_format($invoice->totalVendorPayable(), 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Customer Receivable</small><strong class="text-primary">{{ number_format($invoice->totalCustomerReceivable(), 2) }}</strong></div>
        </div>

        @if($invoice->isDelivered())
        <div class="row mb-3">
          <div class="col-md-3"><strong>Delivered At:</strong><br>{{ optional($invoice->delivered_at)->format('d-M-Y') }}</div>
          <div class="col-md-3"><strong>Received By:</strong><br>{{ $invoice->delivery_received_by_name ?? '—' }}</div>
          <div class="col-md-6"><strong>Delivery Remarks:</strong><br>{{ $invoice->delivery_remarks ?? '—' }}</div>
        </div>
        @endif

        @if($invoice->attachments->count())
        <h5>Attachments</h5>
        <div class="mb-3">
          @foreach($invoice->attachments as $file)
            <a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" class="badge bg-light text-dark border me-1 p-2">
              <i class="fas fa-file"></i> {{ ucfirst($file->stage) }}: {{ $file->original_name }}
            </a>
          @endforeach
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
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead><tr><th>From</th><th>To</th><th>Changed By</th><th>At</th><th>Remarks</th></tr></thead>
            <tbody>
              @forelse($invoice->statusHistories as $h)
              <tr>
                <td>{{ $h->from_status ?? '—' }}</td><td>{{ $h->to_status }}</td>
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
    <form action="{{ route('commission_invoices.moveToInTransit', $invoice->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Move to In Transit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label>Vendor Bill Number *</label><input type="text" name="vendor_bill_no" class="form-control" value="{{ $invoice->vendor_bill_no }}" required></div>
          <div class="mb-3"><label>Bilty Number *</label><input type="text" name="bilty_no" class="form-control" value="{{ $invoice->bilty_no }}" required></div>
          <div class="mb-3"><label>Transport Name *</label><input type="text" name="transport_name" class="form-control" value="{{ $invoice->transport_name }}" required></div>
          <div class="mb-3">
            <label>Attachment (dispatch proof) *</label>
            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.zip" {{ $invoice->attachments->count() ? '' : 'required' }}>
            @if($invoice->attachments->count())<small class="text-muted">Attachment already exists; upload only if needed.</small>@endif
          </div>
          <div class="mb-3"><label>Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Confirm Dispatch</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Deliver modal -->
<div class="modal fade" id="deliverModal" tabindex="-1">
  <div class="modal-dialog">
    <form action="{{ route('commission_invoices.deliver', $invoice->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Mark as Delivered</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label>Delivery Date *</label><input type="date" name="delivered_at" class="form-control" value="{{ date('Y-m-d') }}" required></div>
          <div class="mb-3"><label>Received By</label><input type="text" name="delivery_received_by_name" class="form-control"></div>
          <div class="mb-3"><label>Delivery Proof *</label><input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.zip" required></div>
          <div class="mb-3"><label>Remarks</label><textarea name="delivery_remarks" class="form-control" rows="2"></textarea></div>
          <div class="alert alert-secondary small mb-0">
            This will create a Customer Receivable of <strong>{{ number_format($invoice->totalCustomerReceivable(), 2) }}</strong>,
            recognize Vendor Commission of <strong>{{ number_format($invoice->total_vendor_commission_amount, 2) }}</strong> and
            Customer Commission of <strong>{{ number_format($invoice->total_customer_commission_amount, 2) }}</strong>.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Confirm Delivery</button>
        </div>
      </div>
    </form>
  </div>
</div>
@endsection
