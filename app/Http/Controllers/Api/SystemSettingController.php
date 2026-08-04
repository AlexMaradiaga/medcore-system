<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Exception;

class SystemSettingController extends Controller
{
    /**
     * Obtiene todas las configuraciones globales.
     */
    public function index(): JsonResponse
    {
        try {
            $settings = DB::table('system_settings')->get();
            return response()->json([
                'status' => 'success',
                'data' => $settings
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener configuraciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualiza o crea una configuración global.
     */
    public function updateSetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'setting_key'   => 'required|string|max:100',
            'setting_value' => 'nullable|string',
            'description'   => 'nullable|string|max:255'
        ]);

        try {
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => $validated['setting_key']],
                [
                    'setting_value' => $validated['setting_value'] ?? null,
                    'description'   => $validated['description'] ?? null,
                    'updated_at'    => now()
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración del sistema actualizada correctamente.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al guardar la configuración: ' . $e->getMessage()
            ], 500);
        }
    }
}
