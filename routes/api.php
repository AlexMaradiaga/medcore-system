<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    AuthController,
    PatientController,
    DoctorController,
    SpecialtyController,
    ClinicController,
    AppointmentController,
    ReportController,
    HistoryController,
    AdminController,
    PaymentController,
    SaaSController,
    LaboratoryController,
    ClinicDashboardController,
    LaboratoryDashboardController
};

// RUTAS PÚBLICAS
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register-patient', [PatientController::class, 'store']);
Route::post('/register-doctor', [AuthController::class, 'registerDoctor']);

// RUTAS PROTEGIDAS (Sanctum Core)
Route::middleware('auth:sanctum')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // --- ROL: SOLO ADMINISTRADORES ---
    Route::middleware('role:Admin')->group(function () {
        Route::get('admin/usuarios', [AdminController::class, 'obtenerUsuarios']);
        Route::get('admin/doctores/entidad', [AdminController::class, 'obtenerDoctoresPorEntidad']);
        Route::get('admin/usuarios/agrupados', [AdminController::class, 'obtenerUsuariosPorRol']);
        Route::post('admin/doctores', [AdminController::class, 'registrarDoctor']);

        Route::prefix('doctores')->group(function () {
            Route::post('/', [DoctorController::class, 'store']);
            Route::put('/{id}', [DoctorController::class, 'update']);
            Route::delete('/{id}', [DoctorController::class, 'destroy']);
        });

        Route::apiResource('clinicas', ClinicController::class);
        Route::apiResource('pacientes', PatientController::class);

        // Reportes de Dashboard & BI
        Route::get('reports/dashboard', [ReportController::class, 'dashboardStats']);
        Route::get('admin/reports/analytics', [ReportController::class, 'obtenerReportesAnaliticos']);
        Route::get('admin/doctores-pendientes', [AdminController::class, 'obtenerDoctoresPendientes']);
        Route::put('admin/doctores/{id}/aprobar', [AdminController::class, 'aprobarDoctor']);
        Route::put('admin/usuarios/{id}/estado', [AdminController::class, 'cambiarEstado']);
        Route::get('/admin/indicadores-calidad', [App\Http\Controllers\Api\ReportController::class, 'obtenerIndicadoresCalidad']);
        Route::get('/audit/quality', [ReportController::class, 'obtenerIndicadoresCalidad']);

        // Ruta para el Dashboard de las Clínicas
        Route::get('/clinica/dashboard', [App\Http\Controllers\Api\ClinicDashboardController::class, 'getDashboardData']);
        // Ruta para el Dashboard de los laboratorios
        Route::get('/laboratorio/dashboard-metrics', [LaboratoryDashboardController::class, 'getDashboardData']);

        // Configuración Global de Planes SaaS
        Route::post('admin/saas/actualizar-plan', [SaaSController::class, 'actualizarPlanMembresia']);
        Route::post('admin/servicios/tarifa', [SaaSController::class, 'actualizarPrecioServicio']);
        Route::get('admin/saas/monitoreo', [SaaSController::class, 'obtenerMonitoreoSaaS']);
        Route::get('admin/reportes/exportar', [SaaSController::class, 'exportarReporte']);
    });

    // --- ROL: SOLO DOCTORES (Protegidos por Cuotas SaaS) ---
    Route::middleware('role:Doctor')->group(function () {
        Route::get('doctor/stats/{usuarioId}', [AppointmentController::class, 'getDoctorStats']);
        Route::get('doctor/citas/{usuarioId}', [AppointmentController::class, 'getAppointmentsByDoctorUser']);
        Route::post('doctor/cita/aprobar/{id}', [AppointmentController::class, 'approve']);
        Route::post('doctor/cita/rechazar/{id}', [AppointmentController::class, 'reject']);

        Route::get('citas/doctor/{doctorId}', [AppointmentController::class, 'getByDoctor']);
        Route::post('doctor/consulta/finalizar', [HistoryController::class, 'complete']);

        Route::get('/doctor/paciente/{pacienteId}/historial-completo', [HistoryController::class, 'obtenerHistorialCompleto']);
        Route::get('/doctor/mis-pacientes', [HistoryController::class, 'obtenerMisPacientesAtendidos']);
        Route::post('/doctor/paciente/conceder-autorizacion', [HistoryController::class, 'concederAutorizacionGlobal']);

        Route::get('/doctor/consulta/detalle', [HistoryController::class, 'obtenerDetalleConsulta']);
        Route::get('/medico/consulta/receta', [HistoryController::class, 'obtenerRecetaPorConsulta']);
        Route::get('/doctor/diagnosticos/buscar', [HistoryController::class, 'buscarDiagnosticosCIE11']);
        Route::get('/doctor/catalogo-examen-fisico', [AppointmentController::class, 'getCatalogoExamenFisico']);

        Route::get('/doctor/catalogo-precios', [PaymentController::class, 'obtenerCatalogoPrecios']);
        Route::get('/doctor/consulta/facturacion-detalle', [HistoryController::class, 'obtenerDetalleParaFacturacion']);
        Route::post('/pagos/procesar', [PaymentController::class, 'registrarPago']);
        //dashOdontologa
        Route::middleware('auth:sanctum')->get('/medico/dashboard-mensual', [HistoryController::class, 'obtenerMiniDashboardMensual']);
    });

    // --- ROL: SOLO PACIENTES ---
    Route::middleware('role:Paciente')->group(function () {
        Route::post('citas', [AppointmentController::class, 'store']);
        Route::get('citas/historial/{usuarioId}', [AppointmentController::class, 'getHistoryByPatient']);
        Route::put('citas/{id}/reprogramar', [AppointmentController::class, 'reschedule']);
        Route::get('recetas/pdf/{recetaId}', [AppointmentController::class, 'descargarReceta']);
        Route::get('historial/consultas/{usuarioId}', [AppointmentController::class, 'getHistoryByPatient']);
        Route::get('historial/recetas/{usuarioId}', [AppointmentController::class, 'getPrescriptionsByPatient']);
        Route::get('historial/examenes/{usuarioId}', [AppointmentController::class, 'getExamsByPatient']);
        Route::get('/pacientes/usuario/{id}', [PatientController::class, 'obtenerPorUsuario']);
        Route::post('/pacientes/{id}/emancipar', [PatientController::class, 'emanciparPaciente']);
        Route::post('/pacientes/auto-registro', [PatientController::class, 'autoRegistroTutor']);
    });

    // --- ACCESO COMPARTIDO MUTUO ---
    Route::apiResource('especialidades', SpecialtyController::class);
    Route::get('doctores', [DoctorController::class, 'index']);
    Route::get('reports/appointments', [ReportController::class, 'appointmentsReport']);
    Route::delete('citas/{id}', [AppointmentController::class, 'destroy']);
    Route::put('especialidades/{id}/desactivar', [SpecialtyController::class, 'desactivar']);
    Route::put('clinicas/{id}/desactivar', [ClinicController::class, 'desactivar']);

    // Rutas de Facturación y Pagos:
    Route::get('/doctor/consulta/facturacion-detalle', [HistoryController::class, 'obtenerDetalleParaFacturacion']);
    Route::post('/pagos/procesar', [PaymentController::class, 'registrarPago']);

    //Catálogo de Enfermedades Crónicas
    Route::get('/enfermedades-cronicas', function() {
        return response()->json(
            \Illuminate\Support\Facades\DB::table('EnfermedadesCronicas')->where('Estado', 1)->get()
        , 200);
    });
    Route::get('/catalogo-medicamentos', function() {
        return response()->json(
            \Illuminate\Support\Facades\DB::table('CatalogoMedicamentos')->where('Estado', 1)->get()
        , 200);
    });
    //Catálogo de Alergias para Selección Múltiple
    Route::get('/catalogo-alergias', function() {
        return response()->json(
            \Illuminate\Support\Facades\DB::table('CatalogoAlergias')->where('Estado', 1)->get()
        , 200);
    });

    // RUTAS DE LABORATORIO (Acceso Compartido Paciente/Doctor)
    Route::prefix('laboratorio')->group(function () {
        Route::get('catalogo', [LaboratoryController::class, 'catalogo']);
        Route::get('paciente/{pacienteId}/ordenes', [LaboratoryController::class, 'ordenesPaciente']);
        Route::get('orden/{ordenId}/resultados', [LaboratoryController::class, 'resultadosOrden']);
    });

    // 1. Ruta para obtener todas las instituciones/clínicas
    Route::get('/entidades', [ClinicController::class, 'getEntidadesPublicas']);

    // 2. Ruta para obtener los doctores que pertenecen a una clínica específica
    Route::get('/doctores/entidad/{id}', [DoctorController::class, 'getByClinic']);
});
