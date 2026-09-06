<?php

namespace App\Core\Payments\Domain\Ports;

interface PaymentRepositoryInterface
{
    public function obtenerCatalogoPrecios(?int $doctorId, int $usuarioId): array;

    public function obtenerPerfilUbicacion(?int $doctorId, int $usuarioId): ?object;

    public function guardarCatalogoYUbicacion(array $data, int $usuarioId): bool;

    public function procesarPago(array $data): bool;
}
