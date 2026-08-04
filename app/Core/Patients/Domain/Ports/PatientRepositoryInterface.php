<?php
namespace App\Core\Patients\Domain\Ports;

interface PatientRepositoryInterface {
    public function registrar(array $datos): bool;
    public function obtenerTodos(): array;
    public function update(int $id, array $datos): bool;
    public function delete(int $id): bool;
    public function obtenerPorUsuarioId(int $usuarioId): ?object;
    public function obtenerDependientesPorTutorId(int $tutorId): array;
}
