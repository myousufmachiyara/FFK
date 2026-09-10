@extends('layouts.app')

@section('title', 'Purchase | Receive Goods')

@section('content')
<style>
    #itemTable th { font-size: 12px; }
</style>

<div class="row">
  <div class="col">
    <form action="{{ route('purchase_invoices.receive', $invoice->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Receive Goods — PI-{{ $invoice->invoice_no }} ({{ $invoice->vendor->name ?? '' }})</h2>
        </header>

        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
          @endif

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Received Date *</label>
              <input type="date" name="received_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="itemTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Item</th>
                  <th>Dispatched Net Wt (kg)</th>
                  <th>Received Bags <small class="text-muted">(optional)</small></th>
                  <th>Received Net Wt (kg) *</th>
                  <th>Short Wt (kg)</th>
                  <th>Shortage Reason</th>
                </tr>
              </thead>
              <tbody>
                @foreach($invoice->items as $i => $item)
                <tr>
                  <td>{{ $i + 1 }}</td>
                  <td>
                    {{ $item->product->name ?? '-' }} @if($item->variation) ({{ $item->variation->sku }}) @endif
                    <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                  </td>
                  <td>{{ number_format($item->net_weight, 2) }}</td>
                  <td>
                    <input type="number" step="any" min="0" name="items[{{ $i }}][received_packing_qty]"
                           class="form-control" value="{{ $item->quantity }}">
                  </td>
                  <td>
                    <input type="number" step="any" min="0" max="{{ $item->net_weight }}"
                           name="items[{{ $i }}][received_net_weight]"
                           class="form-control received-weight"
                           data-dispatched="{{ $item->net_weight }}"
                           data-row="{{ $i }}"
                           value="{{ $item->net_weight }}" required>
                  </td>
                  <td>
                    <input type="text" class="form-control short-weight-display" id="short_{{ $i }}" value="0.00" disabled>
                  </td>
                  <td>
                    <input type="text" name="items[{{ $i }}][shortage_reason]" class="form-control">
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @if($invoice->expenses->count())
          <h5 class="mt-4">Other Expenses <small class="text-muted">(entered at creation — confirm, don't re-enter)</small></h5>
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm">
              <thead>
                <tr><th>Type</th><th>Description</th><th>Amount</th><th>Paid By</th><th>Payee</th></tr>
              </thead>
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
                <tr class="fw-bold table-light">
                  <td colspan="2" class="text-end">Total</td>
                  <td>{{ number_format($invoice->total_other_expenses, 2) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-muted small">
            <i class="fas fa-info-circle"></i> These are allocated across items proportional to dispatched net weight,
            and added into inventory cost when you confirm below. Need to change an amount or payee? Edit is locked
            after In Transit — contact an admin if a correction is needed before receiving.
          </p>
          @else
          <p class="text-muted small mt-3">No Other Expenses were entered for this invoice.</p>
          @endif

          <div class="row mb-3 mt-3">
            <div class="col-md-6">
              <label>Attachment</label>
              <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>
            <div class="col-md-6">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="1"></textarea>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Receipt</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  document.querySelectorAll('.received-weight').forEach(function (input) {
    input.addEventListener('input', function () {
      const row = this.dataset.row;
      const dispatched = parseFloat(this.dataset.dispatched) || 0;
      const received = parseFloat(this.value) || 0;
      const short = Math.max(dispatched - received, 0);
      document.getElementById('short_' + row).value = short.toFixed(2);
    });
  });
</script>
@endsection