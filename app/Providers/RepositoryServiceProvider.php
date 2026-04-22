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

// Infraestructura (Implementaciones SQL)
use App\Core\Auth\Infrastructure\Repositories\SqlAuthRepository;
use App\Core\Patients\Infrastructure\Repositories\SqlPatientRepository;
use App\Core\Doctors\Infrastructure\Repositories\SqlDoctorRepository;
use App\Core\Specialties\Infrastructure\Repositories\SqlSpecialtyRepository;
use App\Core\Clinics\Infrastructure\Repositories\SqlClinicRepository;
use App\Core\Appointments\Infrastructure\Repositories\SqlAppointmentRepository;

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
    }

    public function boot(): void
    {
        //
    }
}