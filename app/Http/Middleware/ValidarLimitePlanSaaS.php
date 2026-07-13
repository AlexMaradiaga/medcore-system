<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidarLimitePlanSaaS
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $recurso
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $recurso): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $suscripcion = DB::table('Sistema_Suscripciones_SaaS')
            ->where('UsuarioID', $user->UsuarioID)
            ->first();

        $plan = $suscripcion ? $suscripcion->TipoPlan : 'Gratis';
        $estado = $suscripcion ? $suscripcion->EstadoSuscripcion : 'Activo';

        if ($estado !== 'Activo') {
            return response()->json([
                'status' => 'subscription_expired',
                'message' => 'La suscripción en MedCore Global se encuentra vencida o suspendida.'
            ], 403);
        }

        if ($plan === 'Gratis' && $recurso === 'pacientes') {
            $conteoPacientes = DB::table('Pacientes')
                ->where('entidad_id', $user->EntidadID)
                ->where('Estado', 1)
                ->count();

            if ($conteoPacientes >= 10) {
                return response()->json([
                    'status' => 'limit_reached',
                    'message' => 'Límite excedido: El Plan Básico Gratis solo permite hasta 10 pacientes activos en su clínica.'
                ], 403);
            }
        }

        return $next($request);
    }
}
