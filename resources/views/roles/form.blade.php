@extends('layouts.app')

@section('title', $role ? 'Roles | Edit' : 'Roles | Create')

@section('content')
<div class="row">
    <div class="col">
        <form action="{{ $role ? route('roles.update', $role) : route('roles.store') }}" method="POST">
            @csrf
            @if($role)
                @method('PUT')
            @endif

            {{-- Role Header + Master Select --}}
            <section class="card">
                <header class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title mb-0">{{ $role ? 'Edit Role' : 'Add New Role' }}</h2>
                </header>

                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @elseif (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <div class="row">
                        <div class="col-12 col-md-2 mb-3">
                            <label for="name"><strong>Role Name</strong></label>
                            <input type="text" id="name" name="name" value="{{ old('name', $role->name ?? '') }}" class="form-control" required>
                            @error('name')<div class="text-danger">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </section>

            {{-- Module Permissions --}}
            <section class="card mt-3">
                <header class="card-header d-flex justify-content-between">
                    <h2 class="card-title mb-0">Assign Module Permissions</h2>
                    <div class="checkbox-default mt-3">
                        <input type="checkbox" id="masterCheckAll" class="check-all ">
                        <label class="text-dark"> Select All </label>
                    </div>
                </header>

                <div class="card-body" style="max-height:600px; overflow-y:auto; padding:0rem 0.6rem 0.6rem 0rem!important">
                    <table class="table table-bordered" id="permissionsTable">
                        <thead class="bg-primary text-white text-center sticky-top" style="z-index:1">
                            <tr>
                                <th>Module</th>
                                @php 
                                    $actions = [
                                        'index' => 'View',
                                        'create' => 'Create',
                                        'edit' => 'Edit',
                                        'delete' => 'Delete',
                                        'print' => 'Print'
                                    ];
                                @endphp
                                @foreach($actions as $actionKey => $actionLabel)
                                    <th>
                                        <div class="checkbox-default">
                                            <input type="checkbox" class="check-all" data-action="{{ $actionKey }}"> 
                                            <label class="text-light"> {{ $actionLabel }} </label>
                                        </div>
                                    </th>
                                @endforeach
                                <th> 
                                    <div class="checkbox-default">
                                        <input type="checkbox" id="checkAllModules"> 
                                        <label class="text-light"> All </label>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @php
                                $groupedPermissions = [];
                                foreach ($permissions as $permission) {
                                    $parts = explode('.', $permission->name);
                                    if (count($parts) === 2 && $parts[0] !== 'reports') {
                                        [$module, $action] = $parts;
                                        $groupedPermissions[$module][$action] = $permission->name;
                                    }
                                }
                                ksort($groupedPermissions);
                            @endphp

                            @forelse($groupedPermissions as $module => $perms)
                                <tr>
                                    <td class="align-middle">
                                        <strong>{{ ucwords(str_replace('_', ' ', $module)) }}</strong>
                                    </td>

                                    @foreach($actions as $actionKey => $actionLabel)
                                        <td class="text-center align-middle">
                                            @if(isset($perms[$actionKey]))
                                                <input type="checkbox"
                                                       name="permissions[]"
                                                       value="{{ $perms[$actionKey] }}"
                                                       data-action="{{ $actionKey }}"
                                                       data-module="{{ $module }}"
                                                       class="perm-checkbox {{ $actionKey }}-checkbox"
                                                       {{ $role && $role->hasPermissionTo($perms[$actionKey]) ? 'checked' : '' }}>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach

                                    <td class="text-center align-middle">
                                        <input type="checkbox" class="check-module" data-module="{{ $module }}">
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 2 + count($actions) }}" class="text-center text-muted">
                                        No module permissions found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Report Permissions --}}
            <section class="card mt-3">
                <header class="card-header">
                    <h2 class="card-title mb-0">Assign Report Permissions</h2>
                </header>

                <div class="card-body" style="padding:0rem 0.6rem 0.6rem 0rem!important">
                    <table class="table table-bordered text-center">
                        <thead class="bg-primary text-white">
                            <tr>
                                <th>Report</th>
                                <th>
                                    <div class="checkbox-default">
                                        <input type="checkbox" id="checkAllReports"> 
                                        <label class="text-light"> Access </label>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $reportPermissions = $permissions->filter(fn($p) =>
                                    str_starts_with($p->name, 'reports.')
                                );
                            @endphp

                            @forelse($reportPermissions as $permission)
                                <tr>
                                    <td class="align-middle">
                                        <strong>{{ ucwords(str_replace(['reports.', '_'], ['', ' '], $permission->name)) }}</strong>
                                    </td>
                                    <td class="align-middle">
                                        <input type="checkbox"
                                               name="permissions[]"
                                               class="report-checkbox"
                                               value="{{ $permission->name }}"
                                               {{ $role && $role->hasPermissionTo($permission->name) ? 'checked' : '' }}>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="text-center text-muted">No report permissions found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <footer class="card-footer text-end mt-2">
                    <a class="btn btn-danger" href="{{ route('roles.index') }}">Discard</a>
                    <button type="submit" class="btn btn-primary">
                        {{ $role ? 'Update' : 'Create' }}
                    </button>                    
                </footer>
            </section>
        </form>
    </div>
</div>

<script>
    // Column-level toggles
    document.querySelectorAll('.check-all').forEach(headerCheckbox => {
        headerCheckbox.addEventListener('change', function() {
            let action = this.dataset.action;
            document.querySelectorAll('input[data-action="'+action+'"]').forEach(cb => cb.checked = this.checked);
        });
    });

    // Row-level toggles
    document.querySelectorAll('.check-module').forEach(rowCheckbox => {
        rowCheckbox.addEventListener('change', function() {
            let module = this.dataset.module;
            document.querySelectorAll('input[data-module="'+module+'"]').forEach(cb => cb.checked = this.checked);
        });
    });

    // Global modules toggle
    document.getElementById('checkAllModules')?.addEventListener('change', function() {
        document.querySelectorAll('.check-module').forEach(cb => {
            cb.checked = this.checked;
            cb.dispatchEvent(new Event('change'));
        });
    });

    // Reports toggle
    document.getElementById('checkAllReports')?.addEventListener('change', function() {
        document.querySelectorAll('.report-checkbox').forEach(cb => cb.checked = this.checked);
    });

    // Master toggle: everything
    document.getElementById('masterCheckAll')?.addEventListener('change', function() {
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            if (cb.id !== 'masterCheckAll') cb.checked = this.checked;
        });
    });
</script>
@endsection
