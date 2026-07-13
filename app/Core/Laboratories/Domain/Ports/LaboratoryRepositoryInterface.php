<?php

namespace App\Core\Laboratories\Domain\Ports;

interface LaboratoryRepositoryInterface
{
    public function getCatalogoExamenes(): array;
    public function getOrdenesPorPaciente(int $pacienteId): array;
    public function getResultadosPorOrden(int $ordenId): array;
}
