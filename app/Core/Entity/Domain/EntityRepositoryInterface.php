<?php

namespace App\Core\Entity\Domain;

use Illuminate\Http\Request;

interface EntityRepositoryInterface
{
    public function getAll(?string $tipo = null): array;
    public function getEntidadesPublicas(?string $tipo = null): array;
    public function registerInstitution(array $data, Request $request): int;
    public function approveEntity(int $id): bool;
}
