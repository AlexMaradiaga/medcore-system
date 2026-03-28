<?php

namespace App\Core\Auth\Domain\Ports;

use App\Core\Auth\Domain\Entities\Usuario;

interface AuthRepositoryInterface
{
    public function findByEmail(string $email): ?Usuario;
}