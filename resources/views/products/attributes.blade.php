@extends('layouts.app')

@section('title', 'Products | Attributes')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header">
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Attributes</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addAttributeModal">
              <i class="fas fa-plus"></i> Add Attribute
            </button>
          </div>
        </div>
      </header>
      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-attributes">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Values</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($attributes as $attribute)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $attribute->name }}</td>
                <td>
                  @foreach ($attribute->values as $value)
                    <span class="badge bg-secondary">{{ $value->value }}</span>
                  @endforeach
                </td>
                <td>
                  <a class="text-primary modal-with-form" href="#editAttributeModal{{ $attribute->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  <form action="{{ route('attributes.destroy', $attribute->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>

              <!-- Edit Attribute Modal -->
              <div id="editAttributeModal{{ $attribute->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="POST" action="{{ route('attributes.update', $attribute->id) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Attribute</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group mb-3">
                        <label>Attribute Name</label>
                        <input type="text" class="form-control" name="name" value="{{ $attribute->name }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Slug</label>
                        <input type="text" class="form-control" name="slug" value="{{ $attribute->slug }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Attribute Values (comma-separated)</label>
                        <input type="text" class="form-control" name="values" value="{{ $attribute->values->pluck('value')->implode(', ') }}" required>
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

    <!-- Add Attribute Modal -->
    <div id="addAttributeModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('attributes.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Attribute</h2>
          </header>
          <div class="card-body">
            <div class="form-group mb-3">
              <label>Attribute Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" required>
            </div>
            <div class="form-group mb-3">
              <label>Slug (unique)</label>
              <input type="text" class="form-control" name="slug" required>
            </div>
            <div class="form-group mb-3">
              <label>Attribute Values (comma-separated)</label>
              <input type="text" class="form-control" name="values" placeholder="Red, Blue, Small, Large" required>
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
@endsection
