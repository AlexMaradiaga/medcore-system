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
                'UsuarioID' => 'required|integer',
                'doctor_id'   => 'required|integer',
                'entidad_id'  => 'required|integer',
                'fecha_hora'  => 'required|date_format:Y-m-d H:i:s',
                'motivo'      => 'required|string|max:255',
                'sintomas'    => 'nullable|string',
                'alergias'    => 'nullable|string',
                'edad'        => 'required|integer',
                'genero'      => 'required|string|max:1',
                'medicamentos_actuales' => 'nullable|string',
                'aseguradora' => 'nullable|string|max:100',
                'numero_poliza' => 'nullable|string|max:50',
                'nombre_contacto_emergencia' => 'nullable|string|max:100',
                'telefono_contacto_emergencia' => 'nullable|string|max:20',
            ]);

            $this->repository->create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Cita agendada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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
        $this->repository->cancel((int)$id, "Cancelada por el usuario");

        return response()->json(['status' => 'success', 'message' => 'Cita cancelada correctamente']);
    }

    public function complete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cita_id' => 'required|integer',
                'diagnostico' => 'required|string',
                'notas_medicas' => 'nullable|string',
                'detalle_medicamentos' => 'required|string',
            ]);

            $this->repository->complete($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Consulta finalizada y receta generada con éxito'
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
