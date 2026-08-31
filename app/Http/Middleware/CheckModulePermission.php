<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckModulePermission
{
    public function handle($request, Closure $next, $permission)
    {
        // If user is not logged in
        if (!auth()->user()) {
            abort(403, 'Unauthorized action.');
        }

        // Map actions to logical permissions for CRUD routes
        $map = [
            'store'   => 'create',
            'update'  => 'edit',
            'destroy' => 'delete',
        ];

        $action = $request->route()->getActionMethod();

        // If the permission already contains a dot (e.g. reports.inventory), use as-is
        if (str_contains($permission, '.')) {
            $finalPermission = $permission;
        } else {
            // For CRUD routes, map the action
            $base = $permission; // e.g. 'projects'
            $mappedAction = $map[$action] ?? $action;
            $finalPermission = "$base.$mappedAction";
        }

        if (!auth()->user()->can($finalPermission)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
