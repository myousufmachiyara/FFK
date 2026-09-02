@extends('layouts.app')

@section('title', 'Purchase | New Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
    #purchaseTable th { background: #f8f9fa; font-size: 12px; }
    #purchaseTable td { vertical-align: middle; }
    .readonly-calc { background-color: #f0f0f0 !important; }
</style>

<div class="row">
  <div class="col">
    <form id="purchaseForm" action="{{ route('purchase_invoices.store') }}" method="POST" onkeydown="return event.key != 'Enter';" enctype="multipart/form-data">
      @csrf
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">New Purchase Invoice</h2>
        </header>

        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
          @endif

          <div class="alert alert-info">
            Created as <strong>Pending</strong> — no stock or accounting entries yet. Those happen at
            <strong>In Transit</strong> and <strong>Received</strong>.
          </div>

          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Invoice Date</label>
              <input type="date" name="invoice_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>

            <div class="col-md-2 mb-3">
              <label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach ($vendors as $vendor)
                  <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2 mb-3">
              <label>Vendor Bill # <small class="text-muted">(optional now)</small></label>
              <input type="text" name="bill_no" class="form-control">
            </div>

            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control">
            </div>

            <div class="col-md-4 mb-3">
              <label>Attachments <small class="text-muted">(optional now)</small></label>
              <input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>
          </div>
          <div class="row">
            <div class="col-md-12 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2"></textarea>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="purchaseTable">
              <thead>
                <tr>
                  <th width="16%">Item</th>
                  <th width="11%">Variation</th>
                  <th width="10%">Packing</th>
                  <th width="8%">Packing Wt.</th>
                  <th width="6%">Qty</th>
                  <th width="9%">Gross Weight</th>
                  <th width="9%">Net Weight</th>
                  <th width="10%">Rate ({{ $kgPerMaund ?? '40' }}kg)</th>
                  <th width="9%">Rate (per kg)</th>
                  <th width="9%">Amount</th>
                  <th width="30px"></th>
                </tr>
              </thead>
              <tbody id="itemBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addItemRow()"><i class="fas fa-plus"></i> Add Item</button>

          <div class="row mt-3">
            <div class="col-md-3">
              <label>Total Qty (packing units)</label>
              <input type="text" id="sumQty" class="form-control" disabled>
            </div>
            <div class="col-md-3">
              <label>Total Net Weight (kg)</label>
              <input type="text" id="sumWeight" class="form-control" disabled>
            </div>
            <div class="col-md-6 text-end">
              <label><strong>Total Amount</strong></label>
              <h4 class="text-danger mt-0">PKR <span id="sumAmount">0.00</span></h4>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" id="saveBtn" class="btn btn-success"><i class="fas fa-save"></i> Save as Pending</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
let products = @json($products);
let units = @json($units);
const KG_PER_MAUND = {{ $kgPerMaund ?? 40 }};
let itemIdx = 0;

function productOptions() {
    return products.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
}
function unitOptions() {
    return units.map(u => `<option value="${u.id}">${u.name}</option>`).join('');
}

function addItemRow() {
    const idx = itemIdx++;
    const row = `
    <tr data-row="${idx}">
        <td>
            <select name="items[${idx}][item_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required>
                <option value="">Select Item</option>${productOptions()}
            </select>
        </td>
        <td>
            <select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}">
                <option value="">—</option>
            </select>
        </td>
        <td>
            <select name="items[${idx}][packing_unit_id]" class="form-control select2-js">
                <option value="">—</option>${unitOptions()}
            </select>
        </td>
        <td><input type="number" step="any" min="0" name="items[${idx}][wt_per_packing]" class="form-control wt-packing" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc gross-weight" value="0.00"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][net_weight]" class="form-control net-weight" placeholder="= gross wt" oninput="calcRow(${idx})"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][rate_per_40kg]" class="form-control rate-40kg" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc rate-kg" readonly value="0.0000"></td>
        <td><input type="text" class="form-control readonly-calc amount" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#itemBody').append(row);
    $(`#itemBody tr[data-row="${idx}"] .select2-js`).select2({ width: '100%' });
}

function onProductChange(sel, idx) {
    const productId = sel.value;
    const variationSelect = $(`#variation${idx}`);
    if (!productId) {
        variationSelect.html('<option value="">—</option>').trigger('change.select2');
        return;
    }
    variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');
    fetch(`/product/${productId}/variations`)
        .then(res => res.json())
        .then(data => {
            const variations = data.variation || data.variations || [];
            let html = '<option value="">—</option>';
            variations.forEach(v => html += `<option value="${v.id}">${v.sku}</option>`);
            variationSelect.html(html).trigger('change.select2');
        })
        .catch(() => variationSelect.html('<option value="">Error loading</option>').trigger('change.select2'));
}

function calcRow(idx) {
    const $row = $(`#itemBody tr[data-row="${idx}"]`);
    const wtPacking = parseFloat($row.find('.wt-packing').val()) || 0;
    const qty = parseFloat($row.find('.qty').val()) || 0;
    const rate40 = parseFloat($row.find('.rate-40kg').val()) || 0;
    let netInput = $row.find('.net-weight').val();

    const grossWeight = wtPacking * qty;
    const netWeight = (netInput !== '' && !isNaN(parseFloat(netInput))) ? parseFloat(netInput) : grossWeight;
    const rateKg = KG_PER_MAUND > 0 ? (rate40 / KG_PER_MAUND) : 0;
    const amount = rateKg * netWeight;

    $row.find('.gross-weight').val(grossWeight.toFixed(2));
    $row.find('.rate-kg').val(rateKg.toFixed(4));
    $row.find('.amount').val(amount.toFixed(2));

    calcSummary();
}

function removeRow(btn) {
    $(btn).closest('tr').remove();
    calcSummary();
}

function calcSummary() {
    let qty = 0, weight = 0, amount = 0;
    $('#itemBody tr').each(function () {
        qty += parseFloat($(this).find('.qty').val()) || 0;
        weight += parseFloat($(this).find('.net-weight').val()) || parseFloat($(this).find('.gross-weight').val()) || 0;
        amount += parseFloat($(this).find('.amount').val()) || 0;
    });
    $('#sumQty').val(qty.toFixed(2));
    $('#sumWeight').val(weight.toFixed(2));
    $('#sumAmount').text(amount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    addItemRow();

    $('#purchaseForm').on('submit', function () {
        $('#saveBtn').prop('disabled', true).text('Saving...');
    });
});
</script>
@endsection
