@extends('layouts.app')

@section('title', 'Purchase Return | Create')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_returns.store') }}" method="POST">
      @csrf
      <input type="hidden" name="purchase_invoice_id" value="{{ $invoice->id }}">

      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Return Items — PI-{{ $invoice->invoice_no }} ({{ $invoice->vendor->name ?? '' }})</h2>
        </header>
        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
              </ul>
            </div>
          @endif

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Return Date *</label>
              <input type="date" name="return_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-9">
              <label>Reason / Remarks</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Damaged in transit, wrong item received">
            </div>
          </div>

          <p class="text-muted small">
            <i class="fas fa-info-circle"></i> Only quantities still remaining (not already returned in a prior return) are shown.
            Check the items you want to return and enter how much of each.
          </p>

          <table class="table table-bordered table-sm">
            <thead>
              <tr>
                <th width="30px"></th>
                <th>Item</th><th>Variation</th>
                <th>Received (bags)</th><th>Received (kg)</th>
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
                  <input type="hidden" name="items[{{ $i }}][purchase_invoice_item_id]" value="{{ $item->id }}" disabled id="pii_{{ $i }}">
                </td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td>{{ number_format($item->received_packing_qty, 2) }}</td>
                <td>{{ number_format($item->received_net_weight, 2) }}</td>
                <td>{{ number_format($item->remaining_qty, 2) }}</td>
                <td>{{ number_format($item->remaining_weight, 2) }}</td>
                <td>
                  <input type="number" step="any" min="0" max="{{ $item->remaining_qty }}"
                         name="items[{{ $i }}][quantity]" class="form-control" disabled id="qty_{{ $i }}">
                </td>
                <td>
                  <input type="number" step="any" min="0" max="{{ $item->remaining_weight }}"
                         name="items[{{ $i }}][net_weight]" class="form-control" disabled id="wt_{{ $i }}">
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('purchase_invoices.show', $invoice->id) }}" class="btn btn-danger">Cancel</a>
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