@extends('layouts.app')
@section('title', 'Edit Sale Return')

@section('content')
<div class="row">
  <div class="col">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title">Edit Sale Return</h4>
        <a href="{{ route('sale_return.index') }}" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
      </div>
      <div class="card-body">
        <form action="{{ route('sale_return.update', $return->id) }}" method="POST" id="saleReturnForm">
          @csrf
          @method('PUT')

          <div class="row mb-3">
            <div class="col-md-3">
              <label>Customer Name</label>
              <select name="account_id" class="form-control" required>
                <option value="">Select Customer</option>
                @foreach($customers as $cust)
                  <option value="{{ $cust->id }}" {{ $return->account_id == $cust->id ? 'selected' : '' }}>
                    {{ $cust->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Date</label>
              <input type="date" name="return_date" class="form-control" value="{{ $return->return_date }}" required>
            </div>
            <div class="col-md-2">
              <label>Sale Inv #</label>
              <input type="text" name="sale_invoice_no" class="form-control" value="{{ $return->sale_invoice_no }}">
            </div>
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead>
              <tr>
                <th width="15%">Item Code</th>
                <th>Product</th>
                <th>Variation</th>
                <th width="8%">Qty</th>
                <th width="10%">Price</th>
                <th width="12%">Total</th>
                <th width="5%">
                  <button type="button" class="btn btn-sm btn-success" id="addRowBtn"><i class="fas fa-plus"></i></button>
                </th>
              </tr>
            </thead>
            <tbody>
              @foreach($return->items as $i => $item)
              <tr>
                <td>
                  <input type="text" class="form-control product-code" placeholder="Scan/Enter Code"
                         value="{{ $item->product->barcode ?? '' }}">
                </td>
                <td>
                  <select name="items[{{ $i }}][product_id]" class="form-control product-select" required>
                    <option value="">Select Product</option>
                    @foreach($products as $prod)
                      <option value="{{ $prod->id }}" data-price="{{ $prod->selling_price }}"
                        {{ $item->product_id == $prod->id ? 'selected' : '' }}>
                        {{ $prod->name }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[{{ $i }}][variation_id]" class="form-control variation-select">
                    <option value="">Select Variation</option>
                    @if($item->product && $item->product->variations)
                      @foreach($item->product->variations as $var)
                        <option value="{{ $var->id }}" data-price="{{ $var->price }}"
                          {{ $item->variation_id == $var->id ? 'selected' : '' }}>
                          {{ $var->sku }}
                        </option>
                      @endforeach
                    @endif
                  </select>
                </td>
                <td><input type="number" name="items[{{ $i }}][qty]" class="form-control qty-input" value="{{ $item->qty }}" min="1"></td>
                <td><input type="number" name="items[{{ $i }}][price]" class="form-control price-input" step="any" value="{{ $item->price }}" required></td>
                <td><input type="number" name="items[{{ $i }}][total]" class="form-control total-input" value="{{ $item->qty * $item->price }}" readonly></td>
                <td>
                  <button type="button" class="btn btn-sm btn-danger removeRowBtn">X</button>
                  <input type="hidden" name="items[{{ $i }}][delete]" value="0" class="delete-flag">
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>

          <div class="row mt-3">
            <div class="col-md-6">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2">{{ $return->remarks }}</textarea>
            </div>
            <div class="col-md-2 offset-md-4">
              <label>Net Amount</label>
              <input type="number" name="net_amount" id="net_amount" class="form-control" value="{{ $return->items->sum(fn($x) => $x->qty * $x->price) }}" readonly>
            </div>
          </div>

          <div class="mt-3">
            <button type="submit" class="btn btn-primary">Update Return</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function () {
    let rowIndex = $("#itemsTable tbody tr").length || 0;

    // ✅ Add Row button
    $("#addRowBtn").click(function () {
        let newRow = `<tr>
            <td><input type="text" class="form-control product-code" placeholder="Scan/Enter Code"></td>
            <td>
              <select name="items[${rowIndex}][product_id]" class="form-control product-select" required>
                <option value="">Select Product</option>
                @foreach($products as $prod)
                  <option value="{{ $prod->id }}" data-price="{{ $prod->selling_price }}">{{ $prod->name }}</option>
                @endforeach
              </select>
            </td>
            <td>
              <select name="items[${rowIndex}][variation_id]" class="form-control variation-select">
                <option value="">Select Variation</option>
              </select>
            </td>
            <td><input type="number" name="items[${rowIndex}][qty]" class="form-control qty-input" value="1" min="1"></td>
            <td><input type="number" name="items[${rowIndex}][price]" class="form-control price-input" step="any" required></td>
            <td><input type="number" name="items[${rowIndex}][total]" class="form-control total-input" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger removeRow"><i class="fas fa-trash"></i></button></td>
          </tr>`;
        $("#itemsTable tbody").append(newRow);

        $('#itemsTable tbody tr:last .product-select, #itemsTable tbody tr:last .variation-select').select2({
            width: '100%',
            dropdownAutoWidth: true
        });

        rowIndex++;
    });

    // ✅ Remove row
    $(document).on("click", ".removeRow, .removeRowBtn", function () {
        $(this).closest("tr").remove();
        calculateGrandTotal();
    });

    // ✅ Product change → set price + load variations
    $(document).on("change", ".product-select", function () {
        let row = $(this).closest("tr");
        let productId = $(this).val();
        let price = $(this).find(":selected").data("price") || 0;

        row.find(".price-input").val(price);
        calculateRowTotal(row);

        if (productId) {
            loadVariations(row, productId);
        } else {
            row.find(".variation-select").html('<option value="">Select Variation</option>').trigger('change');
        }
    });

    // ✅ Variation change → update price if variation has price
    $(document).on("change", ".variation-select", function () {
        let row = $(this).closest("tr");
        let price = $(this).find(":selected").data("price") || row.find(".price-input").val();
        row.find(".price-input").val(price);
        calculateRowTotal(row);
    });

    // ✅ Quantity / Price change → recalc total
    $(document).on("input", ".qty-input, .price-input", function () {
        let row = $(this).closest("tr");
        calculateRowTotal(row);
    });

    // ✅ Barcode scan
    $(document).on("blur", ".product-code", function () {
        let row = $(this).closest("tr");
        let barcode = $(this).val().trim();
        if (!barcode) return;

        $.ajax({
            url: '/get-product-by-code/' + encodeURIComponent(barcode),
            method: 'GET',
            success: function (res) {
                const $productSelect = row.find('.product-select');
                const $variationSelect = row.find('.variation-select');

                if (!res || !res.success) {
                    alert(res.message || 'Product not found');
                    resetRow(row);
                    return;
                }

                // 🔹 CASE 1: Variation barcode
                if (res.type === 'variation' && res.variation) {
                    const v = Array.isArray(res.variation) ? res.variation[0] : res.variation;
                    $productSelect.val(v.product_id).trigger('change.select2');
                    loadVariations(row, v.product_id, v.id);

                    row.find('.price-input').val(v.price || 0);
                    row.find('.qty-input').val(row.find('.qty-input').val() || 1);
                    calculateRowTotal(row);

                    row.find('.qty-input').focus();
                    if (row.is(':last-child')) {
                        $("#addRowBtn").trigger("click");
                        $('#itemsTable tbody tr:last .product-code').focus();
                    }
                    return;
                }

                // 🔹 CASE 2: Product barcode
                if (res.type === 'product' && res.product) {
                    const p = res.product;
                    if ($productSelect.find(`option[value="${p.id}"]`).length) {
                        $productSelect.val(p.id).trigger('change.select2');
                        row.find('.product-code').val(p.barcode);
                        row.find('.price-input').val(p.selling_price || 0);
                        loadVariations(row, p.id);
                        setTimeout(() => $variationSelect.select2('open'), 300);
                    } else {
                        alert("Product found but not in dropdown list.");
                        resetRow(row);
                    }
                    return;
                }

                alert('Invalid response. Barcode not matched.');
                resetRow(row);
            },
            error: function () {
                alert('Error fetching product/variation.');
                resetRow(row);
            }
        });
    });

    // ✅ Helpers
    function calculateRowTotal(row) {
        let qty = parseFloat(row.find(".qty-input").val()) || 0;
        let price = parseFloat(row.find(".price-input").val()) || 0;
        let total = qty * price;
        row.find(".total-input").val(total.toFixed(2));
        calculateGrandTotal();
    }

    function calculateGrandTotal() {
        let grandTotal = 0;
        $(".total-input").each(function () {
            grandTotal += parseFloat($(this).val()) || 0;
        });
        $("#net_amount").val(grandTotal.toFixed(2));
    }

    function resetRow(row) {
        row.find('.product-code').val('');
        row.find('.product-select').val('').trigger('change.select2');
        row.find('.variation-select').html('<option value="">Select Variation</option>');
        row.find('.price-input').val('');
        row.find('.qty-input').val(1);
        row.find('.total-input').val('');
        calculateGrandTotal();
    }

    // 🔹 Load variations with optional preselect
    function loadVariations(row, productId, preselectVariationId = null) {
        let $variationSelect = row.find('.variation-select');
        $variationSelect.html('<option value="">Loading...</option>');
        $.get(`/product/${productId}/variations`, function (data) {
            let options = '<option value="">Select Variation</option>';
            (data.variation || []).forEach(function (v) {
                options += `<option value="${v.id}" data-price="${v.price || 0}">${v.sku}</option>`;
            });
            $variationSelect.html(options).trigger('change');

            if (preselectVariationId) {
                $variationSelect.val(preselectVariationId).trigger('change');
            }
        });
    }

    // ✅ Init: calculate totals for preloaded rows
    $("#itemsTable tbody tr").each(function () {
        calculateRowTotal($(this));
    });
});
</script>

@endsection
