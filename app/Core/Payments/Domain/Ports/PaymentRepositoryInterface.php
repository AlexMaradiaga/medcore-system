<?php

namespace App\Core\Payments\Domain\Ports;

interface PaymentRepositoryInterface
{
    /**
     *
     * @param array $data
     * @return bool
     */
    public function procesarPago(array $data): bool;
}
