<?php
namespace App\Core\Patients\Application\Handlers;

use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;

class RegisterPatientHandler {
    public function __construct(
        private PatientRepositoryInterface $repository
    ) {}

    public function execute(array $datos): bool {
        return $this->repository->registrar($datos);
    }
}