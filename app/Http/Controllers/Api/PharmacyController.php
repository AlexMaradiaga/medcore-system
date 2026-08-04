<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Exception;

class PharmacyController extends Controller
{
    /**
     * Búsqueda de receta por Código de Canje o DNI del Paciente
     */
    public function buscarReceta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'criterio' => 'required|string'
        ]);

        try {
            $criterio = trim($validated['criterio']);

            $recetas = DB::table('Recetas as R')
                ->join('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
                ->join('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
                ->join('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
                ->join('Doctores as D', 'C.DoctorID', '=', 'D.DoctorID')
                ->select(
                    'R.RecetaID',
                    'R.CodigoCanje',
                    'R.NombreMedicamento',
                    'R.Dosis',
                    'R.Indicaciones',
                    'R.EstadoReceta',
                    'R.YaCanjeada',
                    'R.FechaEmision',
                    'R.PrecioTotal',
                    DB::raw("P.Nombre + ' ' + P.Apellido as Paciente"),
                    'P.DNI as PacienteDNI',
                    'P.Telefono as PacienteTelefono',
                    DB::raw("D.Nombre + ' ' + D.Apellido as MedicoTratante"),
                    'C.DoctorID'
                )
                ->where('R.CodigoCanje', $criterio)
                ->orWhere('P.DNI', $criterio)
                ->orderBy('R.FechaEmision', 'DESC')
                ->get();

            if ($recetas->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontraron recetas activas asociadas al criterio ingresado.'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'datos'  => $recetas
            ], 200);

        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cambio de estado: Transiciones (Recibida por Farmacia -> Reservada)
     */
    public function cambiarEstado(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'nuevo_estado' => 'required|in:Recibida por Farmacia,Reservada',
            'farmacia_id'  => 'required|integer'
        ]);

        try {
            $afectados = DB::table('Recetas')
                ->where('RecetaID', $id)
                ->where('YaCanjeada', 0)
                ->update([
                    'EstadoReceta' => $validated['nuevo_estado'],
                    'FarmaciaID'   => $validated['farmacia_id']
                ]);

            if (!$afectados) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La receta ya fue surtida previamente o no existe.'
                ], 400);
            }

            return response()->json([
                'status'  => 'success',
                'message' => "Estado actualizado a '{$validated['nuevo_estado']}' exitosamente."
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Endpoint: /api/farmacia/recetas/{id}/surtir
     * Surtido final, deducción/confirmación e inserción de comisión del 3%
     */
    public function surtir(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'precio_total' => 'required|numeric|min:0',
            'farmacia_id'  => 'required|integer'
        ]);

        try {
            $receta = DB::table('Recetas as R')
                ->join('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
                ->join('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
                ->select('R.*', 'C.DoctorID')
                ->where('R.RecetaID', $id)
                ->first();

            if (!$receta) {
                return response()->json(['status' => 'error', 'message' => 'Receta no encontrada.'], 404);
            }

            if ($receta->YaCanjeada == 1 || $receta->EstadoReceta === 'Surtida') {
                return response()->json([
                    'status'  => 'warning',
                    'message' => 'Esta receta médica ya fue surtida anteriormente.'
                ], 400);
            }

            $precioTotal = floatval($validated['precio_total']);
            // Cálculo de comisión fija del 3%
            $comision3Porc = round($precioTotal * 0.03, 2);

            DB::beginTransaction();

            // 1. Marcar Receta como Surtida
            DB::table('Recetas')
                ->where('RecetaID', $id)
                ->update([
                    'EstadoReceta'  => 'Surtida',
                    'YaCanjeada'    => 1,
                    'FarmaciaID'    => $validated['farmacia_id'],
                    'PrecioTotal'   => $precioTotal,
                    'ComisionMonto' => $comision3Porc,
                    'FechaSurtido'  => now()
                ]);

            // 2. Registrar la comisión del 3% a favor de MedGo+ en el libro contable SaaS
            if ($comision3Porc > 0) {
                DB::table('Facturacion_SaaS')->insert([
                    'DoctorID'   => $receta->DoctorID,
                    'EntidadID'  => $validated['farmacia_id'],
                    'Concepto'   => "Comisión Farmacia (3%) - Receta #{$receta->CodigoCanje}",
                    'Monto'      => $comision3Porc,
                    'FechaCargo' => now(),
                    'Estado'     => 'Pendiente'
                ]);
            }

            DB::commit();

            return response()->json([
                'status'           => 'success',
                'message'          => 'Receta marcada como Surtida correctamente.',
                'comision_medgo'   => $comision3Porc,
                'monto_facturado'  => $precioTotal
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
