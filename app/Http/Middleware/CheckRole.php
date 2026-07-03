<?php

namespace App\Http\Middleware;

use App\Http\Resources\ResponseResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if(!in_array($request->user()->role->role_name, $roles)){
            
            $resource = new ResponseResource(false, "anda tidak berhak mengakses halaman ini", null);
            return $resource->response()->setStatusCode(403);
        }
        return $next($request);
    }
}
