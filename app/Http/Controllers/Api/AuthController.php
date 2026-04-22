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
            $token = $userModel->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $usuario->id,
                    'email' => $usuario->email,
                    'rol_id' => $usuario->rolId
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