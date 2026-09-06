<?php
namespace App\Core\Entity\Domain;

class EntitySchedule
{
    public function __construct(
        public ?int $id,
        public int $entityId,
        public int $dayOfWeek,
        public string $openTime,
        public string $closeTime,
        public bool $isInactive,
        public ?string $notes = null
    ) {}
}
