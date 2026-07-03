<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && !$request->has('log_created') && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $user = Auth::user();
            $halaman = $request->path();
            $method = $request->method();
            
            // Tentukan pesan aksi berdasarkan metode HTTP
            $pesan = match($method) {
                'POST' => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'read',
            };

            // Simpan log ke database
            UserLog::create([
                'user_id' => $user->id,
                'name_user' => $user->name,
                'page' => $halaman,
                'info' => $pesan,
            ]);
        }

        return $next($request);
    }
}
