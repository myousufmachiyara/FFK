@extends('layouts.app')

@section('title', 'Purchase | Receive Goods')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_invoices.receive', $invoice->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Receive Goods — PUR-{{ $invoice->invoice_no }} ({{ $invoice->vendor->name ?? '' }})</h2>
        </header>

        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Received Date *</label>
              <input type="date" name="received_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Item</th>
                  <th>Variation</th>
                  <th>Dispatched Qty</th>
                  <th>Received Qty *</th>
                  <th>Short Qty</th>
                  <th>Shortage Reason</th>
                </tr>
              </thead>
              <tbody>
                @foreach($invoice->items as $i => $item)
                <tr>
                  <td>{{ $i + 1 }}</td>
                  <td>
                    {{ $item->product->name ?? '-' }}
                    <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                  </td>
                  <td>{{ $item->variation->sku ?? '-' }}</td>
                  <td>{{ number_format($item->dispatched_quantity ?? $item->quantity, 2) }}</td>
                  <td>
                    <input type="number" step="any" min="0"
                           max="{{ $item->dispatched_quantity ?? $item->quantity }}"
                           name="items[{{ $i }}][received_quantity]"
                           class="form-control received-qty"
                           data-dispatched="{{ $item->dispatched_quantity ?? $item->quantity }}"
                           data-row="{{ $i }}"
                           value="{{ $item->dispatched_quantity ?? $item->quantity }}" required>
                  </td>
                  <td>
                    <input type="text" class="form-control short-qty-display" id="short_{{ $i }}" value="0" disabled>
                  </td>
                  <td>
                    <input type="text" name="items[{{ $i }}][shortage_reason]" class="form-control">
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Bilty Charges</label>
              <input type="number" step="any" min="0" name="bilty_charges" class="form-control" value="0">
            </div>
            <div class="col-md-3">
              <label>Labor Charges</label>
              <input type="number" step="any" min="0" name="labor_charges" class="form-control" value="0">
            </div>
            <div class="col-md-3">
              <label>Other Charges</label>
              <input type="number" step="any" min="0" name="other_charges" class="form-control" value="0">
            </div>
            <div class="col-md-3">
              <label>Attachment</label>
              <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>
          </div>

          <div class="mb-3">
            <label>Remarks</label>
            <textarea name="remarks" class="form-control" rows="2"></textarea>
          </div>

          <small class="text-muted">
            Bilty + Labor + Other charges are split equally per received unit across all items,
            and added into each item's landed inventory cost.
          </small>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Receipt</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  document.querySelectorAll('.received-qty').forEach(function (input) {
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
