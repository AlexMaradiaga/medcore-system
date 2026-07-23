<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CryptoMiddleware
{
    private string $method = 'AES-256-CBC';

    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = config('app.encryption_key');

        if (empty($this->secretKey)) {
            throw new \RuntimeException(
                'No se encontró APP_ENCRYPTION_KEY en la configuración.'
            );
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('payload')) {

            try {
                $payload = $request->input('payload');
                $parts = explode(':', $payload);

                if (count($parts) !== 2) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Payload cifrado inválido.'
                    ], 400);
                }

                [$ivHex, $encryptedData] = $parts;
                $iv = hex2bin($ivHex);

                if ($iv === false) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vector de inicialización (IV) inválido.'
                    ], 400);
                }

                // Sanitizamos la llave para asegurar que tenga los bytes correctos
                $key = substr($this->secretKey, 0, 32);

                $decryptedString = openssl_decrypt(
                    $encryptedData,
                    $this->method,
                    $key,
                    0,
                    $iv
                );

                if ($decryptedString === false) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No fue posible descifrar el contenido.'
                    ], 400);
                }

                $decryptedData = json_decode($decryptedString, true);

                if (!is_array($decryptedData)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El contenido descifrado no es un JSON válido.'
                    ], 400);
                }

                $request->replace($decryptedData);


            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Error procesando el contenido cifrado.'
                ], 400);
            }
        }

        $response = $next($request);


        /*
        |--------------------------------------------------------------------------
        | CIFRAR RESPUESTA
        |--------------------------------------------------------------------------
        */

        if ($response instanceof JsonResponse) {

            $originalData = $response->getData(true);

            $ivLength = openssl_cipher_iv_length($this->method);

            $iv = random_bytes($ivLength);

            $encrypted = openssl_encrypt(
                json_encode($originalData),
                $this->method,
                $this->secretKey,
                0,
                $iv
            );

            if ($encrypted === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error al cifrar la respuesta.'
                ], 500);
            }

            $payload = bin2hex($iv) . ':' . $encrypted;

            $response->setData([
                'payload' => $payload
            ]);
        }

        return $response;
    }
}
