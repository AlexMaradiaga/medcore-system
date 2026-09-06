<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Core\Doctors\Application\Handlers\RegisterDoctorHandler;
use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;

class DoctorController extends Controller
{
    private $handler;
    private $repository;

    public function __construct(
        RegisterDoctorHandler $handler,
        DoctorRepositoryInterface $repository
    ) {
        $this->handler = $handler;
        $this->repository = $repository;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'             => 'required|email',
                'password'          => 'required|string|min:6',
                'dni'               => 'required|string',
                'nombre'            => 'required|string',
                'apellido'          => 'required|string',
                'telefono'          => 'nullable|string',
                'especialidad_id'   => 'required|integer',
                'entidad_id'        => 'required|integer',
                'numero_colegiado'  => 'required|string',
                'ruta_documento'    => 'nullable|string'
            ]);

            $this->handler->execute($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Doctor registrado con éxito y pendiente de verificación'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search'       => $request->query('search'),
            'especialidad' => $request->query('especialidad'),
        ];

        return response()->json($this->repository->getAllActive($filters));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'            => 'required|email',
                'nombre'           => 'required|string',
                'apellido'         => 'required|string',
                'especialidad_id'  => 'required|integer',
                'entidad_id'       => 'required|integer',
                'numero_colegiado' => 'required|string'
            ]);

            $this->repository->update((int)$id, $validated);
            return response()->json(['status' => 'success', 'message' => 'Perfil de doctor actualizado']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete((int)$id);
            return response()->json(['status' => 'success', 'message' => 'Doctor desactivado (Baja lógica)']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function getByClinic($entidadId): JsonResponse
    {
        try {
            $doctores = $this->repository->obtenerPorClinica((int)$entidadId);
            return response()->json($doctores, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener doctores de la clínica: ' . $e->getMessage()
            ], 500);
        }
    }

    public function guardarUbicacionConsultorio(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'doctor_id'             => 'required|integer',
                'latitud'               => 'required|numeric',
                'longitud'              => 'required|numeric',
                'direccion_consultorio' => 'required|string|max:255',
                'habla_ingles'          => 'nullable|boolean',
                'disponible_domicilio'  => 'nullable|boolean',
            ]);

            $this->repository->guardarUbicacionConsultorio($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Ubicación y configuración del consultorio actualizadas correctamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function guardarHorarios(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'horarios'                  => 'required|array',
                'horarios.*.dia_semana'     => 'required|integer|min:1|max:7',
                'horarios.*.hora_inicio'    => 'required|string',
                'horarios.*.hora_fin'       => 'required|string',
                'horarios.*.duracion_minutos' => 'nullable|integer'
            ]);

            $this->repository->guardarHorarios((int)$id, $validated['horarios']);

            return response()->json([
                'status' => 'success',
                'message' => 'Horarios actualizados correctamente'
            ], 200);

        } catch (\Exception $e) {
            \Log::error("Error guardando horarios: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function crearBloqueo(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
                'motivo'       => 'nullable|string|max:255'
            ]);

            $this->repository->registrarBloqueo((int)$id, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Bloqueo o permiso temporal registrado con éxito'
            ], 201);
        } catch (\Exception $e) {
            \Log::error("Error creando bloqueo DoctorID {$id}: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function eliminarBloqueo($id): JsonResponse
    {
        try {
            $this->repository->eliminarBloqueo((int)$id);

            return response()->json([
                'status'  => 'success',
                'message' => 'Bloqueo eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function obtenerDisponibilidad($doctorId): JsonResponse
    {
        return response()->json($this->repository->obtenerDisponibilidad((int)$doctorId));
    }
}
