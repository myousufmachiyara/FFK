@extends('layouts.app')

@section('title', 'Users | Roles')

@section('content')
    <div class="row">
        <div class="col">
            <section class="card">
                @if (session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
                @elseif (session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
                @endif
                <header class="card-header" style="display: flex;justify-content: space-between;">
                    <h2 class="card-title">All Roles</h2>
                    <div>
                        <a href="{{ route('roles.create') }}" class="btn btn-primary"> <i class="fas fa-plus"></i> New Role </a>
                    </div>
                </header>
                <div class="card-body">
                    <div class="modal-wrapper table-scroll">
                        <table class="table table-bordered table-striped mb-0" id="cust-datatable-default">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($roles as $role)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $role->name }}</td>
                                        <td>
                                            <a href="{{ route('roles.edit', $role->id) }}" class="text-primary"><i class="fa fa-edit"></i></a>
                                            <!-- <form action="{{ route('roles.destroy', $role->id) }}" method="POST" class="d-inline">
                                                @csrf @method('DELETE')
                                                <button class="text-danger bg-transparent" style="border:none" onclick="return confirm('Are you sure?')"><i class="fa fa-trash"></i></button>
                                            </form> -->
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
@endsection
