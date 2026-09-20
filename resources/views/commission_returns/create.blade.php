@extends('layouts.app')

@section('title', 'Commission Return | Create')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('commission_returns.store') }}" method="POST">
      @csrf
      <input type="hidden" name="commission_invoice_id" value="{{ $invoice->id }}">

      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Return Items — CI-{{ $invoice->invoice_no }} (Vendor: {{ $invoice->vendor->name ?? '' }} / Customer: {{ $invoice->customer->name ?? '' }})</h2>
        </header>
        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
          @endif

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Return Date *</label>
              <input type="date" name="return_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-9">
              <label>Reason / Remarks</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Customer rejected goods, sent back to vendor">
            </div>
          </div>

          <p class="text-muted small">
            <i class="fas fa-info-circle"></i> This reverses the commission income earned on the returned portion
            (both vendor and customer legs) and reduces the customer's receivable by the goods value — proportional
            to how much of each item's weight is returned. Commission has no stock to adjust.
          </p>

          <table class="table table-bordered table-sm">
            <thead>
              <tr>
                <th width="30px"></th>
                <th>Item</th><th>Variation</th>
                <th>Delivered (bags)</th><th>Delivered (kg)</th>
                <th>Remaining Returnable (bags)</th><th>Remaining Returnable (kg)</th>
                <th>Return Qty (bags)</th><th>Return Wt (kg)</th>
              </tr>
            </thead>
            <tbody>
              @foreach($items as $i => $item)
              <tr>
                <td><input type="checkbox" class="item-check" data-idx="{{ $i }}" onchange="toggleRow({{ $i }})"></td>
                <td>
                  {{ $item->product->name ?? '-' }}
                  <input type="hidden" name="items[{{ $i }}][commission_invoice_item_id]" value="{{ $item->id }}" disabled id="pii_{{ $i }}">
                </td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td>{{ number_format($item->quantity, 2) }}</td>
                <td>{{ number_format($item->net_weight, 2) }}</td>
                <td>{{ number_format($item->remaining_qty, 2) }}</td>
                <td>{{ number_format($item->remaining_weight, 2) }}</td>
                <td><input type="number" step="any" min="0" max="{{ $item->remaining_qty }}" name="items[{{ $i }}][qty]" class="form-control" disabled id="qty_{{ $i }}"></td>
                <td><input type="number" step="any" min="0" max="{{ $item->remaining_weight }}" name="items[{{ $i }}][net_weight]" class="form-control" disabled id="wt_{{ $i }}"></td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('commission_invoices.show', $invoice->id) }}" class="btn btn-danger">Cancel</a>
          <button type="submit" class="btn btn-primary">Record Return</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
function toggleRow(idx) {
    const checked = document.querySelector(`.item-check[data-idx="${idx}"]`).checked;
    document.getElementById(`pii_${idx}`).disabled = !checked;
    document.getElementById(`qty_${idx}`).disabled = !checked;
    document.getElementById(`wt_${idx}`).disabled = !checked;
    if (!checked) {
        document.getElementById(`qty_${idx}`).value = '';
        document.getElementById(`wt_${idx}`).value = '';
    }
}
</script>
@endsection