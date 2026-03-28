<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\DB;
use PDO;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        try {
            $dsn = "sqlsrv:Server=DESKTOP-T26OK4S,1433;Database=DB_MedCore_Global;TrustServerCertificate=true";
            $pdo = new PDO($dsn, "sa", "Oracle92");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            DB::connection('sqlsrv')->setPdo($pdo);
        } catch (\Exception $e) {
        }
    }
}
