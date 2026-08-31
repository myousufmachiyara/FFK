@extends('layouts.app')

@section('title', 'Accounts | Sub Head Of Accounts')

@section('content')
<div class="row">
    <div class="col">
        <section class="card">
            <header class="card-header" style="display: flex;justify-content: space-between;">
                <h2 class="card-title">All Sub Head Of Accounts</h2>
                <div>
                    @can('shoa.create')
                    <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
                        <i class="fas fa-plus"></i> Add New
                    </button>
                    @endcan
                </div>
            </header>

            <div class="card-body">
                <div class="modal-wrapper table-scroll">
                    <table class="table table-bordered table-striped mb-0" id="cust-datatable-default">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Name</th>
                                <th>Head</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subHeadOfAccounts as $item)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $item->name }}</td>
                                <td>{{ $item->headOfAccount->name ?? 'N/A' }}</td>
                                <td>
                                    @can('shoa.edit')
                                    <a href="javascript:void(0);" class="text-primary" onclick="editSubHead({{ $item->id }})">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    @endcan

                                    @can('shoa.delete')
                                    <form action="{{ route('shoa.destroy', $item->id) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <a class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Are you sure?')"><i class="fa fa-trash-alt"></i></a>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        @can('shoa.create')
        {{-- ADD MODAL --}}
        <div id="addModal" class="modal-block modal-block-primary mfp-hide">
            <section class="card">
                <form method="post" action="{{ route('shoa.store') }}" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
                    @csrf
                    <header class="card-header">
                        <h2 class="card-title">New Sub Head Of Account</h2>
                    </header>
                    <div class="card-body">
                        <div class="form-group mt-2">
                            <label>Head Of Account<span class="text-danger">*</span></label>
                            <select data-plugin-selecttwo class="form-control select2-js" name="hoa_id" required>
                                <option value="" selected disabled>Select Head</option>
                                @foreach($HeadOfAccounts as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label>Account Group Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="Name" name="name" required>
                        </div>
                    </div>
                    <footer class="card-footer">
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <button type="submit" class="btn btn-primary">Add</button>
                                <button class="btn btn-default modal-dismiss">Cancel</button>
                            </div>
                        </div>
                    </footer>
                </form>
            </section>
        </div>
        @endcan

        @can('shoa.edit')
        {{-- UPDATE MODAL --}}
        <div id="updateModal" class="modal-block modal-block-primary mfp-hide">
            <section class="card">
                <form method="POST" id="updateForm" action="" onkeydown="return event.key != 'Enter';">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                        <h2 class="card-title">Update Sub Head Of Account</h2>
                    </header>
                    <div class="card-body">
                        <div class="form-group mt-2">
                            <label>Head Of Account<span class="text-danger">*</span></label>
                            <select data-plugin-selecttwo class="form-control select2-js" name="hoa_id" id="edit_hoa_id" required>
                                <option value="" disabled>Select Head</option>
                                @foreach($HeadOfAccounts as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mt-3">
                            <label>Account Group Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" placeholder="Name" required>
                        </div>
                    </div>
                    <footer class="card-footer">
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <button type="submit" class="btn btn-primary">Update</button>
                                <button class="btn btn-default modal-dismiss">Cancel</button>
                            </div>
                        </div>
                    </footer>
                </form>
            </section>
        </div>
        @endcan
    </div>
</div>

<script>
    function editSubHead(id) {
        fetch(`/shoa/${id}/edit`)
            .then(res => res.json())
            .then(data => {
                $('#updateForm').attr('action', `/shoa/${id}`);
                $('#edit_name').val(data.name);
                $('#edit_hoa_id').val(data.hoa_id).trigger('change');

                $.magnificPopup.open({
                    items: { src: '#updateModal' },
                    type: 'inline'
                });
            });
    }
</script>

@endsection
