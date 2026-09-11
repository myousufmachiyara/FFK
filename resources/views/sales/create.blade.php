@extends('layouts.app')

@section('title', 'Create Sale Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single { height: 38px !important; padding: 5px; border: 1px solid #ced4da; }
    .select2-container { display: block !important; width: 100% !important; }
    #itemTable th, #expenseTable th { background: #f8f9fa; font-size: 12px; }
    #itemTable td, #expenseTable td { vertical-align: middle; }
    .readonly-calc { background-color: #f0f0f0 !important; }
    .stock-exceeded { border-color: red !important; }
    .stock-ok { border-color: #28a745 !important; }
</style>

<div class="row">
  <form id="saleInvoiceForm" action="{{ route('sale_invoices.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
    @csrf

    {{-- ═══════════════ MASTER SECTION ═══════════════ --}}
    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Create Sale Invoice</h2></header>
        <div class="card-body">
          @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
          @endif

          <div class="row mb-2">
            <div class="col-md-2">
              <label>Invoice #</label>
              <input type="text" class="form-control" readonly placeholder="Auto"/>
            </div>
            <div class="col-md-2">
              <label>Date</label>
              <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required />
            </div>
            <div class="col-md-3">
              <label>Customer</label>
              <select name="account_id" class="form-control select2-js" required>
                <option value="">Select Customer</option>
                @foreach($customers as $account)
                  <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Payment Terms</label>
              <select name="type" id="invoice_type" class="form-control" required>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
              </select>
            </div>
            <div class="col-md-2" id="creditDaysWrap" style="display:none;">
              <label>Credit Days</label>
              <input type="number" min="1" name="credit_days" id="creditDays" class="form-control" placeholder="e.g. 30">
            </div>
          </div>
          <div class="row">
            <div class="col-md-12">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>
        </div>
      </section>
    </div>

    {{-- ═══════════════ ITEMS GRID SECTION ═══════════════ --}}
    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Items</h2></header>
        <div class="card-body">
          <table class="table table-bordered table-sm" id="itemTable">
            <thead>
              <tr>
                <th width="14%">Item</th>
                <th width="9%">Variation</th>
                <th width="8%">Packing</th>
                <th width="7%">Wt./Packing (kg)</th>
                <th width="5%">Qty</th>
                <th width="8%">Gross Weight</th>
                <th width="8%">Net Weight</th>
                <th width="9%">Rate (40 kg)</th>
                <th width="7%">Rate (kg)</th>
                <th width="6%">Disc %</th>
                <th width="8%">Total</th>
                <th width="30px"></th>
              </tr>
            </thead>
            <tbody id="itemBody"></tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()">+ Add Item</button>
        </div>
      </section>
    </div>

    {{-- ═══════════════ EXTRA EXPENSES SECTION ═══════════════ --}}
    <div class="col-12 mb-3">
      <section class="card">
        <header class="card-header"><h2 class="card-title">Other Expenses</h2></header>
        <div class="card-body">
          <table class="table table-bordered table-sm" id="expenseTable">
            <thead>
              <tr>
                <th width="20%">Type</th>
                <th width="32%">Description</th>
                <th width="16%">Amount</th>
                <th width="27%">Payable To (Vendor Account)</th>
                <th width="30px"></th>
              </tr>
            </thead>
            <tbody id="expenseBody"></tbody>
          </table>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addExpenseRow()"><i class="fas fa-plus"></i> Add Expense</button>
          <p class="text-muted small mt-2 mb-0">
            <i class="fas fa-info-circle"></i> Every expense is always added to the Customer's receivable.
            FFK pays it on the customer's behalf — pick which Vendor account we owe (e.g. a specific transporter).
          </p>
        </div>
      </section>
    </div>

    {{-- ═══════════════ SUMMARY + PAYMENT SECTION ═══════════════ --}}
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
          <input type="hidden" name="discount" id="discountInput" value="0">

          <hr>
          <div class="row mb-2">
            <div class="col-md-4">
              <label><strong>Receive Payment To:</strong></label>
              <select name="payment_account_id" class="form-control select2-js">
                <option value="">No Payment (Credit Sale)</option>
                @foreach($paymentAccounts as $pAc)
                  <option value="{{ $pAc->id }}">{{ $pAc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Amount Received</label>
              <input type="number" name="amount_received" id="amountReceived" class="form-control" step="any" value="0">
            </div>
            <div class="col-md-5 text-end">
              <label>Remaining Balance</label>
              <h4 class="text-danger mt-0">PKR <span id="balanceAmountText">0.00</span></h4>
            </div>
          </div>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" id="saveBtn" class="btn btn-primary">Save Invoice</button>
        </footer>
      </section>
    </div>
  </form>
</div>

<script>
let products = @json($products);
let units = @json($units);
let payeeAccounts = @json($payeeAccounts);
const KG_PER_MAUND = {{ $kgPerMaund }};
let itemIdx = 0;
let expenseIdx = 0;

function productOptions() { return products.map(p => `<option value="${p.id}" data-stock="${p.computed_stock ?? 0}">${p.name}</option>`).join(''); }
function unitOptions() { return units.map(u => `<option value="${u.id}">${u.name}</option>`).join(''); }
function payeeOptions() { return payeeAccounts.map(a => `<option value="${a.id}">${a.name}</option>`).join(''); }

function addItemRow() {
    const idx = itemIdx++;
    const row = `
    <tr data-row="${idx}">
        <td><select name="items[${idx}][product_id]" class="form-control select2-js product-select" onchange="onProductChange(this, ${idx})" required>
            <option value="">Select Item</option>${productOptions()}
        </select></td>
        <td><select name="items[${idx}][variation_id]" class="form-control select2-js variation-select" id="variation${idx}">
            <option value="">—</option>
        </select></td>
        <td><select name="items[${idx}][packing_unit_id]" class="form-control select2-js">
            <option value="">—</option>${unitOptions()}
        </select></td>
        <td><input type="number" step="any" min="0" name="items[${idx}][wt_per_packing]" class="form-control wt-packing" oninput="calcRow(${idx})" required></td>
        <td>
          <input type="number" step="any" min="0" name="items[${idx}][quantity]" class="form-control qty" oninput="calcRow(${idx})" required>
          <small class="text-muted stock-hint" id="stockHint${idx}"></small>
        </td>
        <td><input type="text" class="form-control readonly-calc gross-weight" readonly value="0.00"></td>
        <td>
          <input type="number" step="any" min="0" name="items[${idx}][net_weight]" class="form-control net-weight" placeholder="= gross wt" oninput="calcRow(${idx})">
        </td>
        <td><input type="number" step="any" min="0" name="items[${idx}][rate_per_40kg]" class="form-control rate-40kg" oninput="calcRow(${idx})" required></td>
        <td><input type="text" class="form-control readonly-calc rate-kg" readonly value="0.0000"></td>
        <td><input type="number" step="any" min="0" max="100" name="items[${idx}][discount]" class="form-control disc-pct" value="0" oninput="calcRow(${idx})"></td>
        <td><input type="text" class="form-control readonly-calc total" readonly value="0.00"></td>
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
            variations.forEach(v => {
                const stock = v.stock_quantity ?? 0;
                const stockWt = v.stock_weight ?? 0;
                html += `<option value="${v.id}" data-stock="${stock}">${v.sku} (Stock: ${stock} bags / ${stockWt} kg)</option>`;
            });
            variationSelect.html(html).trigger('change.select2');
            calcRow(idx);
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

    const $variationSelect = $row.find('.variation-select');
    const $selectedVariation = $variationSelect.find('option:selected');
    const variationStock = parseFloat($selectedVariation.data('stock'));
    const stock = (!isNaN(variationStock) && $selectedVariation.val()) ? variationStock : 0;
    const $hint = $(`#stockHint${idx}`);
    const qtyInput = $row.find('.qty');

    if (qty > stock) {
        qtyInput.addClass('stock-exceeded').removeClass('stock-ok');
        $hint.text('⚠ Only ' + stock + ' bags available').css('color', 'red');
    } else {
        qtyInput.addClass('stock-ok').removeClass('stock-exceeded');
        $hint.text('In stock: ' + stock + ' bags').css('color', '#28a745');
    }

    calcSummary();
}

function removeRow(btn) { $(btn).closest('tr').remove(); calcSummary(); }

function addExpenseRow() {
    const idx = expenseIdx++;
    const row = `
    <tr data-erow="${idx}">
        <td><select name="expenses[${idx}][expense_type]" class="form-control">
            <option value="local_cartage">Local Cartage</option>
            <option value="packaging">Packaging</option>
            <option value="plastic_bags">Plastic Bags</option>
            <option value="bardana">Bardana</option>
            <option value="misc">Miscellaneous</option>
            <option value="tulai">Tulai</option>
            <option value="others">Others</option>
        </select></td>
        <td><input type="text" name="expenses[${idx}][description]" class="form-control"></td>
        <td><input type="number" step="any" min="0" name="expenses[${idx}][amount]" class="form-control exp-amount" oninput="calcSummary()"></td>
        <td><select name="expenses[${idx}][payee_account_id]" class="form-control select2-js" required>
            <option value="">Select Vendor Account</option>${payeeOptions()}
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

    $('#sumItemAmount').text(itemAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumExpenseAmount').text(expenseAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumGrossWeight').text(grossWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumNetWeight').text(netWeight.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#sumBillAmount').text(billAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));

    const received = parseFloat($('#amountReceived').val()) || 0;
    const balance = billAmount - received;
    $('#balanceAmountText').text(balance.toLocaleString(undefined, { minimumFractionDigits: 2 }));
}

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    addItemRow();

    $(document).on('input', '#amountReceived', calcSummary);

    $(document).on('change', '#invoice_type', function () {
        if ($(this).val() === 'cash') {
            const bill = parseFloat($('#sumBillAmount').text().replace(/,/g, '')) || 0;
            $('#amountReceived').val(bill.toFixed(2));
        } else {
            $('#amountReceived').val(0);
        }
        calcSummary();
    });

    function togglePaymentTermDays() {
        if ($('#invoice_type').val() === 'credit') {
            $('#creditDaysWrap').show();
            $('#creditDays').prop('required', true);
        } else {
            $('#creditDaysWrap').hide();
            $('#creditDays').prop('required', false).val('');
        }
    }
    $('#invoice_type').on('change', togglePaymentTermDays);
    togglePaymentTermDays();

    $('#saleInvoiceForm').on('submit', function () {
        $('#saveBtn').prop('disabled', true).text('Saving...');
    });
});
</script>
@endsection