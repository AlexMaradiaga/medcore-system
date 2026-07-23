<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidarLimitePlanSaaS
{
    /**
     * Tiempo de caché de la suscripción (5 minutos)
     */
    private const CACHE_MINUTES = 5;

    /**
     * Límites por plan.
     * En el futuro puedes mover esto a config/saas.php
     */
    private const PLAN_LIMITS = [
        'Gratis' => [
            'pacientes' => 10,
        ],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $recurso): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado.'
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Obtener la suscripción desde caché
        |--------------------------------------------------------------------------
        */

        $suscripcion = Cache::remember(
            "suscripcion_usuario_{$user->UsuarioID}",
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($user) {
                return DB::table('Sistema_Suscripciones_SaaS')
                    ->where('UsuarioID', $user->UsuarioID)
                    ->first();
            }
        );

        $plan = $suscripcion?->TipoPlan ?? 'Gratis';
        $estado = $suscripcion?->EstadoSuscripcion ?? 'Activo';

        /*
        |--------------------------------------------------------------------------
        | Validar estado de la suscripción
        |--------------------------------------------------------------------------
        */

        if ($estado !== 'Activo') {
            return response()->json([
                'status' => 'subscription_expired',
                'message' => 'La suscripción de MedCore Global se encuentra vencida o suspendida.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validar si el plan tiene límite para este recurso
        |--------------------------------------------------------------------------
        */

        $limit = self::PLAN_LIMITS[$plan][$recurso] ?? null;

        if ($limit === null) {
            return $next($request);
        }

        /*
        |--------------------------------------------------------------------------
        | Pacientes
        |--------------------------------------------------------------------------
        */

        if ($recurso === 'pacientes') {

            $cantidad = DB::table('Pacientes')
                ->where('EntidadID', $user->EntidadID)
                ->where('Estado', 1)
                ->count();

            if ($cantidad >= $limit) {

                return response()->json([
                    'status' => 'limit_reached',
                    'message' => "Ha alcanzado el límite de {$limit} pacientes permitido por su plan {$plan}."
                ], 403);
            }
        }

        return $next($request);
    }
}
