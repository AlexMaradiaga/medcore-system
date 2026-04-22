<?php
namespace App\Core\Clinics\Domain\Ports;

interface ClinicRepositoryInterface {
    public function getAllActive(): array;
    public function store(array $data): bool;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool; 
}