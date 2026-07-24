<?php
namespace App\Core\SaaS\Domain\Ports;

interface SaaSRepositoryInterface
{
    public function actualizarPlan(int $usuarioId, string $tipoPlan, int $diasVigencia, string $tokenPasarela): bool;
    public function obtenerMonitoreo(): array;
    public function obtenerPreciosDoctor(int $doctorId): array;
    public function guardarPrecioServicio(array $datos): bool;
}
