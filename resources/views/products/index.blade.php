@extends('layouts.app')

@section('title', 'Product | All Product')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header">
        <div style="display: flex;justify-content: space-between;">
          <h2 class="card-title">All Products</h2>
          <div>
            <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Products</a>
          </div>
        </div>
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="cust-datatable-default">
            <thead>
              <tr>
                <th>S.No</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach($products as $index => $product)
              <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $product->name }}</td>
                <td>
                  @if($product->category || $product->subcategory)
                    {{ $product->category->name ?? '' }}
                    @if(!empty($product->subcategory))
                      - {{ $product->subcategory->name }}
                    @endif
                  @else
                    -
                  @endif
                </td>
                <td>
                  <a href="{{ route('products.edit', $product->id) }}" class="text-primary"><i class="fa fa-edit"></i></a>
                  <form method="POST" action="{{ route('products.destroy', $product->id) }}" style="display:inline-block">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Delete this product?')" title="Delete"><i class="fa fa-trash-alt"></i></button>
                  </form>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
  $(document).ready(function () {
    $('#cust-datatable-default').DataTable({
      "pageLength": 100
    });
  });

</script>
@endsection
