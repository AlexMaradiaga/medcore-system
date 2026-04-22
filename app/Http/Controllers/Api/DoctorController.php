<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Core\Doctors\Application\Handlers\RegisterDoctorHandler;
use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;

class DoctorController extends Controller
{
    // Definimos ambas propiedades
    private $handler;
    private $repository;

    // Inyectamos ambos en el constructor
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
}
