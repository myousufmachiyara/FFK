<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get();
        return view('roles.index', compact('roles'));
    }

    public function create()
    {
        $permissions = Permission::all();

        $groupedPermissions = [];
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission->name);
            if (count($parts) === 2) {
                [$module, $action] = $parts;
                $groupedPermissions[$module][$action] = $permission->name;
            }
        }

        $actions = ['index' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'print' => 'Print'];

        $role = null;

        return view('roles.form', compact('permissions', 'groupedPermissions', 'actions', 'role'));
    }

    public function edit(Role $role)
    {
        $permissions = Permission::all();

        $groupedPermissions = [];
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission->name);
            if (count($parts) === 2) {
                [$module, $action] = $parts;
                $groupedPermissions[$module][$action] = $permission->name;
            }
        }

        $actions = ['index' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'print' => 'Print'];

        return view('roles.form', compact('role', 'permissions', 'groupedPermissions', 'actions'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string'
        ]);

        $role = Role::create(['name' => $request->name]);

        if ($request->filled('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return redirect()->route('roles.index')->with('success', 'Role created successfully.');
    }


    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'string'
        ]);

        $role->update(['name' => $request->name]);

        // sync, or empty array if none selected
        $role->syncPermissions($request->permissions ?? []);

        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return redirect()->route('roles.index')->with('success', 'Role deleted successfully.');
    }

    /**
     * Optional: separate endpoint to assign permissions (if you use it elsewhere)
     */
    public function assignPermissions(Request $request, Role $role)
    {
        $permissions = $request->input('permissions', []);
        $role->syncPermissions($permissions);

        return redirect()->route('roles.index')->with('success', 'Permissions updated successfully.');
    }
}
