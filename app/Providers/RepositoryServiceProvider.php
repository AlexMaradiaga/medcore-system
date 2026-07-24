<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Puertos (Interfaces)
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;
use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;
use App\Core\Specialties\Domain\Ports\SpecialtyRepositoryInterface;
use App\Core\Clinics\Domain\Ports\ClinicRepositoryInterface;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use App\Core\SaaS\Domain\Ports\SaaSRepositoryInterface;
// Infraestructura (Implementaciones SQL)
use App\Core\Auth\Infrastructure\Repositories\SqlAuthRepository;
use App\Core\Patients\Infrastructure\Repositories\SqlPatientRepository;
use App\Core\Doctors\Infrastructure\Repositories\SqlDoctorRepository;
use App\Core\Specialties\Infrastructure\Repositories\SqlSpecialtyRepository;
use App\Core\Clinics\Infrastructure\Repositories\SqlClinicRepository;
use App\Core\Appointments\Infrastructure\Repositories\SqlAppointmentRepository;
use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;
use App\Core\Laboratories\Infrastructure\Repositories\SqlLaboratoryRepository;

use App\Core\Payments\Domain\Ports\PaymentRepositoryInterface;
use App\Core\Payments\Infrastructure\Repositories\SqlPaymentRepository;
use App\Core\SaaS\Infrastructure\Repositories\SqlSaaSRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthRepositoryInterface::class, SqlAuthRepository::class);
        $this->app->bind(PatientRepositoryInterface::class, SqlPatientRepository::class);
        $this->app->bind(DoctorRepositoryInterface::class, SqlDoctorRepository::class);
        $this->app->bind(SpecialtyRepositoryInterface::class, SqlSpecialtyRepository::class);
        $this->app->bind(ClinicRepositoryInterface::class, SqlClinicRepository::class);
        $this->app->bind(AppointmentRepositoryInterface::class, SqlAppointmentRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, SqlPaymentRepository::class);
        $this->app->bind(LaboratoryRepositoryInterface::class, SqlLaboratoryRepository::class);
        $this->app->bind(SaaSRepositoryInterface::class, SqlSaaSRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
