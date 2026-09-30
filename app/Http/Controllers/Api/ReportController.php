<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\AppointmentFinancialReportExporter;
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

    public function appointmentsFinancialReport(Request $request)
    {
        $filters = $this->validateFinancialFilters($request, true);

        try {
            $rows = $this->repository->getFinancialReport($filters);
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
            $report = $this->buildFinancialReport($rows);
            $total = count($rows);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $report['data'] = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $report['catalogs'] = $this->repository->getFinancialReportCatalogs();
            $report['meta'] = array_merge($report['meta'], [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ]);

            return response()->json($report);
        } catch (Exception $e) {
            Log::error('Error generando el reporte financiero de citas', [
                'message' => $e->getMessage(),
                'filters' => $filters,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible generar el reporte de citas y cobros.',
            ], 500);
        }
    }

    public function exportAppointmentsFinancialPdf(Request $request)
    {
        $filters = $this->validateFinancialFilters($request, false);
        $report = $this->buildFinancialReport($this->repository->getFinancialReport($filters));
        $report['filters'] = $filters;

        return Pdf::loadView('pdf.reporte-citas-cobros-ejecutivo', ['report' => $report])
            ->setPaper('a4', 'landscape')
            ->download('medgo-reporte-citas-cobros-' . now()->format('Y-m-d') . '.pdf');
    }

    public function exportAppointmentsFinancialExcel(Request $request, AppointmentFinancialReportExporter $exporter)
    {
        $filters = $this->validateFinancialFilters($request, false);
        $report = $this->buildFinancialReport($this->repository->getFinancialReport($filters));
        $report['filters'] = $filters;
        $path = $exporter->createExcel($report);

        return response()->download(
            $path,
            'medgo-reporte-citas-cobros-' . now()->format('Y-m-d') . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function validateFinancialFilters(Request $request, bool $withPagination): array
    {
        $rules = [
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'entidad_id' => ['nullable', 'integer', 'min:1'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'estado' => ['nullable', 'string', 'max:50'],
            'paciente' => ['nullable', 'string', 'max:150'],
            'metodo_pago' => ['nullable', 'string', 'max:50'],
            'estado_pago' => ['nullable', 'string', 'max:50'],
            'solo_con_pago' => ['nullable', 'boolean'],
        ];
        if ($withPagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:10', 'max:100'];
        }
        return $request->validate($rules);
    }

    private function buildFinancialReport(array $rows): array
    {
        $totalCollected = array_sum(array_map(fn ($row) => (float) ($row['MontoCobrado'] ?? 0), $rows));
        $completed = count(array_filter($rows, fn ($row) => strcasecmp((string) ($row['EstadoCita'] ?? ''), 'Completada') === 0));
        $cancelled = count(array_filter($rows, fn ($row) => strcasecmp((string) ($row['EstadoCita'] ?? ''), 'Cancelada') === 0));
        $withPayment = count(array_filter($rows, fn ($row) => (float) ($row['MontoCobrado'] ?? 0) > 0));
        $statusGroups = [];
        $monthGroups = [];
        $doctorGroups = [];
        foreach ($rows as $row) {
            $status = (string) ($row['EstadoCita'] ?? 'Sin estado');
            $statusGroups[$status] = ($statusGroups[$status] ?? 0) + 1;
            $date = (string) ($row['FechaHora'] ?? '');
            $month = strlen($date) >= 7 ? substr($date, 0, 7) : 'Sin fecha';
            $monthGroups[$month] = ($monthGroups[$month] ?? 0) + (float) ($row['MontoCobrado'] ?? 0);
            $doctor = (string) ($row['Doctor'] ?? 'Sin médico');
            $doctorGroups[$doctor]['citas'] = ($doctorGroups[$doctor]['citas'] ?? 0) + 1;
            $doctorGroups[$doctor]['cobrado'] = ($doctorGroups[$doctor]['cobrado'] ?? 0) + (float) ($row['MontoCobrado'] ?? 0);
        }
        ksort($monthGroups);
        uasort($doctorGroups, fn ($a, $b) => $b['cobrado'] <=> $a['cobrado']);
        $total = count($rows);
        $collectionRate = $total > 0 ? round(($withPayment / $total) * 100, 1) : 0;
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
        $topDoctor = array_key_first($doctorGroups);

        return [
            'status' => 'success',
            'data' => $rows,
            'summary' => [
                'total_citas' => $total,
                'completadas' => $completed,
                'canceladas' => $cancelled,
                'con_pago' => $withPayment,
                'monto_cobrado' => round($totalCollected, 2),
                'ticket_promedio' => $withPayment > 0 ? round($totalCollected / $withPayment, 2) : 0,
                'tasa_cobro' => $collectionRate,
                'tasa_finalizacion' => $completionRate,
                'saldo_sin_cobro' => count(array_filter($rows, fn ($row) => (float) ($row['MontoCobrado'] ?? 0) <= 0)),
            ],
            'analytics' => [
                'estados' => array_map(fn ($name, $value) => ['estado' => $name, 'cantidad' => $value], array_keys($statusGroups), array_values($statusGroups)),
                'recaudacion_mensual' => array_map(fn ($period, $value) => ['periodo' => $period, 'monto' => round($value, 2)], array_keys($monthGroups), array_values($monthGroups)),
                'por_doctor' => array_map(fn ($name, $value) => ['doctor' => $name, 'citas' => $value['citas'], 'cobrado' => round($value['cobrado'], 2)], array_keys($doctorGroups), array_values($doctorGroups)),
            ],
            'interpretation' => sprintf(
                'Se analizaron %d citas: %.1f%% finalizaron y %.1f%% registran cobro. La recaudación asciende a L %s, con ticket promedio de L %s. %d citas no muestran cobro aprobado.%s',
                $total, $completionRate, $collectionRate, number_format($totalCollected, 2, '.', ','),
                number_format($withPayment > 0 ? $totalCollected / $withPayment : 0, 2, '.', ','),
                count(array_filter($rows, fn ($row) => (float) ($row['MontoCobrado'] ?? 0) <= 0)),
                $topDoctor ? ' El médico con mayor recaudación es ' . $topDoctor . '.' : ''
            ),
            'meta' => ['max_results' => 5000, 'generated_at' => now()->toIso8601String()],
        ];
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
