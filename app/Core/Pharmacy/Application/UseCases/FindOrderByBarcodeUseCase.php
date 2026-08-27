<?php
namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Entities\PrescriptionOrder;
use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;

class FindOrderByBarcodeUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $repository
    ) {}

    public function execute(string $code): ?PrescriptionOrder
    {
        return $this->repository->findByBarcode($code);
    }
}
