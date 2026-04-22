<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;

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
            // Llamamos al método que definimos en la interfaz y el repositorio
            $stats = $this->repository->getStats();
            
            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            // Esto te dirá exactamente qué falla en el JSON de Postman
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}