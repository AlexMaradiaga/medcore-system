<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function obtenerUsuarios(): JsonResponse
    {
        try {
            $usuarios = DB::select("
                SELECT
                    U.UsuarioID,
                    U.Email,
                    U.RolID,
                    U.Estado,
                    CASE
                        WHEN U.RolID = 1 THEN 'Administrador'
                        WHEN U.RolID = 2 THEN 'Médico / Prestador'
                        WHEN U.RolID = 3 THEN 'Paciente'
                        ELSE 'Desconocido'
                    END as NombreRol,
                    COALESCE(D.Nombre + ' ' + D.Apellido, P.Nombre + ' ' + P.Apellido, 'Admin Sistema') as NombreCompleto
                FROM Usuarios U
                LEFT JOIN Doctores D ON U.UsuarioID = D.UsuarioID
                LEFT JOIN Pacientes P ON U.UsuarioID = P.UsuarioID
                ORDER BY U.UsuarioID DESC
            ");

            return response()->json([
                'status' => 'success',
                'data' => $usuarios
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function registrarDoctor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:Usuarios,Email',
            'password' => 'required|string|min:6',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'especialidad_id' => 'required|integer',
            'numero_colegiado' => 'required|string|unique:Doctores,NumeroColegiado',
            'entidad_id' => 'required|integer'
        ]);

        try {
            DB::beginTransaction();

            $passwordHash = Hash::make($validated['password']);

            $usuarioId = DB::table('Usuarios')->insertGetId([
                'EntidadID' => $validated['entidad_id'],
                'RolID' => 2,
                'Email' => $validated['email'],
                'PasswordHash' => $passwordHash,
                'Estado' => 1
            ]);


            DB::table('Doctores')->insert([
                'UsuarioID' => $usuarioId,
                'EspecialidadID' => $validated['especialidad_id'],
                'Nombre' => $validated['nombre'],
                'Apellido' => $validated['apellido'],
                'NumeroColegiado' => $validated['numero_colegiado'],
                'RutaDocumentoValidacion' => 'uploads/validaciones/default.pdf',
                'EsVerificado' => 1,
                'Estado' => 1
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Doctor y credenciales de usuario creados con éxito.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar el alta del médico: ' . $e->getMessage()
            ], 500);
        }
    }
}
