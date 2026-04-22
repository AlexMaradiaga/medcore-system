<?php
namespace App\Core\Patients\Domain\Ports;

interface PatientRepositoryInterface {
    // Para el Listado (Index)
    public function obtenerTodos(): array;

    // Para el Registro (Store)
    public function registrar(array $datos): bool;

    // Para la Edición (Update) 
    public function update(int $id, array $datos): bool;

    // Para la Baja Lógica (Destroy) 
    public function delete(int $id): bool;
}