<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\Payments\Domain\Ports\PaymentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentRepositoryInterface $repository
    ) {}

    public function obtenerCatalogoPrecios()
    {
        $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

        $precios = DB::select("EXEC sp_ObtenerPreciosDoctor @DoctorID = ?", [$doctorId]);
        return response()->json($precios);
    }

    public function registrarPago(Request $request)
    {
        $data = $request->validate([
            'cita_id'     => 'required|integer',
            'servicio_id' => 'required|integer',
            'monto'       => 'required|numeric',
            'metodo'      => 'required|string',
            'referencia'  => 'nullable|string'
        ]);

        DB::statement("EXEC sp_ProcesarPago ?, ?, ?, ?, ?, ?, ?, ?", [
            auth()->id(),
            (int) $data['servicio_id'],
            (int) $data['cita_id'],
            'CONSULTA',
            (float) $data['monto'],
            $data['metodo'],
            $data['referencia'] ?? 'N/A',
            'PROCESADO'
        ]);

        return response()->json(['status' => 'success']);
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

        $doctorId = $validated['doctor_id']
            ?? DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

        DB::transaction(function () use ($doctorId, $validated) {
            // 1. Actualizar catálogo dinámico de servicios
            foreach ($validated['servicios'] as $serv) {
                DB::table('Servicios_Medicos')
                    ->where('ServicioID', $serv['ServicioID'])
                    ->where('DoctorID', $doctorId)
                    ->update(['Precio' => $serv['Precio']]);
            }

            DB::table('Doctores')
                ->where('DoctorID', $doctorId)
                ->update([
                    'DireccionConsultorio' => $validated['direccion_consultorio'] ?? null,
                    'Latitud'              => $validated['latitud'] ?? null,
                    'Longitud'             => $validated['longitud'] ?? null,
                    'HablaIngles'          => filter_var($validated['habla_ingles'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                    'DisponibleDomicilio'  => filter_var($validated['disponible_domicilio'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                ]);
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Tarifas y datos del consultorio actualizados correctamente.'
        ]);
    }
}
