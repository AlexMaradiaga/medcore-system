<?php

namespace App\Core\Reports\Domain\Ports;

interface StrategicAnalyticsRepositoryInterface
{
    public function getDashboard(array $filters): array;
    public function getExecutiveReportData(array $filters): array;
}
