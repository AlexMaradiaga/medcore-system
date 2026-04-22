<?php

namespace App\Core\Doctors\Application\Handlers;

use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;

class RegisterDoctorHandler
{
    public function __construct(
        private DoctorRepositoryInterface $repository
    ) {}

    public function execute(array $datos): bool
    {
        return $this->repository->registrar($datos);
    }
}