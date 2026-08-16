<?php

namespace App\Core\Laboratories\Domain\Ports;

use Illuminate\Http\UploadedFile;

interface LaboratoryRepositoryInterface
{
    public function getCatalogoExamenes(): array;
    public function getOrdenesPorPaciente(int $pacienteId): array;
    public function getResultadosPorOrden(int $ordenId): array;
    public function obtenerOrdenesOperativas(int $laboratorioId, ?string $estado = null): array;
    public function getExamenesPorOrden(int $ordenId): array;
    public function aceptarOrden(int $ordenId): bool;
    public function validarQR(string $codigoOrden, int $laboratorioId): array;
    public function subirResultadosPDF(int $ordenId, UploadedFile $archivoPdf): array;
    public function actualizarPrecioExamen(int $examId, float $precio): bool;

    /**
     * Registra una nueva solicitud digital resolviendo internamente los IDs de paciente/doctor
     */
    public function crearSolicitudDigital(array $datos, ?object $authUser = null): array;

    /**
     * Ejecuta el Stored Procedure del Dashboard y formatea los datos para el Frontend
     */
    public function getDashboardMetrics(int $laboratorioId): array;
    public function actualizarExamenesOrden(int $ordenId, array $examenesSeleccionadosIds): array;
}
