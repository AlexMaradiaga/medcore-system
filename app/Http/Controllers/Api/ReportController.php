<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ReportController extends Controller
{
    public function __construct(
        private AppointmentRepositoryInterface $repository
    ) {}

    public function appointmentsReport(Request $request)
    {
        $filters = $request->only(['doctor_id', 'fecha_inicio', 'fecha_fin', 'estado']);
        $data = $this->repository->getDetailedReport($filters);

        return response()->json([
            'status' => 'success',
            'count' => count($data),
            'data' => $data
        ]);
    }

    public function dashboardStats()
    {
        try {
            $stats = $this->repository->getStats();

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function obtenerReportesAnaliticos()
    {
        try {
            $pdo = DB::connection()->getPdo();

            $stmt = $pdo->prepare("EXEC sp_ObtenerDashboardAnaliticoAdmin");
            $stmt->execute();

            $funnelRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $profesionalesRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $pacientesRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $heatmapRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $evolucionRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'funnel' => $funnelRaw,
                    'profesionales' => $profesionalesRaw,
                    'pacientes' => $pacientesRaw,
                    'heatmap' => $heatmapRaw,
                    'evolucion' => $evolucionRaw
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Error en ReportController@obtenerReportesAnaliticos: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al calcular la matriz analítica en SQL Server.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function obtenerIndicadoresCalidad(): \Illuminate\Http\JsonResponse
    {
        try {
            $resultados = \Illuminate\Support\Facades\DB::select('EXEC sp_ObtenerIndicadoresCalidad');

            return response()->json([
                'status' => 'success',
                'data' => $resultados[0] ?? null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener indicadores de auditoría.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
