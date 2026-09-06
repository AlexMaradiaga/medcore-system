<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;

class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentRepositoryInterface $repository
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'doctor_id'                    => 'required|integer',
                'entidad_id'                   => 'required|integer',
                'fecha_hora'                   => 'required|date_format:Y-m-d H:i:s',
                'motivo'                       => 'required|string|max:255',
                'sintomas'                     => 'nullable|string',
                'edad'                         => 'required|integer',
                'genero'                       => 'required|string|max:1',
                'telefono'                     => 'nullable|string',
                'alergias'                     => 'nullable|string',
                'aseguradora'                  => 'nullable|string',
                'NumeroPoliza'                => 'nullable|string',
                'nombre_contacto_emergencia'   => 'nullable|string',
                'telefono_contacto_emergencia' => 'nullable|string',
                'medicamentos_actuales'        => 'nullable|string',
                'cronicas_ids'                 => 'nullable|array',
                'cronicas_ids.*'               => 'integer',
                'UsuarioID'                    => 'nullable|integer',
                'usuario_id'                   => 'nullable|integer',
                'paciente_id'                  => 'nullable|integer'
            ]);

            $validated['UsuarioID'] = $validated['UsuarioID']
                ?? $validated['usuario_id']
                ?? $request->user()?->UsuarioID
                ?? $request->user()?->id;

            $citaId = $this->repository->create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cita agendada correctamente en MedGo+',
                'cita_id' => $citaId
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error de validación en la solicitud.',
                'errors'  => $e->errors()
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'line'    => $e->getLine()
            ], 400);
        }
    }

    public function getByDoctor($doctorId): JsonResponse
    {
        return response()->json($this->repository->getPendingByDoctor((int)$doctorId));
    }

    public function reschedule(Request $request, $id): JsonResponse
    {
        $request->validate(['fecha_hora' => 'required|date_format:Y-m-d H:i:s']);

        $this->repository->reschedule((int)$id, $request->fecha_hora);

        return response()->json(['status' => 'success', 'message' => 'Cita reprogramada']);
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->cancel((int)$id, "Cancelada desde el portal");
            return response()->json([
                'status'  => 'success',
                'message' => 'Cita cancelada correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No se pudo cancelar la cita: ' . $e->getMessage()
            ], 400);
        }
    }

    public function getHistoryByPatient($id): JsonResponse
    {
        try {
            $citas = $this->repository->getHistoryByPatient((int)$id);
            return response()->json($citas, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }

    public function getPrescriptionsByPatient($usuarioId): JsonResponse
    {
        try {
            $recetas = $this->repository->getPrescriptions((int)$usuarioId);
            return response()->json($recetas, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getExamsByPatient($usuarioId): JsonResponse
    {
        try {
            $examenSistemas = $this->repository->getExams((int)$usuarioId);
            return response()->json($examenSistemas, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al obtener examen físico: ' . $e->getMessage()
            ], 500);
        }
    }

    public function descargarReceta($recetaId)
    {
        try {
            $pdf = $this->repository->descargarReceta($recetaId);

            return $pdf->download("Receta_{$recetaId}.pdf", [
                'Content-Type'                  => 'application/pdf',
                'Access-Control-Expose-Headers' => 'Content-Disposition'
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDoctorStats($usuarioId): JsonResponse
    {
        try {
            $stats = $this->repository->getDoctorStats((int)$usuarioId);
            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getAppointmentsByDoctorUser($usuarioId): JsonResponse
    {
        try {
            $appointments = $this->repository->getAppointmentsByDoctorUser((int)$usuarioId);
            return response()->json($appointments);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function approve($id): JsonResponse
    {
        try {
            $this->repository->approve((int)$id);
            return response()->json(['status' => 'success', 'message' => 'Cita aprobada']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $request, $id): JsonResponse
    {
        try {
            $motivo = $request->input('motivo', 'Rechazada por el médico');
            $this->repository->cancel((int)$id, $motivo);

            return response()->json(['status' => 'success', 'message' => 'Cita rechazada']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function getCatalogoExamenFisico(): JsonResponse
    {
        try {
            $catalogo = $this->repository->getCatalogoExamenFisico();
            return response()->json($catalogo);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
