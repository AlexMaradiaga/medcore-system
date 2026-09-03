<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\Clinics\Domain\Ports\ClinicRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClinicController extends Controller
{
    public function __construct(
        private ClinicRepositoryInterface $repository
    ) {}

    public function index(): JsonResponse
    {
        $clinicas = $this->repository->getAllActive();
        return response()->json($clinicas);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'    => 'required|string|max:150',
            'rtn'       => 'nullable|string|max:20',
            'direccion' => 'nullable|string',
            'telefono'  => 'nullable|string|max:20'
        ]);

        $this->repository->store($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Clínica registrada correctamente'
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'nombre'    => 'required|string|max:150',
            'rtn'       => 'nullable|string|max:20',
            'direccion' => 'nullable|string',
            'telefono'  => 'nullable|string|max:20'
        ]);

        $this->repository->update($id, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Clínica actualizada'
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $this->repository->delete($id);
        return response()->json([
            'status' => 'success',
            'message' => 'Clínica desactivada'
        ]);
    }

    public function getEntidadesPublicas(): JsonResponse
    {
        try {
            $entidades = \Illuminate\Support\Facades\DB::table('Entidades')
                ->select(
                    'EntidadID',
                    'NombreEntidad', // Se mantiene el nombre real de la columna para el Frontend
                    'TipoEntidad',
                    'Direccion',
                    'Telefono'
                )
                ->where('Estado', 1)
                ->where('TipoEntidad', function ($query) {
                    $query->select('NombreRol')
                        ->from('Roles')
                        ->where('NombreRol', 'Laboratorio');
                })
                ->get();

            return response()->json($entidades, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener laboratorios: ' . $e->getMessage()
            ], 500);
        }
    }
}
