<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\SaaS\Domain\Ports\SaaSRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;
use App\Services\SaaSLimitService;
use Illuminate\Support\Facades\DB;

class SaaSController extends Controller
{
    public function __construct(
        private SaaSRepositoryInterface $repository,
        private SaaSLimitService $limitService
    ) {}

    public function actualizarPlanMembresia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_plan'      => 'required|string|max:50',
            'dias_vigencia'  => 'required|integer|min:1|max:366',
            'token_pasarela' => 'required|string|max:100'
        ]);

        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'No autenticado.'], 401);
            }

            $usuarioId = $user->UsuarioID ?? $user->getAuthIdentifier();

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

    public function obtenerPlanes(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'No autenticado.'], 401);
            }

            $usuarioId = (int) ($user->UsuarioID ?? $user->getAuthIdentifier());

            return response()->json([
                'status' => 'success',
                'data' => $this->repository->obtenerPlanes($usuarioId),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible consultar los planes: ' . $e->getMessage(),
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

    public function obtenerEstadoSaaS(Request $request): JsonResponse
    {
        try {
            $usuarioId = $request->user()->UsuarioID ?? $request->user()->id ?? auth()->id();
            $estado = $this->limitService->obtenerEstadoSaaSCompleto((int) $usuarioId);

            return response()->json([
                'success' => true,
                'data'    => $estado
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar estado SaaS: ' . $e->getMessage()
            ], 500);
        }
    }
}
