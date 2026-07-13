<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDO;

class LaboratoryDashboardController extends Controller
{
    public function getDashboardData(Request $request): JsonResponse
    {
        try {
            $entidadId = $request->user()->EntidadID ?? null;

            if (!$entidadId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no pertenece a ninguna entidad analítica.'
                ], 403);
            }

            $pdo = DB::connection('sqlsrv')->getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerDashboardLaboratorio ?");
            $stmt->execute([$entidadId]);

            $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->nextRowset();
            $ordenesRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'kpis' => $kpis ? $kpis : [],
                    'ordenes_recientes' => $ordenesRecientes
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al capturar métricas del laboratorio: ' . $e->getMessage()
            ], 500);
        }
    }
}
