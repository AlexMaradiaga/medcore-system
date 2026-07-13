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
                'entidad_id' => 'required|integer'
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
        $paciente = DB::table('Pacientes')
            ->where('UsuarioID', $usuarioId)
            ->first();

        $usuario = DB::table('Usuarios')->where('UsuarioID', $usuarioId)->first();
        $dependientes = collect();
        $tutor = null;

        if ($usuario) {
            $emailUsuario = $usuario->Email ?? $usuario->email ?? '';

            $tutor = DB::table('Tutores')->where('Email', $emailUsuario)->first();
            if ($tutor) {
                $dependientes = DB::table('Pacientes')->where('TutorID', $tutor->TutorID)->get();
            }
        }

        if ($paciente) {
            return response()->json([
                'status' => 'success',
                'es_tutor' => $dependientes->isNotEmpty(),
                'necesita_perfil_tutor' => false,
                'data' => $paciente,
                'todos_los_dependientes' => $dependientes->isNotEmpty()
                    ? array_merge([$paciente], $dependientes->toArray())
                    : [$paciente]
            ]);
        }

        if ($dependientes->isNotEmpty()) {
            return response()->json([
                'status' => 'success',
                'es_tutor' => true,
                'necesita_perfil_tutor' => true,
                'data' => $dependientes->first(),
                'todos_los_dependientes' => $dependientes
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'El usuario no cuenta con un perfil clínico en la tabla Pacientes.'
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
        ]);

        $usuario = DB::table('Usuarios')->where('UsuarioID', $validated['UsuarioID'])->first();

        DB::table('Pacientes')->insert([
            'UsuarioID' => $validated['UsuarioID'],
            'DNI' => $validated['DNI'],
            'Nombre' => $validated['Nombre'],
            'Apellido' => $validated['Apellido'],
            'Telefono' => $validated['Telefono'],
            'es_dependiente' => 0,
            'Estado' => 1
        ]);

        return response()->json(['status' => 'success', 'message' => 'Tu perfil clínico individual ha sido creado con éxito.']);
    }
}
