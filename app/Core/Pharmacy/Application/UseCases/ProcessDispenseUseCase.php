<?php
namespace App\Core\Pharmacy\Application\UseCases;

use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;
use Exception;

class ProcessDispenseUseCase
{
    public function __construct(
        private PharmacyRepositoryInterface $repository
    ) {}

    public function execute(string $orderId): void
    {
        // Se busca por el código de orden/código de barras
        $order = $this->repository->findByBarcode($orderId);

        if (!$order) {
            throw new Exception('Orden de receta no encontrada.');
        }

        $order->dispense();

        // Cambiado save() por saveOrder()
        $this->repository->saveOrder($order);
    }
}
