<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Patients\Application\Handlers\RegisterPatientHandler;
use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    private $handler;
    private $repository;

    public function __construct(
        RegisterPatientHandler $handler,
        PatientRepositoryInterface $repository
    ) {
        $this->handler = $handler;
        $this->repository = $repository;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $esDependiente = filter_var($request->input('es_dependiente'), FILTER_VALIDATE_BOOLEAN);

            $datos = $request->validate([
                'dni'                      => 'required|string',
                'nombre'                   => 'required|string',
                'apellido'                 => 'required|string',
                'telefono'                 => 'nullable|string',
                'entidad_id'               => 'required|integer',
                'es_dependiente'           => 'nullable',

                'nacionalidad'             => 'nullable|string|max:50',
                'tipo_sangre'              => 'nullable|string|max:10',

                'email'                    => $esDependiente ? 'nullable|email' : 'required|email',
                'password'                 => $esDependiente ? 'nullable|string|min:6' : 'required|string|min:6',

                'tutor_dni'                => $esDependiente ? 'required|string' : 'nullable|string',
                'tutor_nombre'             => $esDependiente ? 'required|string' : 'nullable|string',
                'tutor_email'              => $esDependiente ? 'required|email' : 'nullable|email',
                'tutor_telefono'           => $esDependiente ? 'required|string' : 'nullable|string',
                'documento_identidad_url'  => $esDependiente ? 'required|string' : 'nullable|string'
            ]);

            $this->handler->execute($datos);

            return response()->json([
                'status' => 'success',
                'message' => 'Paciente registrado correctamente en MedGo+'
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    public function index(): JsonResponse
    {
        $data = $this->repository->obtenerTodos();

        return response()->json([
            'status' => 'success',
            'count'  => count($data),
            'data'   => $data
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'      => 'required|email',
                'nombre'     => 'required|string',
                'apellido'   => 'required|string',
                'telefono'   => 'required|string',
                'entidad_id' => 'required|integer',
                'nacionalidad' => 'nullable|string|max:50',
                'tipo_sangre'  => 'nullable|string|max:10'
            ]);

            $this->repository->update((int)$id, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Datos de paciente actualizados'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete((int)$id);
            return response()->json([
                'status' => 'success',
                'message' => 'Paciente desactivado'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    public function obtenerPorUsuario($usuarioId)
    {
        // Consultamos directamente el paciente por su UsuarioID
        $paciente = DB::table('Pacientes')
            ->where('UsuarioID', $usuarioId)
            ->where('Estado', 1)
            ->select([
                'PacienteID as id',
                'UsuarioID as usuario_id',
                'Nombre as nombre',
                'Apellido as apellido',
                'DNI as dni',
                'Telefono as telefono',
                'Edad as fecha_nacimiento',
                'Genero as genero',
                'TipoSangre as tipo_sangre',
                'Aseguradora as aseguradora',
                'NumeroPoliza as poliza',
                'NombreContactoEmergencia as nombre_contacto_emergencia',
                'TelefonoContactoEmergencia as telefono_contacto_emergencia',
                'es_dependiente',
                'TutorID as tutor_id',
                'parentesco',
                'tutor_dni',
                'tutor_nombre',
                'tutor_telefono',
                'tutor_email'
            ])
            ->first();

        if ($paciente) {
            $dependientes = $this->repository->obtenerDependientesPorTutorId((int)$paciente->id);

            return response()->json([
                'status'       => 'success',
                'es_tutor'     => count($dependientes) > 0,
                'data'         => $paciente,
                'dependientes' => $dependientes
            ], 200);
        }

        $dependienteDirecto = DB::table('Pacientes as P')
            ->leftJoin('Pacientes as T', 'P.TutorID', '=', 'T.PacienteID')
            ->where('P.TutorID', $usuarioId)
            ->orWhere('P.PacienteID', $usuarioId)
            ->where('P.Estado', 1)
            ->select([
                'P.PacienteID as id',
                'P.UsuarioID as usuario_id',
                'P.Nombre as nombre',
                'P.Apellido as apellido',
                'P.DNI as dni',
                'P.Telefono as telefono',
                'P.Edad as fecha_nacimiento',
                'P.Genero as genero',
                'P.TipoSangre as tipo_sangre',
                'P.Aseguradora as aseguradora',
                'P.NumeroPoliza as poliza',
                'P.NombreContactoEmergencia as nombre_contacto_emergencia',
                'P.TelefonoContactoEmergencia as telefono_contacto_emergencia',
                'P.es_dependiente',
                'P.TutorID as tutor_id',
                'P.parentesco',
                'T.Nombre as tutor_nombre',
                'T.DNI as tutor_dni',
                'T.Telefono as tutor_telefono'
            ])
            ->first();

        if ($dependienteDirecto) {
            return response()->json([
                'status'       => 'success',
                'es_tutor'     => false,
                'data'         => $dependienteDirecto,
                'dependientes' => []
            ], 200);
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'El usuario no cuenta con un perfil clínico registrado.'
        ], 404);
    }

    public function emanciparPaciente(Request $request, $pacienteId): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:Usuarios,Email',
            'password' => 'required|string|min:6'
        ]);

        try {
            DB::beginTransaction();

            $paciente = DB::table('Pacientes')->where('PacienteID', $pacienteId)->first();
            if (!$paciente) {
                return response()->json(['status' => 'error', 'message' => 'Paciente no encontrado.'], 404);
            }

            $rolPaciente = DB::table('roles')
                ->where('NombreRol', 'Paciente')
                ->where('Estado', 1)
                ->first();

            if (!$rolPaciente) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El rol estructural "Paciente" no se encuentra activo o configurado en el sistema.'
                ], 500);
            }

            $entidadId = $paciente->EntidadID ?? $paciente->entidad_id ?? 1;

            $usuarioId = DB::table('Usuarios')->insertGetId([
                'RolID'        => $rolPaciente->RolID,
                'Email'        => $validated['email'],
                'PasswordHash' => Hash::make($validated['password']),
                'EntidadID'    => $entidadId,
                'Estado'       => 1
            ]);

            DB::table('Pacientes')
                ->where('PacienteID', $pacienteId)
                ->update([
                    'UsuarioID'      => $usuarioId,
                    'es_dependiente' => 0,
                    'TutorID'        => null,
                    'parentesco'     => null
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'El paciente ha sido promovido a usuario independiente con éxito.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function autoRegistroTutor(Request $request)
    {
        $validated = $request->validate([
            'UsuarioID' => 'required|integer',
            'DNI' => 'required|string|unique:Pacientes,DNI',
            'Nombre' => 'required|string',
            'Apellido' => 'required|string',
            'Telefono' => 'required|string',
            'Nacionalidad' => 'nullable|string|max:50',
            'TipoSangre'   => 'nullable|string|max:10',
        ]);

        DB::table('Pacientes')->insert([
            'UsuarioID'      => $validated['UsuarioID'],
            'DNI'            => $validated['DNI'],
            'Nombre'         => $validated['Nombre'],
            'Apellido'       => $validated['Apellido'],
            'Telefono'       => $validated['Telefono'],
            'Nacionalidad'   => $validated['Nacionalidad'] ?? 'Hondureña',
            'TipoSangre'     => $validated['TipoSangre'] ?? null,
            'es_dependiente' => 0,
            'Estado'         => 1
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Tu perfil clínico individual ha sido creado con éxito.'
        ]);
    }
}
