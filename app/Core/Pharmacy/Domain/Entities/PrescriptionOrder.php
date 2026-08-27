<?php
namespace App\Core\Pharmacy\Domain\Entities;

use DomainException;

class PrescriptionOrder
{
    public function __construct(
        private string $id,
        private string $patientName,
        private string $status, // 'pending', 'in_preparation', 'dispensed'
        private array $medications,
        private ?string $barcode = null
    ) {}

    public function dispense(): void
    {
        if ($this->status === 'dispensed') {
            throw new DomainException('La receta ya ha sido despachada previamente.');
        }

        // Lógica de dominio: cambiar estado
        $this->status = 'dispensed';
    }

    public function getId(): string { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getPatientName(): string { return $this->patientName; }
    public function getMedications(): array { return $this->medications; }
    public function getBarcode(): ?string { return $this->barcode; }
}
