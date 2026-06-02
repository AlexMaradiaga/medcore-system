<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;

class HistoryController extends Controller
{
    public function __construct(
        private DoctorRepositoryInterface $repository
    ) {}

    public function obtenerHistorialCompleto(Request $request, $pacienteId): JsonResponse
    {
        try {
            $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

            if (!$doctorId) {
                return response()->json(['estado' => 'error', 'mensaje' => 'Perfil de médico no encontrado.'], 403);
            }

            $pdo = DB::getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerHistorialClinico ?, ?");
            $stmt->execute([$pacienteId, $doctorId]);

            $consultas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $examenes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $comparativos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $pacienteBasal = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $stmt->nextRowset();
            $metaAutorizacion = $stmt->fetch(\PDO::FETCH_ASSOC);
            $autorizacionGlobal = $metaAutorizacion ? (bool)$metaAutorizacion['AutorizacionGlobal'] : false;

            return response()->json([
                'estado' => 'success',
                'autorizacionGlobal' => $autorizacionGlobal,
                'datos' => [
                    'paciente'     => $pacienteBasal,
                    'consultas'    => $consultas,
                    'examenes'     => $examenes,
                    'comparativos' => $comparativos
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 400);
        }
    }

    public function concederAutorizacionGlobal(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'paciente_id' => 'required|integer',
                'pin_autorizacion' => 'required|string'
            ]);

            // 1. Validación del PIN (etapa actual de pruebas locales)
            if ($request->pin_autorizacion !== '2026') {
                return response()->json([
                    'estado' => 'error',
                    'mensaje' => 'Código de autorización inválido. El paciente debe dictar el PIN correcto.'
                ], 422);
            }

            $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

            if (!$doctorId) {
                return response()->json([
                    'estado' => 'error',
                    'mensaje' => 'Acceso denegado: El usuario autenticado no está registrado como médico.'
                ], 403);
            }

            DB::table('Paciente_Autorizacion_Medico')->updateOrInsert(
                [
                    'PacienteID' => $request->paciente_id,
                    'DoctorID'   => $doctorId,
                ],
                [
                    'FechaAutorizacion' => now(),
                    'FechaExpiracion'   => now()->addYear(),
                    'Estado'            => 1
                ]
            );

            return response()->json([
                'estado' => 'success',
                'mensaje' => '¡Autorización clínica global concedida por el paciente con éxito!'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Los datos enviados no son válidos.',
                'errores' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Error interno en el servidor clínico.',
                'detalle' => $e->getMessage()
            ], 500);
        }
    }

    public function obtenerMisPacientesAtendidos(Request $request): JsonResponse
    {
        try {
            $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');
            if (!$doctorId) {
                return response()->json(['estado' => 'error', 'mensaje' => 'Perfil de médico no encontrado.'], 403);
            }
            $misPacientes = $this->repository->obtenerMisPacientesAtendidos($doctorId);
            return response()->json(['estado' => 'success', 'datos' => $misPacientes]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function obtenerDetalleConsulta(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'consulta_id' => 'required|integer'
            ]);

            $doctorId = DB::table('Doctores')->where('UsuarioID', auth()->id())->value('DoctorID');

            if (!$doctorId) {
                return response()->json(['estado' => 'error', 'mensaje' => 'Médico no autorizado.'], 403);
            }

            $pdo = DB::connection()->getPdo();
            $stmt = $pdo->prepare("EXEC sp_ObtenerDetalleConsultaModal :consulta_id, :doctor_id");
            $stmt->execute([
                'consulta_id' => $request->consulta_id,
                'doctor_id'   => $doctorId
            ]);

            $datosGenerales = $stmt->fetch(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $examenFisico = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->nextRowset();
            $hallazgos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->closeCursor();

            if (!$datosGenerales) {
                return response()->json(['estado' => 'error', 'mensaje' => 'No se encontró la consulta.'], 404);
            }


            $hallazgosPorSistema = [];
            foreach ($hallazgos as $h) {
                $sisId = (int) ($h['examenSistemaID'] ?? $h['ExamenSistemaID'] ?? 0);
                if ($sisId > 0) {
                    $hallazgosPorSistema[$sisId][] = [
                        'hallazgo'       => $h['hallazgo'] ?? $h['Hallazgo'] ?? '',
                        'estadoHallazgo' => $h['estadoHallazgo'] ?? $h['EstadoHallazgo'] ?? ''
                    ];
                }
            }

            $examenFisicoFormateado = [];
            foreach ($examenFisico as $item) {
                $id = (int) ($item['examenSistemaID'] ?? $item['ExamenSistemaID'] ?? 0);
                $examenFisicoFormateado[] = [
                    'examenSistemaID' => $id,
                    'sistema'         => $item['sistema'] ?? $item['Sistema'] ?? '',
                    'condicion'       => $item['condicion'] ?? $item['Condicion'] ?? 'Normal',
                    'detalle'         => $item['detalle'] ?? $item['Detalle'] ?? '',
                    'hallazgos'       => $hallazgosPorSistema[$id] ?? []
                ];
            }

            return response()->json([
                'estado' => 'success',
                'datos'  => [
                    'consultaID'              => (int) $datosGenerales['ConsultaID'],
                    'citaID'                  => (int) $datosGenerales['CitaID'],
                    'diagnostico'             => $datosGenerales['Diagnostico'],
                    'notasEvolucionSubjetiva' => $datosGenerales['NotasMedicas'] ?? '',
                    'estado'                  => $datosGenerales['Estado'],
                    'examenFisico'            => $examenFisicoFormateado
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function obtenerRecetaPorConsulta(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'consulta_id' => 'required|integer'
            ]);

            $consultaId = $request->consulta_id;

            $medicamentos = DB::table('Recetas')
                ->where('ConsultaID', $consultaId)
                ->where('Estado', 1)
                ->select('NombreMedicamento', 'Dosis', 'Indicaciones', 'CodigoCanje')
                ->get();

            return response()->json([
                'estado' => 'success',
                'datos'  => $medicamentos->map(function($item) {
                    return [
                        'NombreMedicamento' => $item->NombreMedicamento,
                        'Dosis'             => $item->Dosis,
                        'Indicaciones'      => $item->Indicaciones,
                        'CodigoCanje'       => $item->CodigoCanje
                    ];
                })->toArray()
            ]);

        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

     public function complete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cita_id'                      => 'required|integer',
                'diagnostico'                  => 'required|string',
                'notas_medicas'                => 'nullable|string',
                'signos_vitales'               => 'required|array',
                'examen_fisico_opciones'       => 'required|array',
                'examen_fisico_notas'          => 'nullable|array',

                'detalle_medicamentos'         => 'required|array',
                'detalle_medicamentos.*.NombreMedicamento' => 'required|string',
                'detalle_medicamentos.*.Dosis'             => 'required|string',
                'detalle_medicamentos.*.Indicaciones'      => 'required|string',
            ]);

            $this->repository->complete($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Consulta finalizada y receta generada con éxito'
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['status' => 'error', 'errors' => $ve->errors()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error Nativo de BD: ' . $e->getMessage()
            ], 400);
        }
    }

    public function buscarDiagnosticosCIE11(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        try {
            $query = $request->query('q');
            if (!$query || strlen(trim($query)) < 3) {
                return response()->json(['estado' => 'success', 'datos' => []]);
            }

            $searchTerm = trim(preg_replace('/\s+/', ' ', $query));

            $token = Cache::remember('icd_oauth_token', now()->addMinutes(50), function () {
                $authResponse = Http::withoutVerifying()
                    ->asForm()
                    ->post('https://icdaccessmanagement.who.int/connect/token', [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => env('ICD_CLIENT_ID'),
                        'client_secret' => env('ICD_CLIENT_SECRET'),
                        'scope'         => 'icdapi_access',
                    ]);

                if ($authResponse->failed()) {
                    return null;
                }

                return $authResponse->json()['access_token'] ?? null;
            });

            if (!$token) {
                return response()->json(['estado' => 'error', 'mensaje' => 'Fallo de autenticación con el servidor ICD-API.'], 401);
            }

            $searchResponse = Http::withoutVerifying()
                ->withHeaders([
                    'Authorization'   => 'Bearer ' . $token,
                    'Accept'          => 'application/json',
                    'Accept-Language' => 'es',
                    'API-Version'     => 'v2',
                ])->get('https://id.who.int/icd/release/11/2024-01/mms/search', [
                    'q'              => $searchTerm,
                    'useFlexisearch' => 'true',
                    'flatResults'    => 'true',
                ]);

            if ($searchResponse->failed()) {
                return response()->json(['estado' => 'success', 'datos' => []]);
            }

            $resultados = $searchResponse->json();
            $diagnosticosFormateados = [];

            if (!empty($resultados['destinationEntities'])) {
                foreach ($resultados['destinationEntities'] as $entity) {
                    if (!empty($entity['title']) && !empty($entity['id'])) {

                        $urlParts = explode('/', rtrim($entity['id'], '/'));
                        $codigoExtraido = end($urlParts);

                        if (($codigoExtraido === 'unspecified' || $codigoExtraido === 'other') && count($urlParts) > 1) {
                            $codigoExtraido = $urlParts[count($urlParts) - 2];
                        }

                        $codigoFinal = !empty($entity['theCode']) ? $entity['theCode'] : $codigoExtraido;

                        $diagnosticosFormateados[] = [
                            'codigo'      => $codigoFinal,
                            'descripcion' => strip_tags($entity['title']), // Limpia los <em>Infección</em>
                        ];
                    }
                }
            }

            return response()->json([
                'estado' => 'success',
                'datos'  => $diagnosticosFormateados
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'estado'  => 'error',
                'mensaje' => 'Excepción en el servicio proxy del catálogo CIE-11.',
                'detalle' => $e->getMessage()
            ], 500);
        }
    }
}
