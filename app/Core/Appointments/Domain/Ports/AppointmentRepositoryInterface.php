<?php

namespace App\Core\Appointments\Domain\Ports;

interface AppointmentRepositoryInterface
{
    public function create(array $data): int;
    public function getPendingByDoctor(int $doctorId): array;
    public function getHistoryByPatient(int $id): array;
    public function getPrescriptions(int $usuarioId): array;
    public function getExams(int $usuarioId): array;
    public function reschedule(int $citaId, string $nuevaFechaHora): bool;
    public function cancel(int $citaId, ?string $motivoCancelacion = null): bool;
    public function descargarReceta($recetaId);
    public function getDoctorStats(int $usuarioId): array;
    public function getAppointmentsByDoctorUser(int $usuarioId): array;
    public function approve(int $citaId): bool;
    public function getCatalogoExamenFisico(): array;
    public function getDoctorAgenda(int $doctorId): array;
    public function getDetailedReport(array $filters): array;
    public function getStats(): array;
}
