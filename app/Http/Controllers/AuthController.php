<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Core\Auth\Application\Commands\LoginCommand;
use App\Core\Auth\Application\Handlers\LoginHandler;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginHandler $handler): JsonResponse
    {
        try {
            $command = new LoginCommand(
                $request->email,
                $request->password
            );

            $usuario = $handler->handle($command);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $usuario->id,
                    'email' => $usuario->email,
                    'rol_id' => $usuario->rolId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 401);
        }
    }
}