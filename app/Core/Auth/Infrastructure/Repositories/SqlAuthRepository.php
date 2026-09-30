<?php

namespace App\Core\Auth\Infrastructure\Repositories;

use App\Core\Auth\Domain\Entities\Usuario;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Exception;

class SqlAuthRepository implements AuthRepositoryInterface
{
    public function findByEmail(string $email): ?Usuario
    {
        $record = DB::table('Usuarios')
            ->where('Email', $email)
            ->where('Estado', 1)
            ->first();

        if (!$record) return null;

        return new Usuario(
            $record->UsuarioID,
            $record->Email,
            $record->PasswordHash,
            $record->RolID,
            $record->EntidadID
        );
    }

    public function updatePassword(string $email, string $newPassword): bool
    {
        $usuario = DB::table('Usuarios')
            ->where('Email', $email)
            ->first();

        if (!$usuario) {
            throw new Exception("No existe ninguna cuenta asociada a este correo.");
        }

        return DB::table('Usuarios')
            ->where('UsuarioID', $usuario->UsuarioID)
            ->update([
                'PasswordHash' => Hash::make($newPassword),
            ]) > 0;
    }

    public function updateAuthenticatedProfile(
        int $userId,
        string $currentPassword,
        ?string $email,
        ?string $newPassword
    ): array {
        return DB::transaction(function () use ($userId, $currentPassword, $email, $newPassword) {
            $usuario = DB::table('Usuarios')
                ->where('UsuarioID', $userId)
                ->lockForUpdate()
                ->first();

            if (!$usuario) {
                throw new Exception('La cuenta autenticada no existe.');
            }

            if (!Hash::check($currentPassword, $usuario->PasswordHash)) {
                throw new Exception('La contraseña actual no es correcta.');
            }

            $nuevoCorreo = $email ? mb_strtolower(trim($email)) : $usuario->Email;

            $correoOcupado = DB::table('Usuarios')
                ->whereRaw('LOWER(Email) = ?', [$nuevoCorreo])
                ->where('UsuarioID', '<>', $userId)
                ->exists();

            if ($correoOcupado) {
                throw new Exception('El correo indicado ya está registrado por otra cuenta.');
            }

            $cambios = [];
            if ($nuevoCorreo !== $usuario->Email) {
                $cambios['Email'] = $nuevoCorreo;
            }

            if ($newPassword) {
                $cambios['PasswordHash'] = Hash::make($newPassword);
            }

            if ($cambios !== []) {
                DB::table('Usuarios')
                    ->where('UsuarioID', $userId)
                    ->update($cambios);
            }

            return [
                'email' => $nuevoCorreo,
                'password_changed' => $newPassword !== null && $newPassword !== '',
            ];
        });
    }

   public function registerDoctor(array $data, array $filePaths): int
    {
        $hablaIngles = !empty($data['habla_ingles']) ? 1 : 0;
        $disponibleDomicilio = !empty($data['disponible_domicilio']) ? 1 : 0;
        $rolDoctorId = DB::table('Roles')->where('NombreRol', 'Doctor')->value('RolID') ?? 2;
        $passwordHash = Hash::make($data['password']);

        // Invocación posicional compatible con PDO SQL Server
        DB::statement("
            EXEC [dbo].[sp_RegistrarDoctor]
                ?, ?, ?, NULL,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
        ", [
            $data['email'],
            $passwordHash,
            $rolDoctorId,
            (int) $data['especialidad_id'],
            $data['nombre'],
            $data['apellido'],
            $data['numero_colegiado'],
            $filePaths['fotografia'] ?? null,
            $filePaths['titulo_medico'] ?? null,
            $filePaths['titulo_especialista'] ?? null,
            $filePaths['constancia_colegio'] ?? null,
            $filePaths['dni'] ?? null,
            $data['nacionalidad'] ?? 'Hondureña',
            $hablaIngles,
            $data['otros_idiomas'] ?? null,
            $disponibleDomicilio,
            $data['latitud'] ?? null,
            $data['longitud'] ?? null,
            $data['direccion_consultorio'] ?? null
        ]);

        return 1;
    }

    public function buildSessionData(Usuario $usuario): array
    {
        $userRecord = DB::table('Usuarios')->where('UsuarioID', $usuario->id)->first();

        if (!$userRecord) {
            throw new Exception('Usuario no encontrado en la base de datos.');
        }

        if ($userRecord->Estado == 0) {
            throw new Exception('Sus credenciales institucionales han sido desactivadas por el administrador.', 403);
        }

        $rolDoctorId = DB::table('Roles')->where('NombreRol', 'Doctor')->value('RolID');

        $doctor = null;
        if ($usuario->rolId == $rolDoctorId) {
            $doctor = DB::table('Doctores')->where('UsuarioID', $usuario->id)->first();
            if ($doctor && $doctor->EsVerificado == 0) {
                throw new Exception('Tu cuenta está en proceso de validación por parte de la administración. Te notificaremos cuando sea aprobada.', 403);
            }
        }

        $entidad = null;
        if ($userRecord->EntidadID) {
            $entidad = DB::table('Entidades')->where('EntidadID', $userRecord->EntidadID)->first();
        }

        $tipoSuscriptor = $doctor ? 'Doctor' : ($entidad->TipoEntidad ?? null);
        $suscripcion = null;
        if ($tipoSuscriptor) {
            $suscripcionQuery = DB::table('Sistema_Suscripciones_SaaS')
                ->where('TipoSuscriptor', $tipoSuscriptor);

            $tipoSuscriptor === 'Doctor'
                ? $suscripcionQuery->where('UsuarioID', $usuario->id)
                : $suscripcionQuery->where('EntidadID', $userRecord->EntidadID);

            $suscripcion = $suscripcionQuery->orderByDesc('SuscripcionSaaSID')->first();
        }

        $plan = $suscripcion->PlanAsignado ?? $suscripcion->TipoPlan ?? null;
        if (!$plan) {
            $plan = 'Sin plan';
        }

        return [
            'id'           => $usuario->id,
            'email'        => $usuario->email,
            'rol_id'       => $usuario->rolId,
            'entidad_id'   => $userRecord->EntidadID,
            'tipo_entidad' => $entidad ? $entidad->TipoEntidad : null,
            'tipo_suscriptor' => $tipoSuscriptor,
            'plan'         => $plan,
            'estado_saas'  => $suscripcion->EstadoSuscripcion ?? null
        ];
    }
}
