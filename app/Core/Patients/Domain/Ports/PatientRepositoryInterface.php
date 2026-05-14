<?php
namespace App\Core\Patients\Domain\Ports;

interface PatientRepositoryInterface {
    public function obtenerTodos(): array;

    public function registrar(array $datos): bool;

    public function update(int $id, array $datos): bool;

    public function delete(int $id): bool;
}
