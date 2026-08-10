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

    public function updatePassword(
        string $email,
        string $newPassword
    ): bool {
        $usuario = DB::table('Usuarios')
            ->where('Email', $email)
            ->first();

        if (!$usuario) {
            throw new \Exception(
                "No existe ninguna cuenta asociada a este correo."
            );
        }

        return DB::table('Usuarios')
            ->where('UsuarioID', $usuario->UsuarioID)
            ->update([
                'PasswordHash' => Hash::make($newPassword),
            ]) > 0;
    }
}
