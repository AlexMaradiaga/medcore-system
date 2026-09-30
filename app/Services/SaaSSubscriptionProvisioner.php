<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaaSSubscriptionProvisioner
{
    public function provisionDoctor(int $usuarioId): void
    {
        $exists = DB::table('Sistema_Suscripciones_SaaS')
            ->where('TipoSuscriptor', 'Doctor')
            ->where('UsuarioID', $usuarioId)
            ->exists();

        if (!$exists) {
            DB::table('Sistema_Suscripciones_SaaS')->insert([
                'EntidadID' => null,
                'UsuarioID' => $usuarioId,
                'TipoSuscriptor' => 'Doctor',
                'TipoPlan' => 'Gratis',
                'EstadoSuscripcion' => 'ACTIVA',
                'FechaVencimiento' => '9999-12-31 00:00:00',
                'TokenPasarela' => null,
                'ActualizadoEn' => now(),
            ]);
        }
    }

    public function provisionEntity(int $entidadId): void
    {
        $entidad = DB::table('Entidades')->where('EntidadID', $entidadId)->first();
        if (!$entidad || !in_array($entidad->TipoEntidad, ['Clinica', 'Farmacia', 'Laboratorio'], true)) {
            throw new \RuntimeException('La entidad no corresponde a un tipo suscribible.');
        }

        $usuarioId = DB::table('Usuarios')
            ->where('EntidadID', $entidadId)
            ->where('Estado', 1)
            ->orderBy('UsuarioID')
            ->value('UsuarioID');

        $exists = DB::table('Sistema_Suscripciones_SaaS')
            ->where('TipoSuscriptor', $entidad->TipoEntidad)
            ->where('EntidadID', $entidadId)
            ->exists();

        if (!$exists) {
            DB::table('Sistema_Suscripciones_SaaS')->insert([
                'EntidadID' => $entidadId,
                'UsuarioID' => $usuarioId,
                'TipoSuscriptor' => $entidad->TipoEntidad,
                'TipoPlan' => 'Gratis',
                'EstadoSuscripcion' => 'ACTIVA',
                'FechaVencimiento' => '9999-12-31 00:00:00',
                'TokenPasarela' => null,
                'ActualizadoEn' => now(),
            ]);
        }
    }
}
