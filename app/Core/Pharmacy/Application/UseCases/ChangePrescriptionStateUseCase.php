<?php
namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;

class ChangePrescriptionStateUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $repository
    ) {}

    public function execute(int $id, string $nuevoEstado, int $farmaciaId): void
    {
        // Cambiado findById por findPrescriptionById
        $prescription = $this->repository->findPrescriptionById($id);

        if (!$prescription) {
            throw new \DomainException('La receta ya fue surtida previamente o no existe.');
        }

        $prescription->cambiarEstado($nuevoEstado, $farmaciaId);
        $this->repository->updateState($id, $nuevoEstado, $farmaciaId);
    }
}
