<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;
use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;

class LaboratoryDashboardController extends Controller
{
    public function __construct(
        private LaboratoryRepositoryInterface $repository
    ) {}

    public function getDashboardData(Request $request): JsonResponse
    {
        try {
            $entidadId = $request->query('laboratorio_id')
                      ?? $request->user()?->EntidadID
                      ?? $request->user()?->entidad_id;

            if (!$entidadId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'El usuario no pertenece a ninguna entidad analítica.'
                ], 403);
            }

            $metrics = $this->repository->getDashboardMetrics((int)$entidadId);

            return response()->json([
                'status' => 'success',
                'data'   => $metrics
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al capturar métricas del laboratorio: ' . $e->getMessage()
            ], 500);
        }
    }
}
