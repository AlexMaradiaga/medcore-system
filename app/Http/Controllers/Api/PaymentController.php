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
}
