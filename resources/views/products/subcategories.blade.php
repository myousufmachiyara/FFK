@extends('layouts.app')

@section('title', 'Products | Subcategories')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header">
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Subcategories</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addSubcategoryModal">
              <i class="fas fa-plus"></i> Add Subcategory
            </button>
          </div>
        </div>
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-subcategories">
            <thead>
              <tr>
                <th>#</th>
                <th>Category</th>
                <th>Name</th>
                <th>Code</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($subcategories as $subcategory)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $subcategory->category->name }}</td>
                <td>{{ $subcategory->name }}</td>
                <td>{{ $subcategory->code }}</td>
               
                <td>
                  <a class="text-primary modal-with-form" href="#editSubcategoryModal{{ $subcategory->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  <form action="{{ route('product_subcategories.destroy', $subcategory->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>

              <!-- Edit Modal -->
              <div id="editSubcategoryModal{{ $subcategory->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="post" action="{{ route('product_subcategories.update', $subcategory->id) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Subcategory</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group mb-3">
                        <label>Category</label>
                        <select name="category_id" class="form-control" required>
                          @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ $subcategory->category_id == $cat->id ? 'selected' : '' }}>
                              {{ $cat->name }}
                            </option>
                          @endforeach
                        </select>
                      </div>
                      <div class="form-group mb-3">
                        <label>Name</label>
                        <input type="text" class="form-control" name="name" value="{{ $subcategory->name }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Code</label>
                        <input type="text" class="form-control" name="code" value="{{ $subcategory->code }}" required>
                      </div>
                    
                    </div>
                    <footer class="card-footer">
                      <div class="row">
                        <div class="col-md-12 text-end">
                          <button type="submit" class="btn btn-warning">Update</button>
                          <button class="btn btn-default modal-dismiss">Cancel</button>
                        </div>
                      </div>
                    </footer>
                  </form>
                </section>
              </div>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Add Modal -->
    <div id="addSubcategoryModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('product_subcategories.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Subcategory</h2>
          </header>
          <div class="card-body">
            <div class="form-group mb-3">
              <label>Category <span class="text-danger">*</span></label>
              <select name="category_id" class="form-control" required>
                <option value="">Select Category</option>
                @foreach($categories as $cat)
                  <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="form-group mb-3">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" required>
            </div>
            <div class="form-group mb-3">
              <label>Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="code" required>
            </div>
          </div>
          <footer class="card-footer">
            <div class="row">
              <div class="col-md-12 text-end">
                <button type="submit" class="btn btn-primary">Create</button>
                <button class="btn btn-default modal-dismiss">Cancel</button>
              </div>
            </div>
          </footer>
        </form>
      </section>
    </div>
  </div>
</div>
<script>
  $(document).ready(function () {
    $('#datatable-subcategories').DataTable({
      "pageLength": 100
    });
  });
</script>
@endsection
