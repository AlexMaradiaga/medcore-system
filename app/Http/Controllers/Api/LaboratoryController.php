<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;

class LaboratoryController extends Controller
{
    public function __construct(
        private LaboratoryRepositoryInterface $repository
    ) {}

    public function catalogo(): JsonResponse
    {
        try {
            $data = $this->repository->getCatalogoExamenes();
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function ordenesPaciente($pacienteId): JsonResponse
    {
        try {
            $data = $this->repository->getOrdenesPorPaciente((int)$pacienteId);
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function resultadosOrden($ordenId): JsonResponse
    {
        try {
            $data = $this->repository->getResultadosPorOrden((int)$ordenId);
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function obtenerOrdenes(Request $request): JsonResponse
    {
        try {
            $laboratorioId = $request->query('laboratorio_id');
            $estado = $request->query('estado');

            $query = DB::table('OrdenesLaboratorio as OL')
                ->join('Pacientes as P', 'OL.PacienteID', '=', 'P.PacienteID')
                ->leftJoin('Doctores as D', 'OL.DoctorID', '=', 'D.DoctorID')
                ->select(
                    'OL.OrdenID',
                    'OL.CodigoOrden',
                    'OL.Estado',
                    'OL.MontoTotal',
                    'OL.ComisionMonto',
                    'OL.ArchivoPdfPath',
                    'OL.FechaOrden',
                    'OL.FechaCompletado',
                    DB::raw("P.Nombre + ' ' + P.Apellido as Paciente"),
                    'P.DNI as PacienteDNI',
                    'P.Telefono as PacienteTelefono',
                    DB::raw("COALESCE(D.Nombre + ' ' + D.Apellido, 'Solicitud Directa') as Doctor")
                )
                ->where('OL.LaboratorioID', $laboratorioId);

            if ($estado && $estado !== 'Todos') {
                $query->where('OL.Estado', $estado);
            }

            $ordenes = $query->orderBy('OL.FechaOrden', 'DESC')->get();

            return response()->json([
                'status' => 'success',
                'datos'  => $ordenes
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Transición 1: Cambiar estado a 'Aceptada'
     */
    public function aceptarOrden($ordenId): JsonResponse
    {
        try {
            $afectados = DB::table('OrdenesLaboratorio')
                ->where('OrdenID', $ordenId)
                ->where('Estado', 'Emitida')
                ->update(['Estado' => 'Aceptada']);

            if (!$afectados) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La orden no está en estado "Emitida" o no existe.'
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Orden aceptada correctamente por el laboratorio.'
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Transición 2: Escaneo / Validación QR (Cambia a 'Paciente Recibido')
     */
    public function validarQR(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_orden' => 'required|string',
            'laboratorio_id' => 'required|integer'
        ]);

        try {
            $orden = DB::table('OrdenesLaboratorio')
                ->where('CodigoOrden', $validated['codigo_orden'])
                ->where('LaboratorioID', $validated['laboratorio_id'])
                ->first();

            if (!$orden) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Código QR / Orden no encontrada para este laboratorio.'
                ], 404);
            }

            if ($orden->Estado === 'Completada') {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'Esta orden ya fue procesada y completada anteriormente.'
                ], 400);
            }

            // Actualizar estado a 'Paciente Recibido'
            DB::table('OrdenesLaboratorio')
                ->where('OrdenID', $orden->OrdenID)
                ->update(['Estado' => 'Paciente Recibido']);

            return response()->json([
                'status' => 'success',
                'message' => 'Recepción de paciente validada correctamente.',
                'orden' => $orden
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Transición 3: Carga de resultados PDF (Cambia a 'Completada' y gatilla comisión 5%)
     */
    public function subirResultadosPDF(Request $request, $ordenId): JsonResponse
    {
        $validated = $request->validate([
            'archivo_pdf' => 'required|file|mimes:pdf|max:10240', // Máx 10 MB
        ]);

        try {
            $orden = DB::table('OrdenesLaboratorio')->where('OrdenID', $ordenId)->first();
            if (!$orden) {
                return response()->json(['status' => 'error', 'message' => 'Orden no encontrada.'], 404);
            }

            // 1. Guardar archivo PDF en disco seguro
            $pathPdf = $request->file('archivo_pdf')->store('laboratorios/resultados', 'public');

            // 2. Calcular la comisión del 5%
            $montoTotal = floatval($orden->MontoTotal ?? 0);
            $comisionCalculada = round($montoTotal * 0.05, 2);

            DB::beginTransaction();

            // 3. Actualizar la orden
            DB::table('OrdenesLaboratorio')
                ->where('OrdenID', $ordenId)
                ->update([
                    'Estado'          => 'Completada',
                    'ArchivoPdfPath'  => $pathPdf,
                    'ComisionMonto'   => $comisionCalculada,
                    'FechaCompletado' => now()
                ]);

            // 4. Registrar en libro contable SaaS la comisión
            if ($comisionCalculada > 0) {
                DB::table('Facturacion_SaaS')->insert([
                    'DoctorID'    => $orden->DoctorID,
                    'Concepto'    => "Comisión 5% Laboratorio - Orden {$orden->CodigoOrden}",
                    'Monto'       => $comisionCalculada,
                    'FechaCargo'  => now(),
                    'Estado'      => 'Pendiente'
                ]);
            }

            DB::commit();

            return response()->json([
                'status'           => 'success',
                'message'          => 'Resultados adjuntados exitosamente. Orden finalizada.',
                'comision_generada' => $comisionCalculada,
                'pdf_url'          => Storage::url($pathPdf)
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
