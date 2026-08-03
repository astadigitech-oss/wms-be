<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\User;
use Illuminate\Http\Request;

class PublicUserController extends Controller
{
    /**
     * List user tanpa auth, hanya data penting.
     */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $users = User::query()
            ->when($q, function ($query) use ($q) {
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('name', 'LIKE', '%' . $q . '%')
                        ->orWhere('username', 'LIKE', '%' . $q . '%')
                        ->orWhere('email', 'LIKE', '%' . $q . '%');
                });
            })
            ->select('id', 'name', 'username', 'email', 'role_id')
            ->with('role:id,role_name')
            ->get();

        return new ResponseResource(true, "List User", $users);
    }
}