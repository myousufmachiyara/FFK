<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->get();

        $roles = Role::all();
        return view('users.index', compact('users', 'roles'));
    }

    public function store(Request $request)
    {
        // Validate request
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username',
            'password' => 'required|min:6|confirmed',
            'role' => 'required|exists:roles,id',
        ]);

        // Prepare data
        $data = $request->only(['name', 'username']);
        $data['password'] = Hash::make($request->password);

        // Create user
        $user = User::create($data);

        // Assign role
        $role = Role::findById($request->role);
        $user->assignRole($role);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function update(Request $request, $id)
    {
        // Validate the request
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username,' . $id,
            'role' => 'required|exists:roles,id',
        ]);

        // Find the user
        $user = User::findOrFail($id);

        // Get basic user data
        $data = $request->only(['name', 'username', 'signature']);

        try {
            // Update user data
            $user->update($data);
            \Log::info('User data updated:', ['user_id' => $user->id, 'data' => $data]);

            // Update user role
            $role = Role::findById($request->role);
            if ($role) {
                $user->syncRoles([$role->name]);
                \Log::info('User role updated:', [
                    'user_id' => $user->id,
                    'role' => $role->name
                ]);
            } else {
                \Log::error('Role not found:', ['role_id' => $request->role]);
                return redirect()->back()
                    ->with('error', 'Selected role not found.')
                    ->withInput();
            }

            return redirect()->route('users.index')
                ->with('success', 'User updated successfully.');

        } catch (\Exception $e) {
            \Log::error('User update error: ' . $e->getMessage(), [
                'user_id' => $user->id ?? $id,
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->with('error', 'Error updating user: ' . $e->getMessage())
                ->withInput();
        }
    }

    public function show($id)
    {
        $user = User::with('roles:id,name')->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'username' => $user->username,
                'roles'    => $user->roles->map(fn($r) => [
                'id'   => $r->id,
                'name' => $r->name
                ])
            ]
        ]);
    }

    public function changePassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user        = User::findOrFail($id);
        $currentUser = auth()->user();

        // Block if target is superadmin BUT current user is NOT superadmin
        // (superadmin can change their own password or any other user's password)
        if (
            ($user->id == 1 || $user->hasRole('superadmin')) &&
            !$currentUser->hasRole('superadmin')
        ) {
            return redirect()->back()
                ->with('error', 'Only a superadmin can change the superadmin password.');
        }

        $user->password = Hash::make($request->password);
        $user->save();

        Log::info('[User] Password changed by admin', [
            'target_user_id'  => $user->id,
            'changed_by'      => $currentUser->id,
        ]);

        return redirect()->route('users.index')
            ->with('success', 'Password changed successfully.');
    }


    public function changeMyPassword(Request $request)
    {
        // Validate — note: 'same' rule compares to another field in the request,
        // not to a Laravel 'confirmed' convention. This matches the form field names.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'current_password'     => 'required|string',
            'new_password'         => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ], [
            'new_password.min'                  => 'New password must be at least 8 characters.',
            'new_password_confirmation.same'    => 'Passwords do not match.',
            'current_password.required'         => 'Please enter your current password.',
        ]);
    
        // Return JSON so the AJAX handler in app.blade.php can read it
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()->all(),
            ], 422);
        }
    
        $user = auth()->user();
    
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'errors'  => ['Current password is incorrect.'],
            ], 422);
        }
    
        $user->password = Hash::make($request->new_password);
        $user->save();
    
        Log::info('[Auth] Password changed', ['user_id' => $user->id]);
    
        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    public function toggleActive($id)
    {
        $user = User::findOrFail($id);

        // Prevent super admin from being deactivated (assuming ID=1 or role 'super-admin')
        if ($user->id == 1 || $user->hasRole('super-admin')) {
            return redirect()->back()->with('error', 'Cannot deactivate the super admin user.');
        }

        $user->is_active = !$user->is_active;
        $user->save();

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "User {$status} successfully.");
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent deleting super admin by ID or role
        if ($user->id == 1 || $user->hasRole('superadmin')) {
            return redirect()->back()->with('error', 'Cannot delete the superadmin user.');
        }

        $user->delete();

        return redirect()->back()->with('success', 'User deleted successfully.');
    }
}
