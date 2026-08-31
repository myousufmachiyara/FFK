@extends('layouts.app')

@section('title', 'Edit Commission Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
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
            <div class="col-md-2">
              <label>Invoice Date</label>
              <input type="date" name="invoice_date" class="form-control" value="{{ $invoice->invoice_date->format('Y-m-d') }}" required>
            </div>
            <div class="col-md-2">
              <label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                @foreach($vendors as $v)<option value="{{ $v->id }}" {{ $invoice->vendor_id == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Customer</label>
              <select name="customer_id" class="form-control select2-js" required>
                @foreach($customers as $c)<option value="{{ $c->id }}" {{ $invoice->customer_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Transport Name</label>
              <input type="text" name="transport_name" class="form-control" value="{{ $invoice->transport_name }}">
            </div>
            <div class="col-md-2">
              <label>Bilty #</label>
              <input type="text" name="bilty_no" class="form-control" value="{{ $invoice->bilty_no }}">
            </div>
            <div class="col-md-2">
              <label>Vendor Bill #</label>
              <input type="text" name="vendor_bill_no" class="form-control" value="{{ $invoice->vendor_bill_no }}">
            </div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3">
              <label>Reference #</label>
              <input type="text" name="ref_no" class="form-control" value="{{ $invoice->ref_no }}">
            </div>
            <div class="col-md-9">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ $invoice->remarks }}">
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Items</h2></header>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered" id="itemTable">
              <thead>
                <tr>
                  <th width="16%">Product</th><th width="12%">Variation</th><th width="8%">Unit</th>
                  <th width="7%">Qty</th><th width="7%">Weight</th><th width="9%">Purchase Price</th>
                  <th width="9%">Sale Price</th><th width="7%">Comm %</th><th width="9%">Comm Amt</th>
                  <th width="9%">Purchase Total</th><th width="9%">Sale Total</th><th width="30px"></th>
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
            <table class="table table-bordered" id="expenseTable">
              <thead><tr><th width="25%">Type</th><th width="50%">Description</th><th width="20%">Amount</th><th width="30px"></th></tr></thead>
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
            <div class="col"><small class="text-muted d-block">Total Qty</small><strong id="sumQty">0</strong></div>
            <div class="col"><small class="text-muted d-block">Total Weight</small><strong id="sumWeight">0</strong></div>
            <div class="col"><small class="text-muted d-block">Total Purchase</small><strong id="sumPurchase">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Sale</small><strong id="sumSale">0.00</strong></div>
            <div class="col"><small class="text-muted d-block">Total Commission</small><strong id="sumCommission">0.00</strong></div>
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
let existingItems = @json($invoice->items);
let existingExpenses = @json($invoice->expenses);
let itemIdx = 0;
let expenseIdx = 0;

function unitOptions(selectedId) {
    return units.map(u => `<option value="${u.id}" ${u.id == selectedId ? 'selected' : ''}>${u.name} (${u.shortcode ?? ''})</option>`).join('');
}
function productOptions(selectedId) {
    return products.map(p => `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${p.name}</option>`).join('');
}

function addItemRow(existing = null) {
    const idx = itemIdx++;
    const qty = existing ? existing.quantity : '';
    const weight = existing ? existing.weight : '';
    const pprice = existing ? existing.purchase_price : '';
    const sprice = existing ? existing.sale_price : '';
    const cpct = existing ? existing.commission_percentage : '';
    const productId = existing ? existing.product_id : '';

    const row = `
    <tr data-row="${idx}">
        <td>
            <select name="items[${idx}][product_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required>
                <option value="">Select Product</option>${productOptions(productId)}
            </select>
        </td>
        <td>
            <select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}">
                <option value="">—</option>
            </select>
        </td>
        <td>
            <select name="items[${idx}][unit_id]" class="form-control select2-js">
                <option value="">—</option>${unitOptions(existing ? existing.unit_id : null)}
            </select>
        </td>
        <td><input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" value="${qty}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][weight]" class="form-control weight" value="${weight}" oninput="calcRow(${idx})"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][purchase_price]" class="form-control pprice" value="${pprice}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][sale_price]" class="form-control sprice" value="${sprice}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" max="100" name="items[${idx}][commission_percentage]" class="form-control cpct" value="${cpct}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control camt" readonly value="0.00"></td>
        <td><input type="text" class="form-control ptotal" readonly value="0.00"></td>
        <td><input type="text" class="form-control stotal" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#itemBody').append(row);
    $(`#itemBody tr[data-row="${idx}"] .select2-js`).select2({ width: '100%' });

    if (existing && existing.variation_id) {
        loadVariationsForRow(productId, idx, existing.variation_id);
    }
    calcRow(idx);
}

function onProductChange(sel, idx) {
    loadVariationsForRow(sel.value, idx, null);
}

function loadVariationsForRow(productId, idx, selectedVariationId) {
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
            variations.forEach(v => {
                const sel = (selectedVariationId == v.id) ? 'selected' : '';
                html += `<option value="${v.id}" ${sel}>${v.sku}</option>`;
            });
            variationSelect.html(html).trigger('change.select2');
        })
        .catch(() => variationSelect.html('<option value="">Error loading</option>').trigger('change.select2'));
}

function calcRow(idx) {
    const $row = $(`#itemBody tr[data-row="${idx}"]`);
    const qty = parseFloat($row.find('.qty').val()) || 0;
    const pprice = parseFloat($row.find('.pprice').val()) || 0;
    const sprice = parseFloat($row.find('.sprice').val()) || 0;
    const cpct = parseFloat($row.find('.cpct').val()) || 0;

    const ptotal = qty * pprice;
    const stotal = qty * sprice;
    const camt = stotal * cpct / 100;

    $row.find('.ptotal').val(ptotal.toFixed(2));
    $row.find('.stotal').val(stotal.toFixed(2));
    $row.find('.camt').val(camt.toFixed(2));

    calcSummary();
}

function addExpenseRow(existing = null) {
    const idx = expenseIdx++;
    const type = existing ? existing.expense_type : 'packing';
    const desc = existing ? existing.description : '';
    const amount = existing ? existing.amount : '';

    const row = `
    <tr data-erow="${idx}">
        <td>
            <select name="expenses[${idx}][expense_type]" class="form-control">
                <option value="packing" ${type === 'packing' ? 'selected' : ''}>Packing</option>
                <option value="local_cartage" ${type === 'local_cartage' ? 'selected' : ''}>Local Cartage</option>
                <option value="misc" ${type === 'misc' ? 'selected' : ''}>Miscellaneous</option>
            </select>
        </td>
        <td><input type="text" name="expenses[${idx}][description]" class="form-control" value="${desc ?? ''}"></td>
        <td><input type="number" step="any" min="0" name="expenses[${idx}][amount]" class="form-control exp-amount" value="${amount}" oninput="calcSummary()"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#expenseBody').append(row);
}

function removeRow(btn) {
    $(btn).closest('tr').remove();
    calcSummary();
}

function calcSummary() {
    let qty = 0, weight = 0, purchase = 0, sale = 0, commission = 0, expenses = 0;

    $('#itemBody tr').each(function () {
        qty += parseFloat($(this).find('.qty').val()) || 0;
        weight += parseFloat($(this).find('.weight').val()) || 0;
        purchase += parseFloat($(this).find('.ptotal').val()) || 0;
        sale += parseFloat($(this).find('.stotal').val()) || 0;
        commission += parseFloat($(this).find('.camt').val()) || 0;
    });

    $('#expenseBody tr').each(function () {
        expenses += parseFloat($(this).find('.exp-amount').val()) || 0;
    });

    $('#sumQty').text(qty.toFixed(2));
    $('#sumWeight').text(weight.toFixed(2));
    $('#sumPurchase').text(purchase.toFixed(2));
    $('#sumSale').text(sale.toFixed(2));
    $('#sumCommission').text(commission.toFixed(2));
    $('#sumExpenses').text(expenses.toFixed(2));
    $('#sumVendorPayable').text(purchase.toFixed(2));
    $('#sumCustomerReceivable').text((sale + expenses).toFixed(2));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    if (existingItems.length) {
        existingItems.forEach(item => addItemRow(item));
    } else {
        addItemRow();
    }

    if (existingExpenses.length) {
        existingExpenses.forEach(exp => addExpenseRow(exp));
    }

    calcSummary();

    $('#commissionEditForm').on('submit', function () {
        $('#updateBtn').prop('disabled', true).text('Updating...');
    });
});
</script>
@endsection
