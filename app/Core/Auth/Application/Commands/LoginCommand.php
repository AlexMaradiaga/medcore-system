<?php

namespace App\Core\Auth\Application\Commands;

class LoginCommand
{
    public function __construct(
        public readonly string $email,
        public readonly string $password
    ) {}
}