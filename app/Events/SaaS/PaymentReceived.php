<?php

namespace App\Events\SaaS;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $usuarioId,
        public ?int $entidadId,
        public float $monto,
        public string $transaccionRef,
        public string $pasarela,
        public array $metadata = []
    ) {}
}
