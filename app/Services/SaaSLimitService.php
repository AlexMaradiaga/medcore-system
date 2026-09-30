<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaaSLimitService
{
    /** Obtiene el plan del titular normalizado: Doctor por UsuarioID o entidad. */
    public function obtenerPlanUsuario(int $usuarioId): string
    {
        $doctor = DB::table('Doctores')->where('UsuarioID', $usuarioId)->where('Estado', 1)->exists();
        $entidadId = DB::table('Usuarios')->where('UsuarioID', $usuarioId)->value('EntidadID');
        $tipoEntidad = $entidadId
            ? DB::table('Entidades')->where('EntidadID', $entidadId)->where('Estado', 1)->value('TipoEntidad')
            : null;

        $query = DB::table('Sistema_Suscripciones_SaaS')
            ->where('TipoSuscriptor', $doctor ? 'Doctor' : $tipoEntidad)
            ->whereIn(DB::raw("UPPER(LTRIM(RTRIM(EstadoSuscripcion)))"), ['ACTIVO', 'ACTIVA', 'VIGENTE'])
            ->where(function ($builder) {
                $builder->whereNull('FechaVencimiento')->orWhere('FechaVencimiento', '>=', Carbon::now());
            });

        $doctor ? $query->where('UsuarioID', $usuarioId) : $query->where('EntidadID', $entidadId);
        $plan = $query->orderByDesc('SuscripcionSaaSID')->value('TipoPlan');

        return $plan ? strtolower(trim($plan)) : 'sin plan';
    }

    /**
     * Calcula pacientes activos en los últimos 90 días (Consulta, Receta, Orden Lab o Expediente).
     */
    public function obtenerPacientesActivosCount(int $usuarioId): int
    {
        $fechaLimite = Carbon::now()->subDays(90);

        $doctorId = DB::table('Doctores')
            ->where('UsuarioID', $usuarioId)
            ->value('DoctorID');

        if (!$doctorId) {
            return 0;
        }

        return DB::table('Pacientes as P')
            ->join('Citas as C', 'P.PacienteID', '=', 'C.PacienteID')
            ->where('C.DoctorID', $doctorId)
            ->where('P.Estado', 1)
            ->where(function ($query) use ($fechaLimite) {
                $query->where('P.updated_at', '>=', $fechaLimite)
                    ->orWhereExists(function ($subQuery) use ($fechaLimite) {
                        $subQuery->select(DB::raw(1))
                            ->from('Consultas as CON')
                            ->whereColumn('CON.CitaID', 'C.CitaID')
                            ->where('CON.FechaCreacion', '>=', $fechaLimite);
                    })
                    ->orWhereExists(function ($subQuery) use ($fechaLimite) {
                        $subQuery->select(DB::raw(1))
                            ->from('Recetas as R')
                            ->join('Consultas as CON2', 'R.ConsultaID', '=', 'CON2.ConsultaID')
                            ->whereColumn('CON2.CitaID', 'C.CitaID')
                            ->where('R.FechaEmision', '>=', $fechaLimite);
                    })
                    ->orWhereExists(function ($subQuery) use ($fechaLimite) {
                        $subQuery->select(DB::raw(1))
                            ->from('OrdenesLaboratorio as OL')
                            ->whereColumn('OL.PacienteID', 'P.PacienteID')
                            ->where('OL.FechaOrden', '>=', $fechaLimite);
                    });
            })
            ->distinct('P.PacienteID')
            ->count('P.PacienteID');
    }

    public function obtenerEstadoSaaSCompleto(int $usuarioId): array
    {
        $user = DB::table('Usuarios')->where('UsuarioID', $usuarioId)->first();
        $planSlug = $this->obtenerPlanUsuario($usuarioId);
        $pacientesActivos = $this->obtenerPacientesActivosCount($usuarioId);

        $beneficiosFounderGlobales = DB::table('system_settings')
            ->where('setting_key', 'founder_benefits_enabled')
            ->value('setting_value');

        $beneficiosActivos = filter_var($beneficiosFounderGlobales ?? true, FILTER_VALIDATE_BOOLEAN);

        $permitido = true;
        $advertencia = false;
        $mensaje = 'Operación permitida.';

        if ($planSlug === 'pro') {
            if ($pacientesActivos >= 200) {
                $permitido = false;
                $mensaje = 'Ha alcanzado el límite máximo de 200 pacientes activos para el Plan Pro. Debe actualizar al Plan Elite para continuar registrando pacientes.';
            } elseif ($pacientesActivos >= 190) {
                $advertencia = true;
                $mensaje = 'Está cerca de alcanzar el límite de 200 pacientes activos en el Plan Pro.';
            }
        } elseif (in_array($planSlug, ['basico', 'gratis'])) {
            if ($pacientesActivos >= 10) {
                $permitido = false;
                $mensaje = 'Ha alcanzado el límite de 10 pacientes activos de su Plan Básico/Gratuito.';
            }
        }

        return [
            'plan_actual'                  => $planSlug,
            'pacientes_activos'            => $pacientesActivos,
            'permitido_nuevo_paciente'     => $permitido,
            'advertencia_limite'           => $advertencia,
            'mensaje'                      => $mensaje,
            'es_founder'                   => (bool) ($user->EsFounder ?? false),
            'founder_nivel'                => $user->NivelFounder ?? null,
            'beneficios_founder_globales'  => $beneficiosActivos,
        ];
    }
}
