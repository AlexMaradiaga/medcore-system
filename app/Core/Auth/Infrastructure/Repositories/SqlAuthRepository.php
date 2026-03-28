<?php

namespace App\Core\Auth\Infrastructure\Repositories;

use App\Core\Auth\Domain\Entities\Usuario;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlAuthRepository implements AuthRepositoryInterface
{
    public function findByEmail(string $email): ?Usuario
    {
        // Usamos Query Builder apuntando a tu tabla real
        $record = DB::table('Usuarios')
            ->where('Email', $email)
            ->where('Estado', 1)
            ->first();

        if (!$record) return null;

        return new Usuario(
            id: $record->UsuarioID,
            email: $record->Email,
            passwordHash: $record->PasswordHash,
            rolId: $record->RolID,
            entidadId: $record->EntidadID
        );
    }
}