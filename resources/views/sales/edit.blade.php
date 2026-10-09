@extends('layouts.app')

@section('title', 'Edit Sale Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
    #itemTable th, #expenseTable th { background: #f8f9fa; font-size: 12px; }
    #itemTable td, #expenseTable td { vertical-align: middle; }
    .readonly-calc { background-color: #f0f0f0 !important; }
</style>

<div class="row">
  <form id="saleInvoiceEditForm" action="{{ route('sale_invoices.update', $invoice->id) }}" method="POST" onkeydown="return event.key != 'Enter';">
    @csrf
    @method('PUT')

    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Edit Sale Invoice: #{{ $invoice->invoice_no }}</h2></header>
        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
          @endif

          <div class="row mb-2">
            <div class="col-md-2">
              <label>Invoice #</label>
              <input type="text" class="form-control" value="{{ $invoice->invoice_no }}" readonly/>
            </div>
            <div class="col-md-2">
              <label>Date</label>
              <input type="date" name="date" class="form-control" value="{{ $invoice->date->format('Y-m-d') }}" required />
            </div>
            <div class="col-md-3">
              <label>Customer</label>
              <select name="account_id" class="form-control select2-js" required>
                @foreach($customers as $acc)
                  <option value="{{ $acc->id }}" {{ $invoice->account_id == $acc->id ? 'selected' : '' }}>{{ $acc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Payment Terms</label>
              <select name="type" id="invoice_type" class="form-control" required>
                <option value="cash" {{ $invoice->type == 'cash' ? 'selected' : '' }}>Cash</option>
                <option value="credit" {{ $invoice->type == 'credit' ? 'selected' : '' }}>Credit</option>
              </select>
            </div>
            <div class="col-md-2" id="creditDaysWrap" style="display:none;">
              <label>Credit Days</label>
              <input type="number" min="1" name="credit_days" id="creditDays" class="form-control" value="{{ $invoice->credit_days }}">
            </div>
            <div class="col-md-2">
              <label>Bilti #</label>
              <input type="text" name="bilty_no" class="form-control" value="{{ $invoice->bilty_no }}">
            </div>
            <div class="col-md-3">
              <label>Transport Name</label>
              <input type="text" name="transport_name" class="form-control" value="{{ $invoice->transport_name }}">
            </div>
          </div>
          <div class="row">
            <div class="col-md-12">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ $invoice->remarks }}">
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Items</h2></header>
        <div class="card-body">
          <table class="table table-bordered table-sm" id="itemTable" style="table-layout: fixed; width: 100%;">
            <thead>
              <tr>
                <th width="14%">Item</th><th width="9%">Variation</th><th width="8%">Packing</th>
                <th width="7%">Wt./Packing (kg)</th><th width="5%">Qty</th>
                <th width="8%">Gross Weight</th><th width="8%">Net Weight</th>
                <th width="10%">Rate (40 kg)</th><th width="9%">Rate (kg)</th>
                <th width="11%">Total</th><th width="30px"></th>
              </tr>
            </thead>
            <tbody id="itemBody"></tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()">+ Add Item</button>
        </div>
      </section>
    </div>

    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Other Expenses</h2></header>
        <div class="card-body">
          <table class="table table-bordered table-sm" id="expenseTable">
            <thead>
              <tr>
                <th width="15%">Type</th><th width="27%">Description</th><th width="13%">Amount</th>
                <th width="15%">Paid By</th><th width="22%">Payee Account</th><th width="30px"></th>
              </tr>
            </thead>
            <tbody id="expenseBody"></tbody>
          </table>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addExpenseRow()"><i class="fas fa-plus"></i> Add Expense</button>
        </div>
      </section>
    </div>

    <div class="col-12">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Sale Invoice Summary</h2></header>
        <div class="card-body">
          <div class="row text-center mb-3">
            <div class="col"><small class="text-muted d-block">Total Item Amount</small><strong id="sumItemAmount">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Expense Amount</small><strong id="sumExpenseAmount">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Gross Wt. (kg)</small><strong id="sumGrossWeight">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Net Wt. (kg)</small><strong id="sumNetWeight">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Bill Amount</small><strong class="text-danger" id="sumBillAmount">0.00</strong></div>
          </div>

          <div class="mt-2 p-2 bg-light border rounded d-inline-block">
            <small class="text-muted d-block">Already Received (all-time):</small>
            <strong class="text-success">PKR {{ number_format($amountReceived, 2) }}</strong>
            <input type="hidden" id="amountReceivedHidden" value="{{ $amountReceived }}">
          </div>

          <hr>
          <div class="row p-3" style="background-color: #e7f3ff; border-radius: 5px; border: 1px solid #b8daff;">
            <div class="col-md-12"><h5><i class="fas fa-plus-circle"></i> Add New Payment (Optional)</h5>
              <small class="text-muted">Added on top of what's already been received — does not replace it.</small>
            </div>
            <div class="col-md-6">
              <label>Receive In (Cash/Bank)</label>
              <select name="payment_account_id" class="form-control select2-js">
                <option value="">-- No New Payment --</option>
                @foreach($paymentAccounts as $pa)
                  <option value="{{ $pa->id }}">{{ $pa->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label>New Amount Received Now</label>
              <input type="number" name="amount_received" id="amountReceived" class="form-control" step="any" value="0">
            </div>
          </div>
          <div class="text-end mt-2">
            <label class="text-danger"><strong>Remaining Balance</strong></label>
            <h4 class="text-danger mt-0">PKR <span id="balanceAmountText">0.00</span></h4>
          </div>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" id="updateBtn" class="btn btn-primary btn-lg">Update Invoice</button>
        </footer>
      </section>
    </div>
  </form>
</div>

<script>
let products = @json($products);
let units = @json($units);
let payeeAccounts = @json($payeeAccounts);
let existingItems = @json($invoice->items);
let existingExpenses = @json($invoice->expenses);
const KG_PER_MAUND = {{ $kgPerMaund }};
let itemIdx = 0;
let expenseIdx = 0;

function productOptions(sel) { return products.map(p => `<option value="${p.id}" data-stock="${p.computed_stock ?? 0}" data-unit="${p.measurement_unit ?? ''}" ${p.id == sel ? 'selected' : ''}>${p.name}</option>`).join(''); }
function unitOptions(sel) { return units.map(u => `<option value="${u.id}" ${u.id == sel ? 'selected' : ''}>${u.name}</option>`).join(''); }
function payeeOptions(sel) { return payeeAccounts.map(a => `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.name}</option>`).join(''); }

function addItemRow(existing = null) {
    const idx = itemIdx++;
    const productId = existing ? existing.product_id : '';
    const wtPacking = existing ? existing.wt_per_packing : '';
    const qty = existing ? existing.quantity : '';
    const netWeight = existing ? existing.net_weight : '';
    const rate40 = existing ? existing.rate_per_40kg : '';
    const rateKgVal = existing ? existing.sale_price : '';
    const packingUnitId = existing ? existing.packing_unit_id : null;

    const row = `
    <tr data-row="${idx}">
        <td><select name="items[${idx}][product_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required>
            <option value="">Select Item</option>${productOptions(productId)}
        </select></td>
        <td><select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}">
            <option value="">—</option>
        </select></td>
        <td><select name="items[${idx}][packing_unit_id]" class="form-control select2-js" id="unit${idx}">
            <option value="">—</option>${unitOptions(packingUnitId)}
        </select></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][wt_per_packing]" class="form-control wt-packing" value="${wtPacking}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" value="${qty}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc gross-weight" readonly value="0.00"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][net_weight]" class="form-control net-weight" value="${netWeight}" placeholder="= gross wt" oninput="calcRow(${idx})"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][rate_per_40kg]" class="form-control rate-40kg" value="${rate40}" oninput="onRateInput(${idx}, 'maund')" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][rate_per_kg]" class="form-control rate-kg" value="${rateKgVal}" oninput="onRateInput(${idx}, 'kg')"></td>
        <td><input type="text" class="form-control readonly-calc total" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#itemBody').append(row);
    $(`#itemBody tr[data-row="${idx}"] .select2-js`).select2({ width: '100%' });

    // FIX: previously only loaded variations here if existing.variation_id
    // was set — an existing line with NO variation never got its stock
    // label initialized on page open at all, only after the product
    // dropdown was manually touched. Now always loads when there's a
    // product, same as a fresh row would.
    if (existing && productId) {
        loadVariationsForRow(productId, idx, existing.variation_id || null);
    }
    calcRow(idx);
}

function onProductChange(sel, idx) {
    const defaultUnit = $(sel).find('option:selected').data('unit');
    if (defaultUnit) {
        $(`#unit${idx}`).val(defaultUnit).trigger('change.select2');
    }
    loadVariationsForRow(sel.value, idx, null);
}

function loadVariationsForRow(productId, idx, selectedId) {
    const variationSelect = $(`#variation${idx}`);
    $(`#noVariationLabel${idx}`).remove();

    if (!productId) {
        showVariationDropdown(variationSelect);
        variationSelect.html('<option value="">—</option>').trigger('change.select2');
        return;
    }
    showVariationDropdown(variationSelect);
    variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');
    fetch(`/product/${productId}/variations?exclude_invoice={{ $invoice->id }}`)
        .then(res => res.json())
        .then(data => {
            const variations = data.variation || data.variations || [];

            // A single entry with id === null is the pseudo-variation the
            // backend returns for a product with no real variations — show
            // its stock directly instead of a dropdown with one
            // meaningless option, since there's nothing to actually
            // choose between.
            const isNoVariation = variations.length === 1 && variations[0].id === null;

            if (isNoVariation) {
                const v = variations[0];
                const stock = v.available_stock ?? v.stock_quantity ?? 0;
                const stockWt = v.available_weight ?? v.stock_weight ?? 0;

                // Keep the select itself intact (with stock data on its
                // one option) so anything reading .variation-select
                // option:selected data-stock keeps working unchanged —
                // only the visible dropdown is hidden.
                variationSelect.html(`<option value="none" data-stock="${stock}" data-weight="${stockWt}" selected></option>`);
                hideVariationDropdown(variationSelect);
                variationSelect.after(
                    `<div id="noVariationLabel${idx}" class="form-control-plaintext small text-muted">No Variation — Stock: ${stock} bags / ${stockWt} kg</div>`
                );
            } else {
                let html = '<option value="">—</option>';
                variations.forEach(v => {
                    const sel = (selectedId == v.id) ? 'selected' : '';
                    const stock = v.stock_quantity ?? 0;
                    const stockWt = v.stock_weight ?? 0;
                    html += `<option value="${v.id}" data-stock="${stock}" ${sel}>${v.sku} (Stock: ${stock} bags / ${stockWt} kg)</option>`;
                });
                variationSelect.html(html).trigger('change.select2');
            }
        })
        .catch(() => variationSelect.html('<option value="">Error loading</option>').trigger('change.select2'));
}

// Hides the select2-rendered dropdown while keeping the underlying
// <select> in the DOM (destroying select2 first, since a hidden select2
// container can otherwise still intercept layout/clicks).
function hideVariationDropdown($select) {
    if ($select.hasClass('select2-hidden-accessible')) {
        $select.select2('destroy');
    }
    // disabled (not just hidden) so this field is never actually
    // submitted with the form — a plain .hide() alone would still POST
    // its value, which would send "none" as variation_id and fail the
    // exists:product_variations,id validation on the backend.
    $select.prop('disabled', true).hide();
}

function showVariationDropdown($select) {
    $select.prop('disabled', false).show();
    if (!$select.hasClass('select2-hidden-accessible')) {
        $select.select2({ width: '100%' });
    }
}


// ── Two-way rate entry ───────────────────────────────────────────────
// Type into either the 40 kg box or the per-kg box; the other one fills
// itself in. Per-kg is what the line total is actually calculated from,
// so typing it directly is never rounded away by a round-trip.
function linkRate($row, sel40, selKg, source) {
    const $r40 = $row.find(sel40);
    const $rKg = $row.find(selKg);

    if (source === 'kg') {
        const kg = parseFloat($rKg.val());
        $r40.val(isNaN(kg) ? '' : +(kg * KG_PER_MAUND).toFixed(2));
    } else {
        const r40 = parseFloat($r40.val());
        $rKg.val((isNaN(r40) || KG_PER_MAUND <= 0) ? '' : +(r40 / KG_PER_MAUND).toFixed(4));
    }
}

function onRateInput(idx, source) {
    linkRate($(`#itemBody tr[data-row="${idx}"]`), '.rate-40kg', '.rate-kg', source);
    calcRow(idx);
}

function calcRow(idx) {
    const $row = $(`#itemBody tr[data-row="${idx}"]`);
    const wtPacking = parseFloat($row.find('.wt-packing').val()) || 0;
    const qty = parseFloat($row.find('.qty').val()) || 0;
    const rateKg = parseFloat($row.find('.rate-kg').val()) || 0;
    let netInput = $row.find('.net-weight').val();

    const grossWeight = wtPacking * qty;
    const netWeight = (netInput !== '' && !isNaN(parseFloat(netInput))) ? parseFloat(netInput) : grossWeight;
    const total = rateKg * netWeight;

    $row.find('.gross-weight').val(grossWeight.toFixed(2));
    $row.find('.total').val(total.toFixed(2));

    calcSummary();
}

function removeRow(btn) { $(btn).closest('tr').remove(); calcSummary(); }

function addExpenseRow(existing = null) {
    const idx = expenseIdx++;
    const type = existing ? existing.expense_type : 'local_cartage';
    const desc = existing ? existing.description : '';
    const amount = existing ? existing.amount : '';
    const paidBy = existing ? existing.paid_by : 'company';
    const payeeId = existing ? existing.payee_account_id : null;

    const row = `
    <tr data-erow="${idx}">
        <td><select name="expenses[${idx}][expense_type]" class="form-control">
            <option value="local_cartage" ${type==='local_cartage'?'selected':''}>Local Cartage</option>
            <option value="packaging" ${type==='packaging'?'selected':''}>Packaging</option>
            <option value="plastic_bags" ${type==='plastic_bags'?'selected':''}>Plastic Bags</option>
            <option value="bardana" ${type==='bardana'?'selected':''}>Bardana</option>
            <option value="misc" ${type==='misc'?'selected':''}>Miscellaneous</option>
            <option value="tulai" ${type==='tulai'?'selected':''}>Tulai</option>
            <option value="others" ${type==='others'?'selected':''}>Others</option>
        </select></td>
        <td><input type="text" name="expenses[${idx}][description]" class="form-control" value="${desc ?? ''}"></td>
        <td><input type="number" step="any" min="0" name="expenses[${idx}][amount]" class="form-control exp-amount" value="${amount}" oninput="calcSummary()"></td>
        <td><select name="expenses[${idx}][paid_by]" class="form-control exp-paid-by" onchange="togglePayee(this)">
            <option value="company" ${paidBy==='company'?'selected':''}>Company (FFK)</option>
            <option value="customer" ${paidBy==='customer'?'selected':''}>Customer</option>
            ${paidBy==='vendor'?'<option value="vendor" selected>Vendor (legacy)</option>':''}
        </select></td>
        <td class="payee-cell"><select name="expenses[${idx}][payee_account_id]" class="form-control select2-js payee-select" required>
            <option value="">Select Account</option>${payeeOptions(payeeId)}
        </select></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calcSummary();"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#expenseBody').append(row);
    $(`#expenseBody tr[data-erow="${idx}"] .select2-js`).select2({ width: '100%' });
    togglePayee($(`#expenseBody tr[data-erow="${idx}"] .exp-paid-by`)[0]);
}

// Customer-paid expenses need no account and post no voucher.
function togglePayee(el) {
    const $tr = $(el).closest('tr');
    const isCustomer = $(el).val() === 'customer';
    const $sel = $tr.find('.payee-select');
    if (isCustomer) {
        $sel.val('').trigger('change').prop('disabled', true).prop('required', false);
        $tr.find('.payee-cell .select2-container').hide();
        if (!$tr.find('.payee-na').length) $tr.find('.payee-cell').append('<span class="payee-na text-muted small">Not applicable</span>');
    } else {
        $sel.prop('disabled', false).prop('required', true);
        $tr.find('.payee-cell .select2-container').show();
        $tr.find('.payee-na').remove();
    }
    calcSummary();
}

function calcSummary() {
    let itemAmount = 0, grossWeight = 0, netWeight = 0, expenseAmount = 0;

    $('#itemBody tr').each(function () {
        itemAmount += parseFloat($(this).find('.total').val()) || 0;
        grossWeight += parseFloat($(this).find('.gross-weight').val()) || 0;
        netWeight += parseFloat($(this).find('.net-weight').val()) || parseFloat($(this).find('.gross-weight').val()) || 0;
    });

    $('#expenseBody tr').each(function () {
        expenseAmount += parseFloat($(this).find('.exp-amount').val()) || 0;
    });

    const billAmount = itemAmount + expenseAmount;
    const alreadyPaid = parseFloat($('#amountReceivedHidden').val()) || 0;
    const newPayment = parseFloat($('#amountReceived').val()) || 0;

    $('#sumItemAmount').text(itemAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumExpenseAmount').text(expenseAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumGrossWeight').text(grossWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumNetWeight').text(netWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumBillAmount').text(billAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));

    const balance = billAmount - alreadyPaid - newPayment;
    $('#balanceAmountText').text(balance.toLocaleString(undefined, { minimumFractionDigits: 2 }));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    if (existingItems.length) { existingItems.forEach(item => addItemRow(item)); } else { addItemRow(); }
    if (existingExpenses.length) { existingExpenses.forEach(exp => addExpenseRow(exp)); }

    $(document).on('input', '#amountReceived', calcSummary);

    function togglePaymentTermDays() {
        if ($('#invoice_type').val() === 'credit') { $('#creditDaysWrap').show(); }
        else { $('#creditDaysWrap').hide(); $('#creditDays').val(''); }
    }
    $('#invoice_type').on('change', togglePaymentTermDays);
    togglePaymentTermDays();

    calcSummary();

    $('#saleInvoiceEditForm').on('submit', function () {
        $('#updateBtn').prop('disabled', true).text('Updating...');
    });
});
</script>
@endsection