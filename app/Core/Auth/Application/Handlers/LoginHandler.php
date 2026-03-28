<?php

namespace App\Core\Auth\Application\Handlers;

use App\Core\Auth\Application\Commands\LoginCommand;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use App\Core\Auth\Domain\Entities\Usuario;
use Illuminate\Support\Facades\Hash;
use Exception;

class LoginHandler
{
    public function __construct(
        private readonly AuthRepositoryInterface $repository
    ) {}

    public function handle(LoginCommand $command)
    {
        $usuario = $this->repository->findByEmail($command->email);

        if (!$usuario || !Hash::check($command->password, $usuario->passwordHash)) {
            throw new Exception("Credenciales inválidas.");
        }

        // Aquí podrías generar un token JWT o Sanctum si lo deseas
        return $usuario;
    }
}