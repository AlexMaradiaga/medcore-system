<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EntityController extends Controller
{
    /**
     * Obtiene el listado de entidades habilitadas.
     * Si recibe el parámetro ?tipo=, filtra por ese tipo exacto o por grupo.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tipo = $request->query('tipo');
            $query = DB::table('Entidades')->where('Estado', 1);

            if ($tipo) {
                if (strtolower($tipo) === 'instituciones') {
                    $query->whereIn('TipoEntidad', ['Clinica', 'Farmacia', 'Laboratorio']);
                } else {
                    $query->where('TipoEntidad', $tipo);
                }
            }

            $entidades = $query->get();

            return response()->json([
                'status' => 'success',
                'data'   => $entidades
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al obtener entidades: ' . $e->getMessage()
            ], 500);
        }
    }
}