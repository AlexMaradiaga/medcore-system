<?php
namespace App\Core\Doctors\Domain\Ports;

interface DoctorRepositoryInterface {

    public function registrar(array $datos): bool;

    public function getAllActive(array $filters = []): array;

    public function obtenerPorEspecialidad(int $especialidadId): array;

    public function update(int $id, array $datos): bool;

    public function delete(int $id): bool;
}
