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
                <th>Rate (40 kg)</th><th>Rate (kg)</th>
              </tr>
            </thead>
            <tbody>
              @php $kgPerMaund = (int) config('purchase_settings.kg_per_maund', 40); @endphp
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
                <td>
                  <input type="number" step="any" min="0" name="items[{{ $i }}][rate_per_40kg]"
                         class="form-control" value="{{ round($item->price * $kgPerMaund, 2) }}"
                         oninput="linkRate('r', {{ $i }}, 'maund')"
                         disabled id="r40_{{ $i }}">
                </td>
                <td>
                  <input type="number" step="any" min="0" name="items[{{ $i }}][rate_per_kg]"
                         class="form-control" value="{{ round($item->price, 4) }}"
                         oninput="linkRate('r', {{ $i }}, 'kg')"
                         disabled id="rkg_{{ $i }}">
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
    document.getElementById(`r40_${idx}`).disabled = !checked;
    document.getElementById(`rkg_${idx}`).disabled = !checked;
    if (!checked) {
        document.getElementById(`qty_${idx}`).value = '';
        document.getElementById(`wt_${idx}`).value = '';
    }
}
const KG_PER_MAUND = {{ (int) config('purchase_settings.kg_per_maund', 40) }};

// ── Two-way rate entry ───────────────────────────────────────────────
// Both boxes are live: type into either and the other fills itself in.
// They arrive pre-filled with the rate the original line was booked at,
// so leaving them alone reverses exactly what was booked.
function linkRate(prefix, idx, source) {
    const r40 = document.getElementById(`${prefix}40_${idx}`);
    const rkg = document.getElementById(`${prefix}kg_${idx}`);
    if (!r40 || !rkg) return;

    if (source === 'kg') {
        const kg = parseFloat(rkg.value);
        r40.value = isNaN(kg) ? '' : +(kg * KG_PER_MAUND).toFixed(2);
    } else {
        const v = parseFloat(r40.value);
        rkg.value = (isNaN(v) || KG_PER_MAUND <= 0) ? '' : +(v / KG_PER_MAUND).toFixed(4);
    }
}

</script>
@endsection