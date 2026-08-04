<?php

namespace App\Events\SaaS;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlanActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $usuarioId,
        public ?int $entidadId,
        public string $tipoPlan,
        public int $diasVigencia,
        public array $metadata = []
    ) {}
}
