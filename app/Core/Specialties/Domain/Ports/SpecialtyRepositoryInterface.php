<?php
namespace App\Core\Specialties\Domain\Ports;

interface SpecialtyRepositoryInterface {
    public function getAllActive(): array; 
    public function findById(int $id): ?object;
    public function store(array $data): bool;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool; 
}