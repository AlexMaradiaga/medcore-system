<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;
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
        } catch (Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function ordenesPaciente($pacienteId): JsonResponse
    {
        try {
            $data = $this->repository->getOrdenesPorPaciente((int)$pacienteId);
            return response()->json([
                'status' => 'success',
                'estado' => 'success',
                'datos'  => $data
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function resultadosOrden($ordenId): JsonResponse
    {
        try {
            $data = $this->repository->getResultadosPorOrden((int)$ordenId);
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function obtenerOrdenes(Request $request): JsonResponse
    {
        try {
            $laboratorioId = $request->query('laboratorio_id');
            $estado = $request->query('estado');

            if (!$laboratorioId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se proporcionó el parámetro laboratorio_id'
                ], 400);
            }

            $ordenes = $this->repository->obtenerOrdenesOperativas((int)$laboratorioId, $estado);

            return response()->json([
                'status' => 'success',
                'datos'  => $ordenes
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function obtenerExamenesDetalle($ordenId): JsonResponse
    {
        try {
            $data = $this->repository->getExamenesPorOrden((int)$ordenId);
            return response()->json([
                'status' => 'success',
                'datos'  => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function aceptarOrden($ordenId): JsonResponse
    {
        try {
            $exito = $this->repository->aceptarOrden((int)$ordenId);

            if (!$exito) {
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

    public function validarQR(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_orden'   => 'required|string',
            'laboratorio_id' => 'required|integer'
        ]);

        try {
            $resultado = $this->repository->validarQR($validated['codigo_orden'], (int)$validated['laboratorio_id']);

            if ($resultado['status'] !== 'success') {
                return response()->json([
                    'status'  => $resultado['status'],
                    'message' => $resultado['message']
                ], $resultado['code']);
            }

            return response()->json([
                'status'  => 'success',
                'message' => $resultado['message'],
                'orden'   => $resultado['orden']
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function subirResultadosPDF(Request $request, $ordenId): JsonResponse
    {
        $request->validate([
            'archivo_pdf' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        try {
            $resultado = $this->repository->subirResultadosPDF((int)$ordenId, $request->file('archivo_pdf'));

            if ($resultado['status'] !== 'success') {
                return response()->json([
                    'status'  => $resultado['status'],
                    'message' => $resultado['message']
                ], $resultado['code']);
            }

            return response()->json([
                'status'            => 'success',
                'message'           => $resultado['message'],
                'comision_generada' => $resultado['comision_generada'],
                'pdf_url'           => $resultado['pdf_url']
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function actualizarTarifa(Request $request, $examId): JsonResponse
    {
        $validated = $request->validate([
            'precio' => 'required|numeric|min:0'
        ]);

        try {
            $exito = $this->repository->actualizarPrecioExamen((int)$examId, (float)$validated['precio']);

            if (!$exito) {
                return response()->json([
                    'estado'  => 'error',
                    'mensaje' => 'No se encontró el examen especificado'
                ], 404);
            }

            return response()->json([
                'estado'  => 'success',
                'mensaje' => 'Precio actualizado correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function crearSolicitudDigital(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'laboratorio_id'    => 'required|integer',
            'paciente_id'       => 'nullable|integer',
            'doctor_id'         => 'nullable|integer',
            'consulta_id'       => 'nullable|integer',
            'notas_clinicas'    => 'nullable|string',
            'nombre_paciente'   => 'nullable|string',
            'codigo_expediente' => 'nullable|string',
            'examenes'          => 'required|array|min:1',
            'examenes.*'        => 'integer',
            'monto_total'       => 'required|numeric|min:0'
        ]);

        try {
            // Toda la resolución de PacienteID y DoctorID ocurre limpiamente dentro del Repositorio
            $resultado = $this->repository->crearSolicitudDigital($validated, $request->user());

            return response()->json([
                'status'       => 'success',
                'message'      => 'Solicitud digital creada exitosamente.',
                'codigo_orden' => $resultado['codigo_orden'],
                'orden_id'     => $resultado['orden_id']
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fallo al registrar la orden: ' . $e->getMessage()
            ], 500);
        }
    }

    public function actualizarExamenesOrden(Request $request, $ordenId): JsonResponse
    {
        $validated = $request->validate([
            'examenes_ids'   => 'array',
            'examenes_ids.*' => 'integer'
        ]);

        try {
            $resultado = $this->repository->actualizarExamenesOrden(
                (int)$ordenId,
                $validated['examenes_ids'] ?? []
            );

            return response()->json($resultado, 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
