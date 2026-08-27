<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\Pharmacy\Application\UseCases\GetDashboardMetricsUseCase;
use App\Core\Pharmacy\Application\UseCases\FindOrderByBarcodeUseCase;
use App\Core\Pharmacy\Application\UseCases\SearchPrescriptionUseCase;
use App\Core\Pharmacy\Application\UseCases\ChangePrescriptionStateUseCase;
use App\Core\Pharmacy\Application\UseCases\DispensePrescriptionUseCase;
use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use DomainException;
use Exception;

class PharmacyController extends Controller
{
    public function metrics(Request $request, GetDashboardMetricsUseCase $useCase): JsonResponse
    {
        try {
            $farmaciaId = $request->query('farmacia_id') ? (int) $request->query('farmacia_id') : null;
            $page       = (int) $request->query('page', 1);
            $yaCanjeada = (int) $request->query('ya_canjeada', 0);

            // Se pasan farmaciaId, página, items por página (10) y el estado yaCanjeada
            $data = $useCase->execute($farmaciaId, $page, 10, $yaCanjeada);

            return response()->json([
                'status' => 'success',
                'datos'  => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function scanBarcode(Request $request, FindOrderByBarcodeUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $order = $useCase->execute($validated['code']);

            if (!$order) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No se encontró ninguna receta o producto asociado al código ingresado.'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'datos'  => [
                    'id'          => $order->getId(),
                    'patient'     => $order->getPatientName(),
                    'status'      => $order->getStatus(),
                    'medications' => $order->getMedications(),
                    'barcode'     => $order->getBarcode()
                ]
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function buscarReceta(Request $request, SearchPrescriptionUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'criterio' => 'required|string'
        ]);

        try {
            $recetas = $useCase->execute($validated['criterio']);

            if (empty($recetas)) {
                return response()->json([
                    'status'  => 'error',
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

    public function cambiarEstado(Request $request, int $id, ChangePrescriptionStateUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'nuevo_estado' => 'required|in:Recibida por Farmacia,Reservada',
            'farmacia_id'  => 'required|integer'
        ]);

        try {
            $useCase->execute($id, $validated['nuevo_estado'], (int) $validated['farmacia_id']);

            return response()->json([
                'status'  => 'success',
                'message' => "Estado actualizado a '{$validated['nuevo_estado']}' exitosamente."
            ]);

        } catch (DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function surtir(Request $request, int $id, DispensePrescriptionUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'precio_total' => 'required|numeric|min:0',
            'farmacia_id'  => 'required|integer'
        ]);

        try {
            $result = $useCase->execute($id, (float) $validated['precio_total'], (int) $validated['farmacia_id']);

            return response()->json([
                'status'          => 'success',
                'message'         => 'Receta marcada como Surtida correctamente.',
                'comision_medgo'  => $result['comision_medgo'],
                'monto_facturado' => $result['monto_facturado']
            ], 200);

        } catch (DomainException $e) {
            return response()->json(['status' => 'warning', 'message' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function surtirLote(Request $request, PharmacyRepositoryInterface $pharmacyRepository): JsonResponse
    {
        $validated = $request->validate([
            'receta_ids'   => 'required|array|min:1',
            'receta_ids.*' => 'integer|exists:Recetas,RecetaID',
            'precio_total' => 'required|numeric|min:0.01',
            'farmacia_id'  => 'required|integer'
        ]);

        try {
            $recetaIds   = $validated['receta_ids'];
            $precioTotal = (float) $validated['precio_total'];
            $farmaciaId  = (int) $validated['farmacia_id'];
            $comision    = $precioTotal * 0.03;

            $pharmacyRepository->dispenseBulk($recetaIds, $precioTotal, $comision, $farmaciaId);

            return response()->json([
                'status'         => 'success',
                'comision_medgo' => $comision,
                'mensaje'        => 'Lote surtido correctamente'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
