<?php

namespace App\Core\Pharmacy\Domain\Repositories;

use App\Core\Pharmacy\Domain\Entities\PrescriptionOrder;
use App\Core\Pharmacy\Domain\Entities\Prescription;

interface PharmacyRepositoryInterface
{
    // Métodos para Dashboard y Escáner Rápido
    public function getDashboardMetrics(?int $farmaciaId = null): array;
    public function findPendingOrders(array $filters = []): array;
    public function findByBarcode(string $code): ?PrescriptionOrder;
    public function saveOrder(PrescriptionOrder $order): void;

    // Métodos para Operatividad de Recetas y Surtido
    public function searchByCriteria(string $criterio): array;
    public function findPrescriptionById(int $id): ?Prescription;
    public function updateState(int $id, string $nuevoEstado, int $farmaciaId): bool;
    public function dispense(Prescription $prescription, float $precioTotal, float $comision, int $farmaciaId): void;
}
