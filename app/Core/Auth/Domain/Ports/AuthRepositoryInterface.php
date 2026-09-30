<?php

namespace App\Core\Auth\Domain\Ports;

use App\Core\Auth\Domain\Entities\Usuario;

interface AuthRepositoryInterface
{
    public function findByEmail(string $email): ?Usuario;
    public function updatePassword(string $email, string $newPassword): bool;
    public function updateAuthenticatedProfile(
        int $userId,
        string $currentPassword,
        ?string $email,
        ?string $newPassword
    ): array;
    public function registerDoctor(array $data, array $filePaths): int;
    public function buildSessionData(Usuario $usuario): array;
}
