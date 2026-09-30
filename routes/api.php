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
    LaboratoryDashboardController,
    PharmacyController,
    EntityScheduleController,
    SystemSettingController,
    StrategicAnalyticsController,
    EntityController
};

// RUTAS PÚBLICAS (Accesibles sin Token / Sanctum)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register-patient', [PatientController::class, 'store']);
Route::post('/register-doctor', [AuthController::class, 'registerDoctor']);
Route::post('/register-institution', [EntityController::class, 'registerInstitution']);
Route::put('/auth/password', [AuthController::class, 'changePassword']);

// 🟢 Catálogo público de especialidades para formularios de alta
Route::get('/especialidades', [SpecialtyController::class, 'index']);


// RUTAS PROTEGIDAS (Sanctum Core)
Route::middleware('auth:sanctum')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('/system/settings', [SystemSettingController::class, 'index']);
    Route::post('/system/settings', [SystemSettingController::class, 'updateSetting']);

    // Actualizar Plan SaaS
    Route::post('saas/actualizar-plan', [SaaSController::class, 'actualizarPlanMembresia']);
    Route::get('saas/planes', [SaaSController::class, 'obtenerPlanes']);
    // Estado e Indicadores de Límites SaaS / Founder
    Route::get('saas/estado', [SaaSController::class, 'obtenerEstadoSaaS']);

    // --- ROL: SOLO ADMINISTRADORES ---
    Route::middleware('role:Admin')->group(function () {
        Route::put('admin/profile', [AuthController::class, 'updateAuthenticatedProfile']);
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
        Route::get('/entidades', [EntityController::class, 'getEntidadesPublicas']);
        Route::apiResource('pacientes', PatientController::class);

        // Reportes de Dashboard & BI
        Route::get('reports/dashboard', [ReportController::class, 'dashboardStats']);
        Route::get('admin/reports/analytics', [ReportController::class, 'obtenerReportesAnaliticos']);
        Route::get('admin/reports/appointments-financial', [ReportController::class, 'appointmentsFinancialReport']);
        Route::get('admin/reports/appointments-financial/pdf', [ReportController::class, 'exportAppointmentsFinancialPdf']);
        Route::get('admin/reports/appointments-financial/excel', [ReportController::class, 'exportAppointmentsFinancialExcel']);
        Route::get('admin/reports/strategic-analytics', [StrategicAnalyticsController::class, 'index']);
        Route::get('admin/reports/strategic-analytics/pdf', [StrategicAnalyticsController::class, 'exportPdf']);
        Route::get('admin/reports/strategic-analytics/excel', [StrategicAnalyticsController::class, 'exportExcel']);
        Route::get('admin/doctores-pendientes', [AdminController::class, 'obtenerDoctoresPendientes']);
        Route::put('admin/doctores/{id}/aprobar', [AdminController::class, 'aprobarDoctor']);
        Route::put('admin/usuarios/{id}/estado', [AdminController::class, 'cambiarEstado']);
        Route::get('admin/doctores/{id}/documentos', [AdminController::class, 'documentosDoctor']);
        Route::put('admin/doctores/{id}/founder', [AdminController::class, 'actualizarFounder']);
        Route::get('/admin/indicadores-calidad', [App\Http\Controllers\Api\ReportController::class, 'obtenerIndicadoresCalidad']);
        Route::get('/audit/quality', [ReportController::class, 'obtenerIndicadoresCalidad']);
        // RUTAS DE APROBACIÓN DE INSTITUCIONES (Clínicas, Farmacias, Laboratorios)
        Route::get('admin/entidades-pendientes', [EntityController::class, 'getPendingEntities']);
        Route::put('admin/entidades/{id}/aprobar', [EntityController::class, 'approveEntity']);

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

    // --- ROL: SOLO DOCTORES ---
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

        Route::post('/doctor/catalogo-precios', [PaymentController::class, 'guardarCatalogoYUbicacion']);
        Route::get('/doctor/perfil-ubicacion', [PaymentController::class, 'obtenerPerfilUbicacion']);
        Route::get('/doctor/consulta/facturacion-detalle', [HistoryController::class, 'obtenerDetalleParaFacturacion']);
        Route::post('/pagos/procesar', [PaymentController::class, 'registrarPago']);

        Route::get('/medico/dashboard-mensual', [HistoryController::class, 'obtenerMiniDashboardMensual']);

        Route::get('doctores/{id}/disponibilidad', [DoctorController::class, 'obtenerDisponibilidad']);
        Route::post('doctores/{id}/horarios', [DoctorController::class, 'guardarHorarios']);
        Route::post('doctores/{id}/bloqueos', [DoctorController::class, 'crearBloqueo']);
        Route::delete('doctor/bloqueos/{id}', [DoctorController::class, 'eliminarBloqueo']);
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

    // --- MÓDULO: FARMACIA ---
    Route::prefix('farmacia')->group(function () {
        Route::get('/metrics', [PharmacyController::class, 'metrics']);
        Route::post('/scan', [PharmacyController::class, 'scanBarcode']);
        Route::get('/recetas/buscar', [PharmacyController::class, 'buscarReceta']);
        Route::put('/recetas/{id}/estado', [PharmacyController::class, 'cambiarEstado']);
        Route::post('/recetas/{id}/surtir', [PharmacyController::class, 'surtir']);
        Route::post('/recetas/surtir-lote', [PharmacyController::class, 'surtirLote']);
    });

    // --- ACCESO COMPARTIDO MUTUO (Solo Operaciones de Escritura/Modificación) ---
    Route::apiResource('especialidades', SpecialtyController::class)->except(['index']);
    Route::get('doctores', [DoctorController::class, 'index']);
    Route::get('reports/appointments', [ReportController::class, 'appointmentsReport']);
    Route::delete('citas/{id}', [AppointmentController::class, 'destroy']);
    Route::put('especialidades/{id}/desactivar', [SpecialtyController::class, 'desactivar']);
    Route::put('clinicas/{id}/desactivar', [ClinicController::class, 'desactivar']);
    Route::get('/doctor/catalogo-precios', [PaymentController::class, 'obtenerCatalogoPrecios']);
    Route::get('/doctor/consulta/facturacion-detalle', [HistoryController::class, 'obtenerDetalleParaFacturacion']);
    Route::post('/pagos/procesar', [PaymentController::class, 'registrarPago']);

    // Catálogos Públicos Compartidos
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
    Route::get('/catalogo-alergias', function() {
        return response()->json(
            \Illuminate\Support\Facades\DB::table('CatalogoAlergias')->where('Estado', 1)->get()
        , 200);
    });

    // RUTAS DE LABORATORIO
    Route::prefix('laboratorio')->group(function () {
        Route::get('/dashboard-metrics', [LaboratoryDashboardController::class, 'getDashboardData']);
        Route::get('catalogo', [LaboratoryController::class, 'catalogo']);
        Route::get('paciente/{pacienteId}/ordenes', [LaboratoryController::class, 'ordenesPaciente']);
        Route::get('orden/{ordenId}/resultados', [LaboratoryController::class, 'resultadosOrden']);
        Route::get('/ordenes', [LaboratoryController::class, 'obtenerOrdenes']);
        Route::get('/ordenes/{ordenId}/examenes', [LaboratoryController::class, 'obtenerExamenesDetalle']);
        Route::post('/ordenes', [LaboratoryController::class, 'crearSolicitudDigital']);
        Route::put('/ordenes/{id}/aceptar', [LaboratoryController::class, 'aceptarOrden']);
        Route::post('/ordenes/escanear-qr', [LaboratoryController::class, 'validarQR']);
        Route::post('/ordenes/{id}/subir-resultados', [LaboratoryController::class, 'subirResultadosPDF']);
        Route::put('/tarifario/{examId}', [LaboratoryController::class, 'actualizarTarifa']);
        Route::put('/ordenes/{ordenId}/actualizar-examenes', [LaboratoryController::class, 'actualizarExamenesOrden']);
    });

    Route::get('/entidades', [EntityController::class, 'getEntidadesPublicas']);
    Route::get('/doctores/entidad/{id}', [DoctorController::class, 'getByClinic']);
    Route::get('doctores/{id}/slots-disponibles', [DoctorController::class, 'obtenerSlotsDisponibles']);
    Route::get('/entidades/{entityId}/horarios', [EntityScheduleController::class, 'index']);
    Route::put('/entidades/{entityId}/horarios', [EntityScheduleController::class, 'update']);
    Route::get('/instituciones', [EntityController::class, 'index']);
});
