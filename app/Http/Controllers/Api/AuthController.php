<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\LoginRequest;
use App\Http\Controllers\Controller;
use App\Core\Auth\Application\Commands\LoginCommand;
use App\Core\Auth\Application\Handlers\LoginHandler;
use App\Core\Auth\Domain\Ports\AuthRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    public function __construct(
        private AuthRepositoryInterface $repository
    ) {}

    public function login(LoginRequest $request, LoginHandler $handler): JsonResponse
    {
        try {
            $command = new LoginCommand(
                $request->email,
                $request->password
            );

            $usuario = $handler->handle($command);
            $userModel = User::find($usuario->id);

            if (!$userModel) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado en la base de datos.'
                ], 404);
            }

            if ($userModel->Estado == 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Sus credenciales institucionales han sido desactivadas por el administrador.'
                ], 403);
            }

            $rolDoctorId = DB::table('Roles')->where('NombreRol', 'Doctor')->value('RolID');
            if (!$rolDoctorId) {
                throw new \Exception("El rol 'Doctor' no está configurado en la tabla Roles de la base de datos.");
            }

            if ($usuario->rolId == $rolDoctorId) {
                $doctor = DB::table('Doctores')->where('UsuarioID', $usuario->id)->first();
                if ($doctor && $doctor->EsVerificado == 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Tu cuenta está en proceso de validación por parte de la administración. Te notificaremos cuando sea aprobada.'
                    ], 403);
                }
            }

            $entidad = null;
            if ($userModel->EntidadID) {
                $entidad = DB::table('Entidades')->where('EntidadID', $userModel->EntidadID)->first();
            }

            $token = $userModel->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'id' => $usuario->id,
                    'email' => $usuario->email,
                    'rol_id' => $usuario->rolId,
                    'entidad_id' => $userModel->EntidadID,
                    'tipo_entidad' => $entidad ? $entidad->TipoEntidad : null
                ],
                'access_token' => $token,
                'token_type' => 'Bearer'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 401);
        }
    }

    public function registerDoctor(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'               => 'required|string|email|max:100|unique:Usuarios,Email',
                'password'            => 'required|string|min:6',
                'nombre'              => 'required|string|max:100',
                'apellido'            => 'required|string|max:100',
                'especialidad_id'     => 'required|integer',
                'numero_colegiado'    => 'required|string|unique:Doctores,NumeroColegiado',

                'fotografia'          => 'required|file|image|mimes:jpg,jpeg,png|max:2048',
                'titulo_medico'       => 'required|file|mimes:pdf,jpg,png|max:3072',
                'titulo_especialista' => 'required|file|mimes:pdf,jpg,png|max:3072',
                'constancia_colegio'  => 'required|file|mimes:pdf,jpg,png|max:2048',
                'dni'                 => 'required|file|mimes:pdf,jpg,png|max:2048',
            ]);

            $rolDoctorId = DB::table('Roles')->where('NombreRol', 'Doctor')->value('RolID') ?? 2;

            $pathFoto = $request->file('fotografia')->store('doctores/fotos', 'public');
            $pathTituloMed = $request->file('titulo_medico')->store('doctores/titulos_medicos', 'public');
            $pathTituloEsp = $request->file('titulo_especialista')->store('doctores/titulos_especialidades', 'public');
            $pathConstancia = $request->file('constancia_colegio')->store('doctores/constancias', 'public');
            $pathDni = $request->file('dni')->store('doctores/documentos_identidad', 'public');

            DB::beginTransaction();

            $passwordHash = Hash::make($validated['password']);

            DB::statement("
                INSERT INTO Usuarios (RolID, Email, PasswordHash, EntidadID, Estado)
                VALUES (?, ?, ?, NULL, 0)
            ", [$rolDoctorId, $validated['email'], $passwordHash]);

            $usuarioCreado = DB::table('Usuarios')->where('Email', $validated['email'])->first();

            DB::statement("
                INSERT INTO Doctores (
                    UsuarioID, EspecialidadID, Nombre, Apellido, NumeroColegiado,
                    RutaFoto, RutaTituloMedico, RutaTituloEspecialista, RutaConstanciaColegio, RutaDni,
                    EsVerificado, Estado
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
            ", [
                $usuarioCreado->UsuarioID,
                $validated['especialidad_id'],
                $validated['nombre'],
                $validated['apellido'],
                $validated['numero_colegiado'],
                $pathFoto,
                $pathTituloMed,
                $pathTituloEsp,
                $pathConstancia,
                $pathDni
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Solicitud de registro enviada con éxito. Un administrador auditará sus documentos para habilitar el inicio de sesión.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo procesar el registro.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'usuario_id'   => 'required|integer',
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            $this->repository->updatePassword(
                $validated['usuario_id'],
                $validated['old_password'],
                $validated['new_password']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña actualizada con éxito.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Sesión cerrada exitosamente y token eliminado.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo cerrar la sesión.'
            ], 500);
        }
    }
}
