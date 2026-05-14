<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class HistoryController extends Controller
{
    public function obtenerHistorialCompleto(Request $request, $pacienteId): JsonResponse
    {
        try {
            $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

            if (!$doctorId) {
                return response()->json([
                    'estado' => 'error',
                    'mensaje' => 'Perfil de médico no encontrado para este usuario.'
                ], 403);
            }

            $pdo = DB::getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerHistorialClinico ?, ?");
            $stmt->execute([$pacienteId, $doctorId]);

            $consultas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $examenes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $comparativos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return response()->json([
                'estado' => 'success',
                'datos' => [
                    'consultas' => $consultas,
                    'examenes' => $examenes,
                    'comparativos' => $comparativos
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => $e->getMessage()
            ], 400);
        }
    }
}
