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
            $stats = $this->repository->getStats();

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
