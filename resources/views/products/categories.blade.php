@extends('layouts.app')

@section('title', 'Products | Categories')

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
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Categories</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addCategoryModal">
              <i class="fas fa-plus"></i> Add Category
            </button>
          </div>
        </div>
        @if ($errors->has('error'))
        <strong class="text-danger">{{ $errors->first('error') }}</strong>
        @endif
      </header>
      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-categories">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Code</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($categories as $category)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $category->name }}</td>
                <td>{{ $category->code }}</td>
                <td>
                  <a class="text-primary modal-with-form" href="#editCategoryModal{{ $category->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  <form action="{{ route('product_categories.destroy', $category->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>

              <!-- Edit Modal -->
              <div id="editCategoryModal{{ $category->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="post" action="{{ route('product_categories.update', $category->id) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Category</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Name</label>
                        <input type="text" class="form-control" name="name" value="{{ $category->name }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Code</label>
                        <input type="text" class="form-control" name="code" value="{{ $category->code }}" required>
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
    <div id="addCategoryModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('product_categories.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Category</h2>
          </header>
          <div class="card-body">
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
    $('#datatable-categories').DataTable({
      "pageLength": 100
    });
  });
</script>
@endsection