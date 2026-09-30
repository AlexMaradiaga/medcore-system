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
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

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

            $doctor = null;
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

            $tipoSuscriptor = $doctor ? 'Doctor' : ($entidad->TipoEntidad ?? null);
            $suscripcion = null;
            if ($tipoSuscriptor) {
                $suscripcionQuery = DB::table('Sistema_Suscripciones_SaaS')
                    ->where('TipoSuscriptor', $tipoSuscriptor);

                $tipoSuscriptor === 'Doctor'
                    ? $suscripcionQuery->where('UsuarioID', $usuario->id)
                    : $suscripcionQuery->where('EntidadID', $userModel->EntidadID);

                $suscripcion = $suscripcionQuery->orderByDesc('SuscripcionSaaSID')->first();
            }

            $plan = $suscripcion->PlanAsignado ?? $suscripcion->TipoPlan ?? null;

            if (!$plan) {
                $plan = 'Sin plan';
            }

            $estadoSaaS = $suscripcion->EstadoSuscripcion ?? null;

            $token = $userModel->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'id'           => $usuario->id,
                    'email'        => $usuario->email,
                    'rol_id'       => $usuario->rolId,
                    'entidad_id'   => $userModel->EntidadID,
                    'tipo_entidad' => $entidad ? $entidad->TipoEntidad : null,
                    'tipo_suscriptor' => $tipoSuscriptor,
                    'plan'         => $plan,
                    'estado_saas'  => $estadoSaaS,
                    'es_founder'  => (bool) ($userModel->EsFounder ?? false),
                    'nivel_founder' => $userModel->NivelFounder,
                ],
                'access_token' => $token,
                'token_type'   => 'Bearer'
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

            $filePaths = [
                'fotografia'          => $request->file('fotografia')->store('doctores/fotos', 'public'),
                'titulo_medico'       => $request->file('titulo_medico')->store('doctores/titulos_medicos', 'public'),
                'titulo_especialista' => $request->file('titulo_especialista')->store('doctores/titulos_especialidades', 'public'),
                'constancia_colegio'  => $request->file('constancia_colegio')->store('doctores/constancias', 'public'),
                'dni'                 => $request->file('dni')->store('doctores/documentos_identidad', 'public'),
            ];

            $this->repository->registerDoctor($validated, $filePaths);

            return response()->json([
                'status'  => 'success',
                'message' => 'Solicitud de registro enviada con éxito. Un administrador auditará sus documentos.'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Datos inválidos o duplicados (email/colegiado ya existen).',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'        => 'required|email',
                'new_password' => 'required|string|min:8',
            ]);

            $this->repository->updatePassword(
                $validated['email'],
                $validated['new_password']
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Contraseña reestablecida con éxito. Ya puedes iniciar sesión.'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error'  => $e->getMessage()
            ], 400);
        }
    }

    public function updateAuthenticatedProfile(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'email' => ['nullable', 'required_without:new_password', 'email', 'max:100'],
                'new_password' => [
                    'nullable',
                    'required_without:email',
                    'confirmed',
                    'different:current_password',
                    Password::min(8)->letters()->numbers(),
                ],
            ]);

            $resultado = $this->repository->updateAuthenticatedProfile(
                (int) $request->user()->getAuthIdentifier(),
                $validated['current_password'],
                $validated['email'] ?? null,
                $validated['new_password'] ?? null
            );

            if ($resultado['password_changed']) {
                $request->user()->tokens()->delete();
            }

            return response()->json([
                'status' => 'success',
                'message' => $resultado['password_changed']
                    ? 'Credenciales actualizadas. Por seguridad debes iniciar sesión nuevamente.'
                    : 'Correo administrativo actualizado correctamente.',
                'data' => [
                    'email' => $resultado['email'],
                    'requires_reauthentication' => $resultado['password_changed'],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Revise los datos del formulario.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
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
