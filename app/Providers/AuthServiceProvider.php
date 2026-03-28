<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use App\Core\Auth\Infrastructure\Repositories\SqlAuthRepository;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AuthRepositoryInterface::class,
            SqlAuthRepository::class
        );
    }

    public function boot(): void {}
}