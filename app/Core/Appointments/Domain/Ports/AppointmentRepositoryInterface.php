<?php

namespace App\Core\Appointments\Domain\Ports;

interface AppointmentRepositoryInterface
{public function create(array $data): bool;
    public function getPendingByDoctor(int $doctorId): array;
    public function getHistoryByPatient(int $pacienteId): array;
    public function reschedule(int $citaId, string $nuevaFechaHora): bool;
    public function cancel(int $citaId, string $motivoCancelacion = null): bool;
    public function complete(array $data): bool;
    public function getDoctorAgenda(int $doctorId): array;
    public function getDetailedReport(array $filters): array;
    public function getStats(): array;
    public function getCatalogoExamenFisico(): array;
}
