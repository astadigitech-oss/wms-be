<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::all();
        return new ResponseResource(true, "List roles", $roles);
    }

    public function show($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return new ResponseResource(false, "Role not found", null);
        }

        return new ResponseResource(true, "Role details", $role);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_name' => 'required|min:2|unique:roles,role_name',
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation error", $validator->errors());
        }

        $role = Role::create(['role_name' => $request->role_name]);

        return new ResponseResource(true, "Role created successfully", $role);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'role_name' => 'required|min:2|unique:roles,role_name,' . $id,
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation error", $validator->errors());
        }

        $role = Role::find($id);

        if (!$role) {
            return new ResponseResource(false, "Role not found", null);
        }

        $role->update(['role_name' => $request->role_name]);

        return new ResponseResource(true, "Role updated successfully", $role);
    }

    public function destroy($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return new ResponseResource(false, "Role not found", null);
        }

        $role->delete();

        return new ResponseResource(true, "Role deleted successfully", null);
    }
}
