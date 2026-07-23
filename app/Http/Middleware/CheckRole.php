<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Verifica que el usuario autenticado posea el rol requerido.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        // Verificar autenticación
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado.'
            ], 401);
        }

        // Verificar que el usuario tenga un rol asignado
        $userRole = $user->rol?->NombreRol;

        if (!$userRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        // Verificar que el rol coincida
        if ($userRole !== $role) {
            return response()->json([
                'status' => 'error',
                'message' => "Acceso denegado. Se requiere el rol: {$role}."
            ], 403);
        }

        return $next($request);
    }
}
