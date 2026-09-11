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
              <input type="date" name="date" class="form-control" value="{{ $invoice->date }}" required />
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
          <table class="table table-bordered table-sm" id="itemTable">
            <thead>
              <tr>
                <th width="14%">Item</th><th width="9%">Variation</th><th width="8%">Packing</th>
                <th width="7%">Wt./Packing (kg)</th><th width="5%">Qty</th>
                <th width="8%">Gross Weight</th><th width="8%">Net Weight</th>
                <th width="9%">Rate (40 kg)</th><th width="7%">Rate (kg)</th>
                <th width="6%">Disc %</th><th width="8%">Total</th><th width="30px"></th>
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
                <th width="20%">Type</th><th width="32%">Description</th><th width="16%">Amount</th>
                <th width="27%">Payable To (Vendor Account)</th><th width="30px"></th>
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
          <input type="hidden" name="discount" id="discountInput" value="{{ $invoice->discount }}">

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

function productOptions(sel) { return products.map(p => `<option value="${p.id}" data-stock="${p.computed_stock ?? 0}" ${p.id == sel ? 'selected' : ''}>${p.name}</option>`).join(''); }
function unitOptions(sel) { return units.map(u => `<option value="${u.id}" ${u.id == sel ? 'selected' : ''}>${u.name}</option>`).join(''); }
function payeeOptions(sel) { return payeeAccounts.map(a => `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.name}</option>`).join(''); }

function addItemRow(existing = null) {
    const idx = itemIdx++;
    const productId = existing ? existing.product_id : '';
    const wtPacking = existing ? existing.wt_per_packing : '';
    const qty = existing ? existing.quantity : '';
    const netWeight = existing ? existing.net_weight : '';
    const rate40 = existing ? existing.rate_per_40kg : '';
    const disc = existing ? existing.discount : 0;
    const packingUnitId = existing ? existing.packing_unit_id : null;

    const row = `
    <tr data-row="${idx}">
        <td><select name="items[${idx}][product_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required>
            <option value="">Select Item</option>${productOptions(productId)}
        </select></td>
        <td><select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}">
            <option value="">—</option>
        </select></td>
        <td><select name="items[${idx}][packing_unit_id]" class="form-control select2-js">
            <option value="">—</option>${unitOptions(packingUnitId)}
        </select></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][wt_per_packing]" class="form-control wt-packing" value="${wtPacking}" oninput="calcRow(${idx})" required></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" value="${qty}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc gross-weight" readonly value="0.00"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][net_weight]" class="form-control net-weight" value="${netWeight}" placeholder="= gross wt" oninput="calcRow(${idx})"></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][rate_per_40kg]" class="form-control rate-40kg" value="${rate40}" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc rate-kg" readonly value="0.0000"></td>
        <td><input type="number" step="any" min="0" max="100" name="items[${idx}][discount]" class="form-control disc-pct" value="${disc}" oninput="calcRow(${idx})"></td>
        <td><input type="text" class="form-control readonly-calc total" readonly value="0.00"></td>
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
    fetch(`/product/${productId}/variations`)
        .then(res => res.json())
        .then(data => {
            const variations = data.variation || data.variations || [];
            let html = '<option value="">—</option>';
            variations.forEach(v => {
                const sel = (selectedId == v.id) ? 'selected' : '';
                const stock = v.stock_quantity ?? 0;
                html += `<option value="${v.id}" data-stock="${stock}" ${sel}>${v.sku} (Stock: ${stock} kg)</option>`;
            });
            variationSelect.html(html).trigger('change.select2');
        })
        .catch(() => variationSelect.html('<option value="">Error loading</option>').trigger('change.select2'));
}

function calcRow(idx) {
    const $row = $(`#itemBody tr[data-row="${idx}"]`);
    const wtPacking = parseFloat($row.find('.wt-packing').val()) || 0;
    const qty = parseFloat($row.find('.qty').val()) || 0;
    const rate40 = parseFloat($row.find('.rate-40kg').val()) || 0;
    const discPct = parseFloat($row.find('.disc-pct').val()) || 0;
    let netInput = $row.find('.net-weight').val();

    const grossWeight = wtPacking * qty;
    const netWeight = (netInput !== '' && !isNaN(parseFloat(netInput))) ? parseFloat(netInput) : grossWeight;
    const rateKg = KG_PER_MAUND > 0 ? (rate40 / KG_PER_MAUND) : 0;
    const discountedRate = rateKg - (rateKg * discPct / 100);
    const total = discountedRate * netWeight;

    $row.find('.gross-weight').val(grossWeight.toFixed(2));
    $row.find('.rate-kg').val(rateKg.toFixed(4));
    $row.find('.total').val(total.toFixed(2));

    calcSummary();
}

function removeRow(btn) { $(btn).closest('tr').remove(); calcSummary(); }

function addExpenseRow(existing = null) {
    const idx = expenseIdx++;
    const type = existing ? existing.expense_type : 'local_cartage';
    const desc = existing ? existing.description : '';
    const amount = existing ? existing.amount : '';
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
        <td><select name="expenses[${idx}][payee_account_id]" class="form-control select2-js" required>
            <option value="">Select Vendor Account</option>${payeeOptions(payeeId)}
        </select></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calcSummary();"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#expenseBody').append(row);
    $(`#expenseBody tr[data-erow="${idx}"] .select2-js`).select2({ width: '100%' });
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