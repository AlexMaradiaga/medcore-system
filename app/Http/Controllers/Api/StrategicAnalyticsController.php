<?php

namespace App\Http\Controllers\Api;

use App\Core\Reports\Domain\Ports\StrategicAnalyticsRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\StrategicReportExporter;
use Throwable;

class StrategicAnalyticsController extends Controller
{
    public function __construct(
        private StrategicAnalyticsRepositoryInterface $repository,
        private StrategicReportExporter $exporter
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, true);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->repository->getDashboard($filters),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al generar la analítica estratégica de MedGo+', [
                'message' => $exception->getMessage(),
                'filters' => $filters,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible generar la analítica estratégica.',
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        $filters = $this->validateFilters($request);
        $report = $this->repository->getExecutiveReportData($filters);
        $filename = 'reporte_ejecutivo_contable_medgo_' . now()->format('Ymd_His') . '.pdf';

        return Pdf::loadView('pdf.reporte-ejecutivo-contable', ['report' => $report])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    public function exportExcel(Request $request)
    {
        $filters = $this->validateFilters($request);
        $report = $this->repository->getExecutiveReportData($filters);
        $path = $this->exporter->createExcel($report);
        $filename = 'reporte_ejecutivo_contable_medgo_' . now()->format('Ymd_His') . '.xlsx';

        return response()->download(
            $path,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function validateFilters(Request $request, bool $withPagination = false): array
    {
        $rules = [
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
        ];
        if ($withPagination) {
            $rules += [
                'entity_page' => ['nullable', 'integer', 'min:1'],
                'revenue_page' => ['nullable', 'integer', 'min:1'],
                'clinical_page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:3', 'max:20'],
            ];
        }

        return $request->validate($rules);
    }
}
