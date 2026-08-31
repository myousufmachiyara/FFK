@extends('layouts.app')

@section('title', 'Purchase Return | New Return')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_return.store') }}" method="POST">
      @csrf

      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">New Purchase Return</h2>
        </header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach ($vendors as $vendor)
                  <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2">
              <label>Return Date</label>
              <input type="date" name="return_date" class="form-control" value="{{ now()->toDateString() }}" required>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="returnTable">
              <thead>
                <tr>
                  <th>Barcode</th>
                  <th>Item Name</th>
                  <th>Variation</th>
                  <th>Invoice #</th>
                  <th>Qty</th>
                  <th>Unit</th>
                  <th>Price</th>
                  <th>Amount</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="ReturnTableBody">
                <tr>
                  <td><input type="text" name="items[0][barcode]" class="form-control product-code"></td>

                  <td>
                    <select name="items[0][item_id]" class="form-control select2-js product-select" onchange="onReturnItemChange(this)">
                      <option value="">Select Item</option>
                      @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-barcode="{{ $product->barcode }}" data-unit="{{ $product->measurement_unit }}">
                          {{ $product->name }}
                        </option>
                      @endforeach
                    </select>
                  </td>

                  <td>
                    <select name="items[0][variation_id]" class="form-control select2-js variation-select">
                      <option value="">Select Variation</option>
                    </select>
                  </td>

                  <td>
                    <select name="items[0][invoice_id]" class="form-control invoice-select" required>
                      <option value="">Select Invoice</option>
                    </select>
                  </td>

                  <td><input type="number" name="items[0][quantity]" class="form-control quantity" step="any" onchange="rowTotal(0)"></td>

                  <td>
                    <select name="items[0][unit]" class="form-control unit-select" required>
                      <option value="">-- Select --</option>
                      @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
                      @endforeach
                    </select>
                  </td>

                  <td><input type="number" name="items[0][price]" class="form-control price" step="any" onchange="rowTotal(0)"></td>
                  <td><input type="number" name="items[0][amount]" class="form-control amount" step="any" readonly></td>
                  <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
                  </td>
                </tr>
              </tbody>
            </table>

            <button type="button" class="btn btn-outline-primary" onclick="addReturnRow()"><i class="fas fa-plus"></i> Add Item</button>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control"></textarea>
            </div>

            <div class="col-md-3">
              <label>Total Amount</label>
              <input type="number" id="total_amount" class="form-control" readonly>
              <input type="hidden" name="total_amount" id="total_amount_hidden">
            </div>

            <div class="col-md-3">
              <label>Net Amount</label>
              <input type="number" id="net_amount" class="form-control" readonly>
              <input type="hidden" name="net_amount_hidden">
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var units = @json($units);
  var index = 1;

  // ----------------- Add new row -----------------
  function addReturnRow() {
    let newRow = `
      <tr>
        <td><input type="text" name="items[${index}][barcode]" class="form-control product-code"></td>

        <td>
          <select name="items[${index}][item_id]" class="form-control select2-js product-select" onchange="onReturnItemChange(this)">
            <option value="">Select Item</option>
            ${products.map(p => `<option value="${p.id}" data-barcode="${p.barcode}" data-unit="${p.measurement_unit}">${p.name}</option>`).join('')}
          </select>
        </td>

        <td>
          <select name="items[${index}][variation_id]" class="form-control select2-js variation-select">
            <option value="">Select Variation</option>
          </select>
        </td>

        <td>
          <select name="items[${index}][invoice_id]" class="form-control invoice-select" required>
            <option value="">Select Invoice</option>
          </select>
        </td>

        <td><input type="number" name="items[${index}][quantity]" class="form-control quantity" step="any" onchange="rowTotal(${index})"></td>

        <td>
          <select name="items[${index}][unit]" class="form-control unit-select" required>
            <option value="">-- Select --</option>
            ${units.map(u => `<option value="${u.id}">${u.name} (${u.shortcode})</option>`).join('')}
          </select>
        </td>

        <td><input type="number" name="items[${index}][price]" class="form-control price" step="any" onchange="rowTotal(${index})"></td>
        <td><input type="number" name="items[${index}][amount]" class="form-control amount" step="any" readonly></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
        </td>
      </tr>
    `;
    $('#ReturnTableBody').append(newRow);
    $('.select2-js').select2({ width: '100%' });
    index++;
  }

  // ----------------- When product changes -----------------
  function onReturnItemChange(select) {
    const row = $(select).closest('tr');
    const productId = $(select).val();
    const barcode = $(select).find(':selected').data('barcode');
    const unitId = $(select).find(':selected').data('unit');

    // Update barcode & unit
    row.find('input.product-code').val(barcode);
    row.find('select.unit-select').val(unitId).trigger('change');

    // Load variations & invoices
    loadVariations(row, productId);
    loadInvoices(row, productId);
  }

  // ----------------- Load product variations -----------------
  function loadVariations(row, productId) {
    const $variationSelect = row.find('.variation-select');
    $variationSelect.html('<option>Loading...</option>');

    $.get(`/product/${productId}/variations`, function(data){
      let options = '<option value="">Select Variation</option>';
      (data.variation || []).forEach(v => {
        options += `<option value="${v.id}">${v.sku}</option>`;
      });
      $variationSelect.html(options);
      $variationSelect.select2({ width: '100%' });
    });
  }

    // ----------------- Load invoices for vendor & product -----------------
  function loadInvoices(row, productId) {
    const $invoiceSelect = row.find('.invoice-select');
    const $priceInput = row.find('.price');

    $invoiceSelect.html('<option>Loading...</option>');

    $.get(`/product/${productId}/invoices`, function(data){
      let options = '<option value="">Select Invoice</option>';
      data.forEach(inv => {
        // Store rate as data attribute
        options += `<option value="${inv.id}" data-rate="${inv.rate}">#${inv.id}</option>`;
      });
      $invoiceSelect.html(options);

      // Reset price
      $priceInput.val('');
    }).fail(() => {
      alert('Failed to load invoices.');
      $invoiceSelect.html('<option value="">Select Invoice</option>');
      $priceInput.val('');
    });
  }

  // Listen for invoice selection change to set the price
  $(document).on('change', '.invoice-select', function() {
    const $row = $(this).closest('tr');
    const rate = $(this).find(':selected').data('rate') || 0;
    $row.find('.price').val(rate).trigger('change'); // trigger rowTotal
  });

  // ----------------- Barcode scanning -----------------
  $(document).on('blur', '.product-code', function () {
      const row = $(this).closest('tr');
      const barcode = $(this).val().trim();
      if (!barcode) return;

      $.ajax({
          url: '/get-product-by-code/' + encodeURIComponent(barcode),
          method: 'GET',
          success: function (res) {
              if (!res || !res.success) {
                  alert(res?.message || 'Product not found');
                  resetRow(row);
                  return;
              }

              if (res.type === 'variation') {
                  const variation = res.variation;

                  row.find('.product-select').val(variation.product_id).trigger('change.select2');
                  row.find('.variation-select').html(`<option value="${variation.id}" selected>${variation.sku}</option>`).trigger('change.select2');
                  row.find('.product-code').val(variation.barcode);
                  row.find('input[name*="[barcode]"]').val(variation.barcode);

                  // Load invoices for this vendor & product
                  loadInvoices(row, variation.product_id);

                  row.find('.quantity').focus();

              } else if (res.type === 'product') {
                  const product = res.product;

                  const $productSelect = row.find('.product-select');
                  if ($productSelect.find(`option[value="${product.id}"]`).length) {
                      $productSelect.val(product.id).trigger('change.select2');

                      row.find('.variation-select').html('<option value="">Select Variation</option>').trigger('change.select2');
                      row.find('.product-code').val(product.barcode);
                      row.find('input[name*="[barcode]"]').val(product.barcode);

                      loadInvoices(row, product.id);
                      row.find('.variation-select').select2('open');

                  } else {
                      alert("Product found but not in dropdown list.");
                      resetRow(row);
                  }
              }
          },
          error: function () {
              alert('Error fetching product details.');
              resetRow(row);
          }
      });
  });

  // ----------------- Reset row -----------------
  function resetRow(row) {
      row.find('.product-code').val('').focus();
      row.find('.product-select').val('').trigger('change.select2');
      row.find('.variation-select').html('<option value="">Select Variation</option>').trigger('change.select2');
      row.find('select.invoice-select').html('<option value="">Select Invoice</option>');
      row.find('input[name*="[barcode]"]').val('');
  }

  // ----------------- Row totals -----------------
  function rowTotal(idx) {
    const row = $('#ReturnTableBody tr').eq(idx);
    const qty = parseFloat(row.find('input.quantity').val()) || 0;
    const price = parseFloat(row.find('input.price').val()) || 0;
    row.find('input.amount').val((qty * price).toFixed(2));
    updateTotal();
  }

  function updateTotal() {
    let total = 0;
    $('#ReturnTableBody tr').each(function(){
      total += parseFloat($(this).find('input.amount').val()) || 0;
    });
    $('#total_amount, #net_amount').val(total.toFixed(2));
    $('#total_amount_hidden, input[name="net_amount_hidden"]').val(total.toFixed(2));
  }

  function removeRow(button) {
    $(button).closest('tr').remove();
    updateTotal();
  }

  $(document).ready(function(){
    $('.select2-js').select2({ width: '100%' });

    // When vendor changes, reload all invoice selects in table
    $('select[name="vendor_id"]').on('change', function() {
        $('#ReturnTableBody tr').each(function(){
            const productId = $(this).find('.product-select').val();
            if (productId) loadInvoices($(this), productId);
        });
    });
  });
</script>

@endsection
