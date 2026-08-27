<?php
namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;

class DispensePrescriptionUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $repository
    ) {}

    public function execute(int $id, float $precioTotal, int $farmaciaId): array
    {
        // Cambiado findById por findPrescriptionById
        $prescription = $this->repository->findPrescriptionById($id);

        if (!$prescription) {
            throw new \DomainException('Receta no encontrada.');
        }

        $comisionMonto = $prescription->surtir($precioTotal, $farmaciaId);
        $this->repository->dispense($prescription, $precioTotal, $comisionMonto, $farmaciaId);

        return [
            'comision_medgo' => $comisionMonto,
            'monto_facturado' => $precioTotal
        ];
    }
}
