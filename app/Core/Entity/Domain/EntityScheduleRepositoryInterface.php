<?php
namespace App\Core\Entity\Domain;

interface EntityScheduleRepositoryInterface
{
    public function getSchedulesByEntity(int $entityId): array;
    public function saveSchedules(int $entityId, array $schedules): void;
}
