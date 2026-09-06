<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\Payments\Domain\Ports\PaymentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentRepositoryInterface $repository
    ) {}

    public function obtenerCatalogoPrecios(Request $request): JsonResponse
    {
        $doctorId = $request->query('doctor_id') ? (int) $request->query('doctor_id') : null;
        $precios = $this->repository->obtenerCatalogoPrecios($doctorId, auth()->id());

        return response()->json($precios);
    }

    public function obtenerPerfilUbicacion(Request $request): JsonResponse
    {
        $doctorId = $request->query('doctor_id') ? (int) $request->query('doctor_id') : null;
        $perfil = $this->repository->obtenerPerfilUbicacion($doctorId, auth()->id());

        return response()->json($perfil);
    }

    public function guardarCatalogoYUbicacion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doctor_id'             => 'nullable|integer',
            'servicios'             => 'required|array',
            'servicios.*.ServicioID'=> 'required|integer',
            'servicios.*.Precio'    => 'required|numeric|min:0',
            'direccion_consultorio' => 'nullable|string|max:255',
            'latitud'               => 'nullable|numeric',
            'longitud'              => 'nullable|numeric',
            'habla_ingles'          => 'nullable|boolean',
            'disponible_domicilio'  => 'nullable|boolean',
        ]);

        $this->repository->guardarCatalogoYUbicacion($validated, auth()->id());

        return response()->json([
            'status'  => 'success',
            'message' => 'Tarifas y datos del consultorio actualizados correctamente.'
        ]);
    }

    public function registrarPago(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cita_id'     => 'required|integer',
            'servicio_id' => 'required|integer',
            'monto'       => 'required|numeric',
            'metodo'      => 'required|string',
            'referencia'  => 'nullable|string'
        ]);

        $this->repository->procesarPago([
            'consulta_id'         => $data['cita_id'],
            'monto'               => $data['monto'], // <-- ¡PASAR MONTO!
            'metodo_pago'         => $data['metodo'],
            'referencia_pasarela' => $data['referencia'] ?? 'N/A'
        ]);

        return response()->json(['status' => 'success']);
    }
}
