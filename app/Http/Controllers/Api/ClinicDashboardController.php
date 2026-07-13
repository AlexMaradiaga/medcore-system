<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClinicDashboardController extends Controller
{
   public function getDashboardData(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autorizado. Sesión no válida.'
                ], 401);
            }

            $entidadIdRaw = $request->query('entidad_id');

            if (!$entidadIdRaw || $entidadIdRaw === 'NaN' || !is_numeric($entidadIdRaw)) {
                $entidadId = $user->EntidadID;
            } else {
                $entidadId = (int)$entidadIdRaw;
            }

            $pdo = DB::connection('sqlsrv')->getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerDashboardClinica ?");
            $stmt->execute([$entidadId]);

            $kpis = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->nextRowset();
            $citasRecientes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'kpis' => $kpis ? $kpis : [],
                    'citas_recientes' => $citasRecientes
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los KPIs de la clínica: ' . $e->getMessage()
            ], 500);
        }
    }
}
