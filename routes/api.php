<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    AuthController,
    PatientController,
    DoctorController,
    SpecialtyController,
    ClinicController,
    AppointmentController,
    ReportController
};

// RUTAS PÚBLICAS
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register-patient', [PatientController::class, 'store']);

// RUTAS PROTEGIDAS (Middleware Sanctum)
Route::middleware('auth:sanctum')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // --- SEGURIDAD: SOLO ADMINISTRADORES ---
    Route::middleware('role:Admin')->group(function () {

        // Gestión de Doctores (Solo Admin crea/edita/borra)
        Route::prefix('doctores')->group(function () {
            Route::post('/', [DoctorController::class, 'store']);
            Route::put('/{id}', [DoctorController::class, 'update']);
            Route::delete('/{id}', [DoctorController::class, 'destroy']);
        });

        // Especialidades, Clínicas y Pacientes (Gestión total)
        Route::apiResource('clinicas', ClinicController::class);
        Route::apiResource('pacientes', PatientController::class);

        // Reportes de Dashboard
        Route::get('reports/dashboard', [ReportController::class, 'dashboardStats']);
    });

    // --- SEGURIDAD: SOLO DOCTORES ---
    Route::middleware('role:Doctor')->group(function () {
        // Rutas para el Dashboard
        Route::get('doctor/stats/{usuarioId}', [AppointmentController::class, 'getDoctorStats']);
        Route::get('doctor/citas/{usuarioId}', [AppointmentController::class, 'getAppointmentsByDoctorUser']);

        // Rutas de gestión de consulta
        Route::get('citas/doctor/{doctorId}', [AppointmentController::class, 'getByDoctor']);
        Route::post('citas/finalizar', [AppointmentController::class, 'complete']);
    });

    // --- SEGURIDAD: SOLO PACIENTES ---
    Route::middleware('role:Paciente')->group(function () {
        Route::post('citas', [AppointmentController::class, 'store']);
        Route::get('citas/historial/{usuarioId}', [AppointmentController::class, 'getHistoryByPatient']);
        Route::put('citas/{id}/reprogramar', [AppointmentController::class, 'reschedule']);
        Route::get('recetas/pdf/{recetaId}', [App\Http\Controllers\Api\AppointmentController::class, 'descargarReceta']);
    });

    // --- ACCESO COMPARTIDO ---

    Route::apiResource('especialidades', SpecialtyController::class);
    Route::get('doctores', [DoctorController::class, 'index']);
    Route::get('reports/appointments', [ReportController::class, 'appointmentsReport']);
    Route::delete('citas/{id}', [AppointmentController::class, 'destroy']);
});
