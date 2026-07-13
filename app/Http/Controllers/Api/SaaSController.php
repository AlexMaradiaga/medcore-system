<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class SaaSController extends Controller
{
    public function actualizarPlanMembresia(Request $request)
    {
        $request->validate([
            'tipo_plan' => 'required|string',
            'dias_vigencia' => 'required|integer',
            'token_pasarela' => 'required|string'
        ]);

        try {
            DB::transaction(function () use ($request) {

                DB::statement("EXEC sp_ActualizarPlanSuscripcion NULL, ?, ?, ?, ?, ?", [
                    auth()->id(),
                    $request->tipo_plan,
                    'Activo',
                    $request->dias_vigencia,
                    $request->token_pasarela
                ]);

                $plan = DB::table('PlanesSaaS')->where('NombrePlan', $request->tipo_plan)->first();

                if ($plan) {
                    DB::table('HistorialPagosSaaS')->insert([
                        'UsuarioID'     => auth()->id(),
                        'PlanID'        => $plan->PlanID,
                        'MontoPagado'   => $plan->TarifaMensual,
                        'TokenPasarela' => $request->token_pasarela,
                        'FechaPago'     => now()
                    ]);
                }
            });

            return response()->json(['status' => 'success', 'message' => 'Plan SaaS modificado e historial registrado con éxito.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    public function actualizarPrecioServicio(Request $request)
    {
        $request->validate([
            'servicio_id' => 'nullable|integer',
            'doctor_id' => 'required|integer',
            'nombre_servicio' => 'required|string|max:100',
            'precio' => 'required|numeric',
            'estado' => 'required|boolean'
        ]);

        try {
            DB::statement("EXEC sp_GuardarServicioMedico ?, ?, ?, ?, ?", [
                $request->servicio_id,
                $request->doctor_id,
                $request->nombre_servicio,
                $request->precio,
                $request->estado
            ]);
            return response()->json(['status' => 'success', 'message' => 'Tarifa de servicio grabada.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    public function obtenerMonitoreoSaaS(): \Illuminate\Http\JsonResponse
    {
        try {
            $results = DB::select("EXEC sp_ObtenerMonitoreoSaaS");


            $pdo = DB::getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerMonitoreoSaaS");
            $stmt->execute();

            $data = [];
            $data['kpis'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['MRR'=>0, 'PremiumActivos'=>0, 'PlanesGratis'=>0, 'PorVencer'=>0];

            $stmt->nextRowset();
            $data['suscripciones'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $stmt->nextRowset();
            $data['transacciones'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $stmt->closeCursor();

            return response()->json([
                'status' => 'success',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error("Error crítico en Monitoreo SaaS: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()], 500);
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
