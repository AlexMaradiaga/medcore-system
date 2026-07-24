<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\SaaS\Domain\Ports\SaaSRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class SaaSController extends Controller
{
    public function __construct(
        private SaaSRepositoryInterface $repository
    ) {}

    public function actualizarPlanMembresia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_plan'      => 'required|string',
            'dias_vigencia'  => 'required|integer',
            'token_pasarela' => 'required|string'
        ]);

        try {
            // Fallback en caso de que la ruta de API no transporte la sesión de Sanctum/JWT
            $usuarioId = auth()->id() ?? $request->input('usuario_id') ?? 4;

            $exito = $this->repository->actualizarPlan(
                (int) $usuarioId,
                $validated['tipo_plan'],
                (int) $validated['dias_vigencia'],
                $validated['token_pasarela']
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Plan SaaS modificado con éxito.',
                'pago_registrado' => $exito
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al actualizar el plan: ' . $e->getMessage()
            ], 500);
        }
    }
    public function obtenerMonitoreoSaaS(): JsonResponse
    {
        try {
            $data = $this->repository->obtenerMonitoreo();
            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()], 500);
        }
    }

    public function actualizarPrecioServicio(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'servicio_id' => 'nullable|integer',
            'doctor_id' => 'required|integer',
            'nombre_servicio' => 'required|string|max:100',
            'precio' => 'required|numeric',
            'estado' => 'required|boolean'
        ]);

        try {
            $this->repository->guardarPrecioServicio($validated);
            return response()->json(['status' => 'success', 'message' => 'Tarifa de servicio grabada exitosamente.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    public function exportarReporte(Request $request)
    {
        $tipo = $request->query('tipo');

        $data = [];
        if ($tipo === 'general') {
            $data = DB::select("EXEC sp_ReporteEjecutivoGeneral");
        } elseif ($tipo === 'por-plan') {
            $data = DB::select("EXEC sp_ReportePorPlan");
        }

        return response()->json($data);
    }
}
