<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Log;

class AuthenticateMultiple
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $authorizationHeader = $request->header('Authorization');

        // 1. Cek otentikasi menggunakan Sanctum
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user(); // Ambil pengguna dari Auth guard

            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Eager load relasi 'role'
            $user->load('role');

            // Cek jika pengguna memiliki peran yang benar
            if (!in_array($user->role->role_name, $roles)) {
                $resource = new ResponseResource(false, "anda tidak berhak mengakses halaman ini", null);
                return $resource->response()->setStatusCode(403);
            }

            // Set user secara manual untuk memastikan auth()->id() dapat diakses di luar middleware
            Auth::login($user);

            return $next($request);
        }

        // 2. Cek otentikasi menggunakan API key
        if ($authorizationHeader && strpos($authorizationHeader, 'Bearer ') !== 0) {
            $apiKey = $authorizationHeader;

            $user = User::where('api_key', $apiKey)->first();
            if ($user) {
                // Set user secara manual untuk permintaan ini
                Auth::login($user);

                return $next($request);
            }
        }

        // 3. Jika kedua metode otentikasi gagal
        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
