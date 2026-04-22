<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
// IMPORTANTE: Asegúrate de tener estas dos importaciones
use App\Core\Patients\Application\Handlers\RegisterPatientHandler;
use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;

class PatientController extends Controller
{
    private $handler;
    private $repository;

    public function __construct(
        RegisterPatientHandler $handler,
        PatientRepositoryInterface $repository
    ) {
        $this->handler = $handler;
        $this->repository = $repository;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $datos = $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string|min:6',
                'dni'      => 'required|string',
                'nombre'   => 'required|string',
                'apellido' => 'required|string',
                'telefono' => 'nullable|string',
                'entidad_id' => 'required|integer'
            ]);

            $this->handler->execute($datos);

            return response()->json([
                'status' => 'success',
                'message' => 'Paciente registrado correctamente en MedCore'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    public function index(): JsonResponse 
    {
        $data = $this->repository->obtenerTodos(); 
    
        return response()->json([
            'status' => 'success',
            'count'  => count($data),
            'data'   => $data
        ]);
    }

    public function update(Request $request, $id): JsonResponse 
    {
        try {
            $validated = $request->validate([
                'email'      => 'required|email',
                'nombre'     => 'required|string',
                'apellido'   => 'required|string',
                'telefono'   => 'required|string',
                'entidad_id' => 'required|integer'
            ]);

            $this->repository->update((int)$id, $validated);

            return response()->json([
                'status' => 'success', 
                'message' => 'Datos de paciente actualizados'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function destroy($id): JsonResponse 
    {
        try {
            $this->repository->delete((int)$id);
            return response()->json([
                'status' => 'success', 
                'message' => 'Paciente desactivado'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => $e->getMessage()
            ], 400);
        }
    }
}