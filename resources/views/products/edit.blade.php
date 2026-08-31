@extends('layouts.app')

@section('title', 'Product | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form id="productForm" action="{{ route('products.update', $product->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Edit Product</h2>
        </header>
        <div class="card-body">
          @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
          @endif
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          <div class="row pb-3">
            <div class="col-md-2">
              <label>Product Name *</label>
              <input type="text" name="name" class="form-control" required value="{{ old('name', $product->name) }}">
            </div>

            <div class="col-md-2">
              <label>Category *</label>
              <select name="category_id" class="form-control select2-js" required>
                @foreach($categories as $cat)
                  <option value="{{ $cat->id }}" {{ $product->category_id == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2">
              <label>Sub Category </label>
              <select name="subcategory_id" class="form-control">
                @foreach($subcategories as $subcat)
                  <option value="{{ $subcat->id }}" {{ $product->subcategory_id == $subcat->id ? 'selected' : '' }}>{{ $subcat->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2">
              <label>SKU</label>
              <input type="text" name="sku" id="sku" class="form-control" value="{{ old('sku', $product->sku) }}">
            </div>

            <div class="col-md-2">
              <label>Measurement Unit *</label>
              <select name="measurement_unit" class="form-control" required>
                <option value="">-- Select Unit --</option>
                @foreach($units as $unit)
                  <option value="{{ $unit->id }}" {{ $product->measurement_unit == $unit->id ? 'selected' : '' }}>
                    {{ $unit->name }} ({{ $unit->shortcode }})
                  </option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2">
              <label>Selling Price / Unit</label>
              <input type="number" step="any" name="selling_price" class="form-control" value="{{ old('selling_price', $product->selling_price) }}">
            </div>

            <div class="col-md-2 mt-3">
              <label>Opening Stock</label>
              <input type="number" step="any" name="opening_stock" class="form-control" value="{{ old('opening_stock', $product->opening_stock) }}">
            </div>

            <div class="col-md-2 mt-3">
              <label>Status</label>
              <select name="is_active" class="form-control">
                <option value="1" {{ old('is_active', $product->is_active) == 1 ? 'selected' : '' }}>Active</option>
                <option value="0" {{ old('is_active', $product->is_active) == 0 ? 'selected' : '' }}>Inactive</option>
              </select>
            </div>


            <div class="col-md-4 mt-3">
              <label>Description</label>
              <textarea name="description" class="form-control">{{ old('description', $product->description) }}</textarea>
            </div>
          </div>

          <div class="row mt-4">
            <div class="col-md-12">
              <h2 class="card-title">Existing Variations</h2>
              <div id="variation-section">
                @foreach($product->variations as $i => $variation)
                  <div class="variation-block border p-2 mb-3 existing-variation">
                    <input type="hidden" name="variations[{{ $i }}][id]" value="{{ $variation->id }}">
                    <div class="row">
                      <div class="col-md-4">
                        <label>SKU</label>
                        <input type="text" name="variations[{{ $i }}][sku]" class="form-control sku-field" value="{{ $variation->sku }}">
                      </div>
                      <div class="col-md-2">
                        <label>Stock</label>
                        <input type="number" step="any" name="variations[{{ $i }}][stock_quantity]" class="form-control" value="{{ $variation->stock_quantity }}">
                      </div>
                      <div class="col-md-4">
                        <label>Attributes</label>
                        <select name="variations[{{ $i }}][attributes][]" multiple class="form-control select2-js variation-attributes">
                          @foreach($attributes as $attribute)
                            @foreach($attribute->values as $value)
                              <option value="{{ $value->id }}" {{ $variation->attributeValues->pluck('id')->contains($value->id) ? 'selected' : '' }}>
                                {{ $attribute->name }} - {{ $value->value }}
                              </option>
                            @endforeach
                          @endforeach
                        </select>
                      </div>
                      <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-danger remove-existing-variation" data-id="{{ $variation->id }}">X</button>
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>

              <div class="col-md-12 mt-3">
                <h2 class="card-title">Add New Variations</h2>
                <div id="new-variation-section"></div>
                <button type="button" class="btn btn-sm btn-secondary mt-2" id="addNewVariationBtn">Add Variation</button>
              </div>
            </div>
          </div>

        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('products.index') }}" class="btn btn-danger">Cancel</a>
          <button type="submit" class="btn btn-primary">Update Product</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  
  $(document).ready(function () {
    $('.select2-js').select2();

    $(document).on('change', '.variation-attributes', function () {
      const block = $(this).closest('.variation-block');
      const selectedOptions = $(this).find('option:selected');
      const attrTexts = [];
      selectedOptions.each(function () {
        attrTexts.push($(this).text().split('-')[1]);
      });
      const variationName = attrTexts.join('-');
      const mainSku = $('#sku').val();
      block.find('.sku-field').val(mainSku + '-' + variationName);
    });

    let newVariationIndex = 0;
    $('#addNewVariationBtn').click(function () {
      newVariationIndex++;
      const html = `
        <div class="variation-block border p-2 mb-3">
          <div class="row">
            <div class="col-md-4">
              <label>SKU</label>
              <input type="text" name="new_variations[${newVariationIndex}][sku]" class="form-control sku-field">
            </div>
            <div class="col-md-2">
              <label>Stock</label>
              <input type="number" step="any" name="new_variations[${newVariationIndex}][stock_quantity]" value="0.00" class="form-control">
            </div>
            <div class="col-md-4">
              <label>Attributes</label>
              <select name="new_variations[${newVariationIndex}][attributes][]" multiple class="form-control select2-js variation-attributes">
                @foreach($attributes as $attribute)
                  @foreach($attribute->values as $value)
                    <option value="{{ $value->id }}">{{ $attribute->name }}-{{ $value->value }}</option>
                  @endforeach
                @endforeach
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="button" class="btn btn-sm btn-danger remove-new-variation">X</button>
            </div>
          </div>
        </div>
      `;    
      $('#new-variation-section').append(html);
      $('.select2-js').select2();
    });

    $(document).on('click', '.remove-new-variation', function () {
      $(this).closest('.variation-block').remove();
    });

    $(document).on('click', '.remove-existing-variation', function () {
      const block = $(this).closest('.variation-block');
      const variationId = $(this).data('id');
      if (confirm('Are you sure you want to remove this variation?')) {
        block.find('input, select, textarea').prop('disabled', true); // disable all fields so they don’t get submitted
        block.hide();
        block.append(`<input type="hidden" name="removed_variations[]" value="${variationId}" class="removed-variation-flag">`);
        const undoHtml = `<div class="undo-variation-alert alert alert-warning mb-3" data-id="${variationId}">
          Variation removed. <button type="button" class="btn btn-sm btn-link p-0 undo-remove-variation">Undo</button>
        </div>`;
        block.after(undoHtml);
      }
    });

    $(document).on('click', '.undo-remove-variation', function () {
      const alertBox = $(this).closest('.undo-variation-alert');
      const variationId = alertBox.data('id');
      const block = $('.variation-block').has(`input[name="variation_ids[]"][value="${variationId}"]`);
      block.find('.removed-variation-flag').remove();
      block.find('input, select, textarea').prop('disabled', false);
      block.show();
      alertBox.remove();
    });

  });
</script>
@endsection
