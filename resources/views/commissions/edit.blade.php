@extends('layouts.app')

@section('title', 'Edit Commission Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
    #itemTable th, #expenseTable th { background: #f8f9fa; font-size: 11px; }
    #itemTable td, #expenseTable td { vertical-align: middle; }
    .readonly-calc { background-color: #f0f0f0 !important; }
</style>

<div class="row">
  <form id="commissionEditForm" action="{{ route('commission_invoices.update', $invoice->id) }}" method="POST" onkeydown="return event.key != 'Enter';">
    @csrf
    @method('PUT')
    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Edit Commission Invoice: CI-{{ $invoice->invoice_no }}</h2>
          @if ($errors->any())
            <div class="alert alert-danger mt-2"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
          @endif
        </header>
        <div class="card-body">
          <div class="row mb-2">
            <div class="col-md-2"><label>Invoice Date</label><input type="date" name="invoice_date" class="form-control" value="{{ $invoice->invoice_date->format('Y-m-d') }}" required></div>
            <div class="col-md-2"><label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                @foreach($vendors as $v)<option value="{{ $v->id }}" {{ $invoice->vendor_id == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-2"><label>Customer</label>
              <select name="customer_id" class="form-control select2-js" required>
                @foreach($customers as $c)<option value="{{ $c->id }}" {{ $invoice->customer_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-2"><label>Transport</label><input type="text" name="transport_name" class="form-control" value="{{ $invoice->transport_name }}"></div>
            <div class="col-md-2"><label>Bilty #</label><input type="text" name="bilty_no" class="form-control" value="{{ $invoice->bilty_no }}"></div>
            <div class="col-md-2"><label>Vendor Bill #</label><input type="text" name="vendor_bill_no" class="form-control" value="{{ $invoice->vendor_bill_no }}"></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3"><label>Reference #</label><input type="text" name="ref_no" class="form-control" value="{{ $invoice->ref_no }}"></div>
            <div class="col-md-9"><label>Remarks</label><input type="text" name="remarks" class="form-control" value="{{ $invoice->remarks }}"></div>
          </div>
        </div>
      </section>
    </div>

    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Items</h2></header>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-sm" id="itemTable">
              <thead>
                <tr>
                  <th width="10%">Product</th><th width="7%">Variation</th><th width="6%">Packing</th>
                  <th width="6%">Wt/Pack</th><th width="5%">Qty</th><th width="6%">Net Wt</th>
                  <th width="7%">Pur Rate/{{ $kgPerMaund }}kg</th><th width="6%">Pur Rate/kg</th><th width="7%">Pur Total</th>
                  <th width="7%">Sale Rate/{{ $kgPerMaund }}kg</th><th width="6%">Sale Rate/kg</th><th width="7%">Sale Total</th>
                  <th width="6%">Vendor Comm %</th><th width="6%">Vendor Comm Amt</th>
                  <th width="6%">Cust Comm %</th><th width="6%">Cust Comm Amt</th>
                  <th width="25px"></th>
                </tr>
              </thead>
              <tbody id="itemBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addItemRow()"><i class="fas fa-plus"></i> Add Item</button>
        </div>
      </section>
    </div>

    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Other Expenses</h2></header>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-sm" id="expenseTable">
              <thead>
                <tr>
                  <th width="15%">Type</th><th width="25%">Description</th><th width="12%">Amount</th>
                  <th width="15%">Paid By</th><th width="23%">Payee Account</th><th width="10%"></th>
                </tr>
              </thead>
              <tbody id="expenseBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addExpenseRow()"><i class="fas fa-plus"></i> Add Expense</button>
        </div>
      </section>
    </div>

    <div class="col-12">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Summary</h2></header>
        <div class="card-body">
          <div class="row text-center">
            <div class="col"><small class="text-muted d-block">Total Weight</small><strong id="sumWeight">0</strong></div>
            <div class="col"><small class="text-muted d-block">Purchase Total</small><strong id="sumPurchase">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Sale Total</small><strong id="sumSale">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Vendor Commission</small><strong id="sumVendorComm">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Customer Commission</small><strong id="sumCustComm">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Other Expenses</small><strong id="sumExpenses">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Vendor Payable</small><strong class="text-danger" id="sumVendorPayable">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Customer Receivable</small><strong class="text-primary" id="sumCustomerReceivable">0.00</strong></div>
          </div>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('commission_invoices.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" id="updateBtn" class="btn btn-primary"><i class="fas fa-save"></i> Update Invoice</button>
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

function productOptions(sel) { return products.map(p => `<option value="${p.id}" ${p.id == sel ? 'selected' : ''}>${p.name}</option>`).join(''); }
function unitOptions(sel) { return units.map(u => `<option value="${u.id}" ${u.id == sel ? 'selected' : ''}>${u.name}</option>`).join(''); }
function payeeOptions(sel) { return payeeAccounts.map(a => `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.name}</option>`).join(''); }

function addItemRow(existing = null) {
    const idx = itemIdx++;
    const productId = existing ? existing.product_id : '';
    const packingId = existing ? existing.packing_unit_id : null;
    const v = (k, d = '') => existing ? existing[k] : d;

    const row = `
    <tr data-row="${idx}">
        <td><select name="items[${idx}][product_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required><option value="">Select</option>${productOptions(productId)}</select></td>
        <td><select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}"><option value="">—</option></select></td>
        <td><select name="items[${idx}][packing_unit_id]" class="form-control select2-js"><option value="">—</option>${unitOptions(packingId)}</select></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][wt_per_packing]" class="form-control wt-packing" value="${v('wt_per_packing')}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" value="${v('quantity')}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][net_weight]" class="form-control net-weight" value="${v('net_weight')}" placeholder="auto" oninput="calcRow(${idx})"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][purchase_rate_per_40kg]" class="form-control pur-rate40" value="${v('purchase_rate_per_40kg')}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc pur-rate-kg" readonly value="0.0000"></td>
        <td><input type="text" class="form-control readonly-calc pur-total" readonly value="0.00"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][sale_rate_per_40kg]" class="form-control sale-rate40" value="${v('sale_rate_per_40kg')}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc sale-rate-kg" readonly value="0.0000"></td>
        <td><input type="text" class="form-control readonly-calc sale-total" readonly value="0.00"></td>
        <td><input type="number" step="any" min="0" max="100" name="items[${idx}][vendor_commission_percentage]" class="form-control vendor-comm-pct" value="${v('vendor_commission_percentage', 0)}" oninput="calcRow(${idx})"></td>
        <td><input type="text" class="form-control readonly-calc vendor-comm-amt" readonly value="0.00"></td>
        <td><input type="number" step="any" min="0" max="100" name="items[${idx}][customer_commission_percentage]" class="form-control cust-comm-pct" value="${v('customer_commission_percentage', 0)}" oninput="calcRow(${idx})"></td>
        <td><input type="text" class="form-control readonly-calc cust-comm-amt" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#itemBody').append(row);
    $(`#itemBody tr[data-row="${idx}"] .select2-js`).select2({ width: '100%' });

    if (existing && existing.variation_id) {
        loadVariationsForRow(productId, idx, existing.variation_id);
    }
    calcRow(idx);
}

function onProductChange(sel, idx) { loadVariationsForRow(sel.value, idx, null); }

function loadVariationsForRow(productId, idx, selectedId) {
    const variationSelect = $(`#variation${idx}`);
    if (!productId) { variationSelect.html('<option value="">—</option>').trigger('change.select2'); return; }
    variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');
    fetch(`/product/${productId}/variations`).then(res => res.json()).then(data => {
        const variations = data.variation || data.variations || [];
        let html = '<option value="">—</option>';
        variations.forEach(v => { const sel = (selectedId == v.id) ? 'selected' : ''; html += `<option value="${v.id}" ${sel}>${v.sku}</option>`; });
        variationSelect.html(html).trigger('change.select2');
    }).catch(() => variationSelect.html('<option value="">Error</option>').trigger('change.select2'));
}

function calcRow(idx) {
    const $row = $(`#itemBody tr[data-row="${idx}"]`);
    const wtPacking = parseFloat($row.find('.wt-packing').val()) || 0;
    const qty = parseFloat($row.find('.qty').val()) || 0;
    const purRate40 = parseFloat($row.find('.pur-rate40').val()) || 0;
    const saleRate40 = parseFloat($row.find('.sale-rate40').val()) || 0;
    const vendorPct = parseFloat($row.find('.vendor-comm-pct').val()) || 0;
    const custPct = parseFloat($row.find('.cust-comm-pct').val()) || 0;
    let netInput = $row.find('.net-weight').val();

    const grossWeight = wtPacking * qty;
    const netWeight = (netInput !== '' && !isNaN(parseFloat(netInput))) ? parseFloat(netInput) : grossWeight;
    const purRateKg = KG_PER_MAUND > 0 ? (purRate40 / KG_PER_MAUND) : 0;
    const saleRateKg = KG_PER_MAUND > 0 ? (saleRate40 / KG_PER_MAUND) : 0;
    const purTotal = purRateKg * netWeight;
    const saleTotal = saleRateKg * netWeight;

    $row.find('.pur-rate-kg').val(purRateKg.toFixed(4));
    $row.find('.pur-total').val(purTotal.toFixed(2));
    $row.find('.sale-rate-kg').val(saleRateKg.toFixed(4));
    $row.find('.sale-total').val(saleTotal.toFixed(2));
    $row.find('.vendor-comm-amt').val((purTotal * vendorPct / 100).toFixed(2));
    $row.find('.cust-comm-amt').val((saleTotal * custPct / 100).toFixed(2));

    calcSummary();
}

function removeRow(btn) { $(btn).closest('tr').remove(); calcSummary(); }

function addExpenseRow(existing = null) {
    const idx = expenseIdx++;
    const type = existing ? existing.expense_type : 'packing';
    const desc = existing ? existing.description : '';
    const amount = existing ? existing.amount : '';
    const paidBy = existing ? existing.paid_by : 'vendor';
    const payeeId = existing ? existing.payee_account_id : null;

    const row = `
    <tr data-erow="${idx}">
        <td><select name="expenses[${idx}][expense_type]" class="form-control">
            <option value="packing" ${type==='packing'?'selected':''}>Packing</option>
            <option value="local_cartage" ${type==='local_cartage'?'selected':''}>Local Cartage</option>
            <option value="misc" ${type==='misc'?'selected':''}>Miscellaneous</option>
        </select></td>
        <td><input type="text" name="expenses[${idx}][description]" class="form-control" value="${desc ?? ''}"></td>
        <td><input type="number" step="any" min="0" name="expenses[${idx}][amount]" class="form-control exp-amount" value="${amount}" oninput="calcSummary()"></td>
        <td><select name="expenses[${idx}][paid_by]" class="form-control paid-by" onchange="togglePayee(${idx})">
            <option value="vendor" ${paidBy==='vendor'?'selected':''}>Vendor</option>
            <option value="company" ${paidBy==='company'?'selected':''}>Company (FFK)</option>
        </select></td>
        <td><select name="expenses[${idx}][payee_account_id]" class="form-control select2-js payee-select" id="payee${idx}" ${paidBy==='company'?'':'disabled'}>
            <option value="">—</option>${payeeOptions(payeeId)}
        </select></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calcSummary();"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#expenseBody').append(row);
    $(`#expenseBody tr[data-erow="${idx}"] .select2-js`).select2({ width: '100%' });
}

function togglePayee(idx) {
    const $row = $(`#expenseBody tr[data-erow="${idx}"]`);
    const paidBy = $row.find('.paid-by').val();
    const $payee = $(`#payee${idx}`);
    if (paidBy === 'company') { $payee.prop('disabled', false); }
    else { $payee.prop('disabled', true).val('').trigger('change.select2'); }
}

function calcSummary() {
    let weight = 0, purchase = 0, sale = 0, vendorComm = 0, custComm = 0, expenses = 0;
    $('#itemBody tr').each(function () {
        weight += parseFloat($(this).find('.net-weight').val()) || (parseFloat($(this).find('.wt-packing').val()) || 0) * (parseFloat($(this).find('.qty').val()) || 0);
        purchase += parseFloat($(this).find('.pur-total').val()) || 0;
        sale += parseFloat($(this).find('.sale-total').val()) || 0;
        vendorComm += parseFloat($(this).find('.vendor-comm-amt').val()) || 0;
        custComm += parseFloat($(this).find('.cust-comm-amt').val()) || 0;
    });
    $('#expenseBody tr').each(function () { expenses += parseFloat($(this).find('.exp-amount').val()) || 0; });

    $('#sumWeight').text(weight.toFixed(2));
    $('#sumPurchase').text(purchase.toFixed(2));
    $('#sumSale').text(sale.toFixed(2));
    $('#sumVendorComm').text(vendorComm.toFixed(2));
    $('#sumCustComm').text(custComm.toFixed(2));
    $('#sumExpenses').text(expenses.toFixed(2));
    $('#sumVendorPayable').text((purchase - vendorComm).toFixed(2));
    $('#sumCustomerReceivable').text((sale + expenses).toFixed(2));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    if (existingItems.length) { existingItems.forEach(item => addItemRow(item)); } else { addItemRow(); }
    if (existingExpenses.length) { existingExpenses.forEach(exp => addExpenseRow(exp)); }

    calcSummary();

    $('#commissionEditForm').on('submit', function () {
        $('#updateBtn').prop('disabled', true).text('Updating...');
    });
});
</script>
@endsection
