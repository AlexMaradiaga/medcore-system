<?php

namespace App\Core\Auth\Infrastructure\Repositories;

use App\Core\Auth\Domain\Entities\Usuario;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    public function updatePassword(int $usuarioId, string $oldPassword, string $newPassword): bool
    {
        $usuario = DB::table('Usuarios')->where('UsuarioID', $usuarioId)->first();

        if (!$usuario) {
            throw new \Exception("Usuario no encontrado.");
        }

        if (!Hash::check($oldPassword, $usuario->PasswordHash)) {
            throw new \Exception("La contraseña actual es incorrecta.");
        }

        return DB::table('Usuarios')
            ->where('UsuarioID', $usuarioId)
            ->update([
                'PasswordHash' => Hash::make($newPassword),
            ]);
    }
}
