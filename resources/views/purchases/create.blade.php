@extends('layouts.app')

@section('title', 'Purchase | New Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
    #purchaseTable th, #expenseTable th { background: #f8f9fa; font-size: 12px; }
    #purchaseTable td, #expenseTable td { vertical-align: middle; }
    .readonly-calc { background-color: #f0f0f0 !important; }
</style>

<div class="row">
  <div class="col">
    <form id="purchaseForm" action="{{ route('purchase_invoices.store') }}" method="POST" onkeydown="return event.key != 'Enter';" enctype="multipart/form-data">
      @csrf

      {{-- ═══════════════ MASTER SECTION ═══════════════ --}}
      <section class="card mb-3">
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
              <label>Bilti # <small class="text-muted">(optional now)</small></label>
              <input type="text" name="bilty_no" class="form-control">
            </div>

            <div class="col-md-2 mb-3">
              <label>Transport Name <small class="text-muted">(optional now)</small></label>
              <input type="text" name="transport_name" class="form-control">
            </div>

            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control">
            </div>

            <div class="col-md-2 mb-3">
              <label>Payment Terms</label>
              <select name="payment_terms" id="paymentTerms" class="form-control" required>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
              </select>
            </div>

            <div class="col-md-2 mb-3" id="creditDaysWrap" style="display:none;">
              <label>Credit Days</label>
              <input type="number" min="1" name="credit_days" id="creditDays" class="form-control" placeholder="e.g. 30">
            </div>

            <div class="col-md-4 mb-3">
              <label>Attachments <small class="text-muted">(optional now)</small></label>
              <input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>

            <div class="col-md-8 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="1"></textarea>
            </div>
          </div>
        </div>
      </section>

      {{-- ═══════════════ ITEMS GRID SECTION ═══════════════ --}}
      <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Items</h2></header>
        <div class="card-body">
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="purchaseTable">
              <thead>
                <tr>
                  <th width="15%">Item</th>
                  <th width="10%">Variation</th>
                  <th width="9%">Packing</th>
                  <th width="8%">Wt./Packing (kg)</th>
                  <th width="6%">Qty</th>
                  <th width="9%">Gross Weight</th>
                  <th width="9%">Net Weight</th>
                  <th width="10%">Rate (40 kg)</th>
                  <th width="8%">Rate (kg)</th>
                  <th width="9%">Amount</th>
                  <th width="30px"></th>
                </tr>
              </thead>
              <tbody id="itemBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addItemRow()"><i class="fas fa-plus"></i> Add Item</button>
        </div>
      </section>

      {{-- ═══════════════ EXTRA EXPENSES SECTION ═══════════════ --}}
      <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Other Expenses</h2></header>
        <div class="card-body">
          <div class="table-responsive mb-2">
            <table class="table table-bordered table-sm" id="expenseTable">
              <thead>
                <tr>
                  <th width="15%">Type</th>
                  <th width="27%">Description</th>
                  <th width="13%">Amount</th>
                  <th width="15%">Paid By</th>
                  <th width="22%">Payee Account <small class="text-muted">(if Company)</small></th>
                  <th width="30px"></th>
                </tr>
              </thead>
              <tbody id="expenseBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addExpenseRow()"><i class="fas fa-plus"></i> Add Expense</button>
          <p class="text-muted small mt-2 mb-0">
            <i class="fas fa-info-circle"></i> Every expense is always added to the inventory landed cost.
            If the <strong>Vendor</strong> paid it, it also increases what we owe the Vendor. If
            <strong>Company (FFK)</strong> paid it, pick which account we owe instead (e.g. a specific transporter).
          </p>
        </div>
      </section>

      {{-- ═══════════════ SUMMARY SECTION ═══════════════ --}}
      <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Purchase Invoice Summary</h2></header>
        <div class="card-body">
          <div class="row text-center">
            <div class="col"><small class="text-muted d-block">Total Item Amount</small><strong id="sumItemAmount">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Expense Amount</small><strong id="sumExpenseAmount">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Gross Wt. (kg)</small><strong id="sumGrossWeight">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Net Wt. (kg)</small><strong id="sumNetWeight">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Bill Amount</small><strong class="text-danger" id="sumBillAmount">0.00</strong></div>
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
let payeeAccounts = @json($payeeAccounts);
const KG_PER_MAUND = {{ $kgPerMaund ?? 40 }};
let itemIdx = 0;
let expenseIdx = 0;

function productOptions() { return products.map(p => `<option value="${p.id}">${p.name}</option>`).join(''); }
function unitOptions() { return units.map(u => `<option value="${u.id}">${u.name}</option>`).join(''); }
function payeeOptions() { return payeeAccounts.map(a => `<option value="${a.id}">${a.name}</option>`).join(''); }

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
        <td><input type="text" class="form-control readonly-calc gross-weight" readonly value="0.00"></td>
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

function addExpenseRow() {
    const idx = expenseIdx++;
    const row = `
    <tr data-erow="${idx}">
        <td>
            <select name="expenses[${idx}][expense_type]" class="form-control">
                <option value="local_cartage">Local Cartage</option>
                <option value="packaging">Packaging</option>
                <option value="plastic_bags">Plastic Bags</option>
                <option value="bardana">Bardana</option>
                <option value="misc">Miscellaneous</option>
                <option value="tulai">Tulai</option>
                <option value="others">Others</option>
            </select>
        </td>
        <td><input type="text" name="expenses[${idx}][description]" class="form-control"></td>
        <td><input type="number" step="any" min="0" name="expenses[${idx}][amount]" class="form-control exp-amount" oninput="calcSummary()"></td>
        <td>
            <select name="expenses[${idx}][paid_by]" class="form-control paid-by" onchange="togglePayee(${idx})">
                <option value="vendor">Vendor</option>
                <option value="company">Company (FFK)</option>
            </select>
        </td>
        <td>
            <select name="expenses[${idx}][payee_account_id]" class="form-control select2-js payee-select" id="payee${idx}" disabled>
                <option value="">—</option>${payeeOptions()}
            </select>
        </td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calcSummary();"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#expenseBody').append(row);
    $(`#expenseBody tr[data-erow="${idx}"] .select2-js`).select2({ width: '100%' });
}

function togglePayee(idx) {
    const $row = $(`#expenseBody tr[data-erow="${idx}"]`);
    const paidBy = $row.find('.paid-by').val();
    const $payee = $(`#payee${idx}`);
    if (paidBy === 'company') {
        $payee.prop('disabled', false);
    } else {
        $payee.prop('disabled', true).val('').trigger('change.select2');
    }
}

function calcSummary() {
    let qty = 0, netWeight = 0, grossWeight = 0, itemAmount = 0, expenseAmount = 0;

    $('#itemBody tr').each(function () {
        qty += parseFloat($(this).find('.qty').val()) || 0;
        grossWeight += parseFloat($(this).find('.gross-weight').val()) || 0;
        netWeight += parseFloat($(this).find('.net-weight').val()) || parseFloat($(this).find('.gross-weight').val()) || 0;
        itemAmount += parseFloat($(this).find('.amount').val()) || 0;
    });

    $('#expenseBody tr').each(function () {
        expenseAmount += parseFloat($(this).find('.exp-amount').val()) || 0;
    });

    const billAmount = itemAmount + expenseAmount;

    $('#sumItemAmount').text(itemAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumExpenseAmount').text(expenseAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumGrossWeight').text(grossWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumNetWeight').text(netWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumBillAmount').text(billAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    addItemRow();

    function togglePaymentTermDays() {
        if ($('#paymentTerms').val() === 'credit') {
            $('#creditDaysWrap').show();
            $('#creditDays').prop('required', true);
        } else {
            $('#creditDaysWrap').hide();
            $('#creditDays').prop('required', false).val('');
        }
    }
    $('#paymentTerms').on('change', togglePaymentTermDays);
    togglePaymentTermDays();

    $('#purchaseForm').on('submit', function () {
        $('#saveBtn').prop('disabled', true).text('Saving...');
    });
});
</script>
@endsection