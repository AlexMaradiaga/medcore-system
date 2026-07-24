<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class ValidarLimitePlanSaaS
{
    /**
     * Tiempo de caché de la suscripción (5 minutos)
     */
    private const CACHE_MINUTES = 5;

    /**
     * Límites por plan.
     */
    private const PLAN_LIMITS = [
        'Gratis' => [
            'pacientes' => 10,
        ],
        'Basico' => [
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

        // Obtener el ID del usuario soportando ambas convenciones (UsuarioID o id)
        $userId = $user->UsuarioID ?? $user->id;

        /*
        |--------------------------------------------------------------------------
        | Obtener la suscripción desde caché usando UsuarioID
        |--------------------------------------------------------------------------
        */
        $suscripcion = Cache::remember(
            "suscripcion_usuario_{$userId}",
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($userId) {
                return DB::table('Sistema_Suscripciones_SaaS')
                    ->where('UsuarioID', $userId)
                    ->first();
            }
        );

        // Mapeo flexible de Plan (soporta PlanAsignado o TipoPlan)
        $plan = $suscripcion?->PlanAsignado ?? $suscripcion?->TipoPlan ?? 'Gratis';

        // Mapeo flexible de Estado (soporta EstadoSaaS como int/string o EstadoSuscripcion)
        $estadoRaw = $suscripcion?->EstadoSaaS ?? $suscripcion?->EstadoSuscripcion ?? 'Activo';
        $esActivo = ($estadoRaw == 1 || strtolower((string)$estadoRaw) === 'activo');

        /*
        |--------------------------------------------------------------------------
        | Validar estado de la suscripción
        |--------------------------------------------------------------------------
        */
        if (!$esActivo) {
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
        | Pacientes (Validación por DoctorID asociado al Usuario logueado)
        |--------------------------------------------------------------------------
        */
        if ($recurso === 'pacientes') {
            // Obtenemos el DoctorID correcto a partir del UsuarioID autenticado
            $doctorId = DB::table('Doctores')->where('UsuarioID', $userId)->value('DoctorID');

            if (!$doctorId) {
                return $next($request); // Si no es doctor, dejamos pasar
            }

            $fechaLimite = Carbon::now()->subDays(90);

            $cantidad = DB::table('Citas')
                ->where('DoctorID', $doctorId)
                ->where('FechaHora', '>=', $fechaLimite)
                ->whereIn('EstadoCita', ['Completada', 'Finalizada'])
                ->distinct('PacienteID')
                ->count('PacienteID');

            if ($cantidad >= $limit) {
                return response()->json([
                    'status' => 'limit_reached',
                    'message' => "Ha alcanzado el límite de {$limit} pacientes activos (en los últimos 90 días) permitido por su plan {$plan}."
                ], 403);
            }
        }

        return $next($request);
    }
}
