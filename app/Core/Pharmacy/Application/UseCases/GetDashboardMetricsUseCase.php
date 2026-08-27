<?php

namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;

class GetDashboardMetricsUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $pharmacyRepository
    ) {}

    public function execute(?int $farmaciaId = null, int $page = 1, int $perPage = 10, int $yaCanjeada = 0): array
    {
        return $this->pharmacyRepository->getDashboardMetrics($farmaciaId, $page, $perPage, $yaCanjeada);
    }
}
