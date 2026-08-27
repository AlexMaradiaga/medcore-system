<?php
namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;

class SearchPrescriptionUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $repository
    ) {}

    public function execute(string $criterio): array
    {
        $criterioLimpio = trim($criterio);
        $recetas = $this->repository->searchByCriteria($criterioLimpio);

        return array_map(fn($receta) => $receta->toArray(), $recetas);
    }
}
