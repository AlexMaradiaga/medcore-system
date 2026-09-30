<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Services\SaaSSubscriptionProvisioner;
use Exception;

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
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function registrarDoctor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'               => 'required|string|email|max:100|unique:Usuarios,Email',
            'password'            => 'required|string|min:6',
            'nombre'              => 'required|string|max:100',
            'apellido'            => 'required|string|max:100',
            'especialidad_id'     => 'required|integer',
            'numero_colegiado'    => 'required|string|unique:Doctores,NumeroColegiado',
            'entidad_id'          => 'required|integer',

            'fotografia'          => 'required|file|image|mimes:jpg,jpeg,png|max:2048',
            'titulo_medico'       => 'required|file|mimes:pdf,jpg,png|max:3072',
            'titulo_especialista' => 'required|file|mimes:pdf,jpg,png|max:3072',
            'constancia_colegio'  => 'required|file|mimes:pdf,jpg,png|max:2048',
            'dni'                 => 'required|file|mimes:pdf,jpg,png|max:2048',
        ]);

        try {
            $pathFoto       = $request->file('fotografia')->store('doctores/fotos', 'public');
            $pathTituloMed  = $request->file('titulo_medico')->store('doctores/titulos_medicos', 'public');
            $pathTituloEsp  = $request->file('titulo_especialista')->store('doctores/titulos_especialidades', 'public');
            $pathConstancia = $request->file('constancia_colegio')->store('doctores/constancias', 'public');
            $pathDni        = $request->file('dni')->store('doctores/documentos_identidad', 'public');

            DB::beginTransaction();

            $passwordHash = Hash::make($validated['password']);

            $usuarioId = DB::table('Usuarios')->insertGetId([
                'RolID'        => 2,
                'Email'        => $validated['email'],
                'PasswordHash' => $passwordHash,
                'EntidadID'    => $validated['entidad_id'],
                'Estado'       => 1
            ]);

            DB::table('Doctores')->insert([
                'UsuarioID'              => $usuarioId,
                'EspecialidadID'         => $validated['especialidad_id'],
                'Nombre'                 => $validated['nombre'],
                'Apellido'               => $validated['apellido'],
                'NumeroColegiado'        => $validated['numero_colegiado'],

                'RutaFoto'               => $pathFoto,
                'RutaTituloMedico'       => $pathTituloMed,
                'RutaTituloEspecialista' => $pathTituloEsp,
                'RutaConstanciaColegio'  => $pathConstancia,
                'RutaDni'                => $pathDni,

                'EsVerificado'           => 1,
                'Estado'                 => 1
            ]);

            app(SaaSSubscriptionProvisioner::class)->provisionDoctor((int) $usuarioId);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Doctor y credenciales creados con éxito. Expediente guardado correctamente.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            
            if (isset($pathFoto)) {
                Storage::disk('public')->delete([$pathFoto, $pathTituloMed, $pathTituloEsp, $pathConstancia, $pathDni]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Error al procesar el alta del médico: ' . $e->getMessage()
            ], 500);
        }
    }

    public function obtenerDoctoresPendientes(): JsonResponse
    {
        $pendientes = DB::table('Doctores')->where('EsVerificado', 0)->get();
        return response()->json($pendientes);
    }

    public function aprobarDoctor($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $doctor = DB::table('Doctores')->where('DoctorID', $id)->first();

            if (!$doctor) {
                return response()->json(['status' => 'error', 'message' => 'Doctor no encontrado.'], 404);
            }

            DB::table('Doctores')->where('DoctorID', $id)->update(['EsVerificado' => 1]);
            DB::table('Usuarios')->where('UsuarioID', $doctor->UsuarioID)->update(['Estado' => 1]);
            app(SaaSSubscriptionProvisioner::class)->provisionDoctor((int) $doctor->UsuarioID);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Especialista verificado y habilitado correctamente.'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar la aprobación.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function obtenerUsuariosPorRol(Request $request): JsonResponse
    {
        $rol = $request->query('rol_id');
        if ((int) $rol === 2) {
            $usuarios = DB::table('Usuarios as U')
                ->join('Doctores as D', 'D.UsuarioID', '=', 'U.UsuarioID')
                ->leftJoin('Especialidades as E', 'E.EspecialidadID', '=', 'D.EspecialidadID')
                ->where('U.RolID', 2)
                ->select([
                    'U.UsuarioID', 'U.Email', 'U.RolID', 'U.Estado', 'U.EsFounder',
                    'U.NivelFounder', 'U.FechaFounder', 'D.DoctorID', 'D.Nombre', 'D.Apellido',
                    'D.NumeroColegiado', 'D.RutaFoto', 'D.RutaTituloMedico',
                    'D.RutaTituloEspecialista', 'D.RutaConstanciaColegio', 'D.RutaDni',
                    'E.NombreEspecialidad as Especialidad',
                    DB::raw("'Doctor' as NombreRol"),
                    DB::raw("LTRIM(RTRIM(CONCAT(D.Nombre, ' ', D.Apellido))) as NombreCompleto"),
                ])
                ->orderByDesc('U.EsFounder')
                ->orderBy('D.Nombre')
                ->get();
            return response()->json(['status' => 'success', 'data' => $usuarios]);
        }
        $usuarios = DB::select("EXEC sp_ObtenerUsuariosAgrupados ?", [$rol ? (int)$rol : null]);
        return response()->json(['status' => 'success', 'data' => $usuarios]);
    }

    public function documentosDoctor(int $id): JsonResponse
    {
        $doctor = DB::table('Doctores')->where('DoctorID', $id)->first();
        if (!$doctor) {
            return response()->json(['status' => 'error', 'message' => 'Doctor no encontrado.'], 404);
        }

        $documentos = collect([
            ['tipo' => 'Fotografía', 'ruta' => $doctor->RutaFoto],
            ['tipo' => 'Título médico', 'ruta' => $doctor->RutaTituloMedico],
            ['tipo' => 'Título de especialidad', 'ruta' => $doctor->RutaTituloEspecialista],
            ['tipo' => 'Constancia de colegiación', 'ruta' => $doctor->RutaConstanciaColegio],
            ['tipo' => 'Documento de identidad', 'ruta' => $doctor->RutaDni],
        ])->filter(fn ($documento) => !empty($documento['ruta']))
          ->map(fn ($documento) => array_merge($documento, [
              'url' => url(Storage::disk('public')->url($documento['ruta'])),
              'extension' => strtolower(pathinfo($documento['ruta'], PATHINFO_EXTENSION)),
          ]))->values();

        return response()->json(['status' => 'success', 'data' => $documentos]);
    }

    public function actualizarFounder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'es_founder' => ['required', 'boolean'],
            'nivel_founder' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $doctor = DB::table('Doctores')->where('DoctorID', $id)->first();
        if (!$doctor) {
            return response()->json(['status' => 'error', 'message' => 'Doctor no encontrado.'], 404);
        }

        DB::table('Usuarios')->where('UsuarioID', $doctor->UsuarioID)->update([
            'EsFounder' => $validated['es_founder'] ? 1 : 0,
            'NivelFounder' => $validated['es_founder'] ? ($validated['nivel_founder'] ?? 1) : null,
            'FechaFounder' => $validated['es_founder'] ? now() : null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $validated['es_founder'] ? 'El médico fue marcado como Founder.' : 'Se retiró la condición Founder.',
        ]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $usuario = \App\Models\User::findOrFail($id);

        $nuevoEstado = $request->input('estado');

        $usuario->Estado = $nuevoEstado;
        $usuario->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Estado del usuario actualizado correctamente.',
            'usuario' => $usuario
        ]);
    }
    public function obtenerDoctoresPorEntidad(Request $request): JsonResponse
    {
        try {
            $entidadId = $request->query('entidad_id');

            if (!$entidadId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El parámetro entidad_id es requerido.'
                ], 400);
            }

            $doctores = DB::select("
                SELECT
                    U.UsuarioID as UsuarioID,
                    U.EntidadID as EntidadID,
                    U.Estado as Estado,
                    (D.Nombre + ' ' + D.Apellido) as NombreCompleto,
                    D.NumeroColegiado as NumeroColegiado,
                    E.NombreEspecialidad as Especialidad
                FROM Usuarios U
                INNER JOIN Doctores D ON U.UsuarioID = D.UsuarioID
                LEFT JOIN Especialidades E ON D.EspecialidadID = E.EspecialidadID
                WHERE U.RolID = 2 AND U.EntidadID = ?
                ORDER BY D.Nombre ASC
            ", [$entidadId]);

            return response()->json([
                'status' => 'success',
                'data' => $doctores
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los médicos de la entidad: ' . $e->getMessage()
            ], 500);
        }
    }
}
