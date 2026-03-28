<?php

namespace App\Core\Auth\Domain\Entities;

class Usuario
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly int $rolId,
        public readonly ?int $entidadId = null
    ) {}
}