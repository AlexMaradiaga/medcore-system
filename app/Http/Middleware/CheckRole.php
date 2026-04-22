<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        // 1. Verificamos que el usuario esté logueado
        if (!$request->user()) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ($request->user()->rol->NombreRol !== $role) {
            return response()->json([
                'status' => 'error',
                'message' => "Acceso denegado. Se requiere el rol: {$role}"
            ], 403);
        }

        return $next($request);
    }
}
