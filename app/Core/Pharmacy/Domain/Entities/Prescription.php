<?php
namespace App\Core\Pharmacy\Domain\Entities;

use DomainException;

class Prescription
{
    public function __construct(
        private int $id,
        private string $codigoCanje,
        private string $nombreMedicamento,
        private string $dosis,
        private string $indicaciones,
        private string $estadoReceta,
        private bool $yaCanjeada,
        private string $fechaEmision,
        private ?float $precioTotal,
        private string $pacienteNombre,
        private string $pacienteDNI,
        private string $pacienteTelefono,
        private string $medicoTratante,
        private int $doctorId,
        private ?int $farmaciaId = null
    ) {}

    public function cambiarEstado(string $nuevoEstado, int $farmaciaId): void
    {
        if ($this->yaCanjeada || $this->estadoReceta === 'Surtida') {
            throw new DomainException('La receta ya fue surtida previamente o no se puede modificar.');
        }

        $this->estadoReceta = $nuevoEstado;
        $this->farmaciaId = $farmaciaId;
    }

    public function surtir(float $precioTotal, int $farmaciaId): float
    {
        if ($this->yaCanjeada || $this->estadoReceta === 'Surtida') {
            throw new DomainException('Esta receta médica ya fue surtida anteriormente.');
        }

        $this->estadoReceta = 'Surtida';
        $this->yaCanjeada = true;
        $this->precioTotal = $precioTotal;
        $this->farmaciaId = $farmaciaId;

        // Regla de Dominio: Comisión SaaS fija del 3%
        return round($precioTotal * 0.03, 2);
    }

    // Getters para exportación de estado o lectura en repositorios/DTOs
    public function getId(): int { return $this->id; }
    public function getCodigoCanje(): string { return $this->codigoCanje; }
    public function getEstadoReceta(): string { return $this->estadoReceta; }
    public function isYaCanjeada(): bool { return $this->yaCanjeada; }
    public function getDoctorId(): int { return $this->doctorId; }
    public function getFarmaciaId(): ?int { return $this->farmaciaId; }
    public function toArray(): array
    {
        return [
            'RecetaID' => $this->id,
            'CodigoCanje' => $this->codigoCanje,
            'NombreMedicamento' => $this->nombreMedicamento,
            'Dosis' => $this->dosis,
            'Indicaciones' => $this->indicaciones,
            'EstadoReceta' => $this->estadoReceta,
            'YaCanjeada' => $this->yaCanjeada ? 1 : 0,
            'FechaEmision' => $this->fechaEmision,
            'PrecioTotal' => $this->precioTotal,
            'Paciente' => $this->pacienteNombre,
            'PacienteDNI' => $this->pacienteDNI,
            'PacienteTelefono' => $this->pacienteTelefono,
            'MedicoTratante' => $this->medicoTratante,
            'DoctorID' => $this->doctorId
        ];
    }
}
