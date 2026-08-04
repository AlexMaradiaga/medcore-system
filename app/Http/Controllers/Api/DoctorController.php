<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Core\Doctors\Application\Handlers\RegisterDoctorHandler;
use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;

class DoctorController extends Controller
{
    private $handler;
    private $repository;

    public function __construct(
        RegisterDoctorHandler $handler,
        DoctorRepositoryInterface $repository
    ) {
        $this->handler = $handler;
        $this->repository = $repository;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'             => 'required|email',
                'password'          => 'required|string|min:6',
                'dni'               => 'required|string',
                'nombre'            => 'required|string',
                'apellido'          => 'required|string',
                'telefono'          => 'nullable|string',
                'especialidad_id'   => 'required|integer',
                'entidad_id'        => 'required|integer',
                'numero_colegiado'  => 'required|string',
                'ruta_documento'    => 'nullable|string'
            ]);

            $this->handler->execute($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Doctor registrado con éxito y pendiente de verificación'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search'       => $request->query('search'),
            'especialidad' => $request->query('especialidad'),
        ];

        return response()->json($this->repository->getAllActive($filters));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'            => 'required|email',
                'nombre'           => 'required|string',
                'apellido'         => 'required|string',
                'especialidad_id'  => 'required|integer',
                'entidad_id'       => 'required|integer',
                'numero_colegiado' => 'required|string'
            ]);

            $this->repository->update((int)$id, $validated);
            return response()->json(['status' => 'success', 'message' => 'Perfil de doctor actualizado']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete((int)$id);
            return response()->json(['status' => 'success', 'message' => 'Doctor desactivado (Baja lógica)']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function getByClinic($entidadId): JsonResponse
    {
        try {
            $doctores = \Illuminate\Support\Facades\DB::table('Doctores as D')
                ->join('Usuarios as U', 'D.UsuarioID', '=', 'U.UsuarioID')
                ->join('Especialidades as E', 'D.EspecialidadID', '=', 'E.EspecialidadID')
                ->leftJoin('Servicios_Medicos as SM', function($join) {
                    $join->on('D.DoctorID', '=', 'SM.DoctorID')
                         ->where('SM.NombreServicio', 'like', '%Consulta%');
                })
                ->select(
                    'D.DoctorID',
                    'D.Nombre',
                    'D.Apellido',
                    'E.NombreEspecialidad as Especialidad',
                    'U.EntidadID',
                    'D.RutaFoto as Foto',
                    'D.EsVerificado',
                    'D.Estado',
                    'D.Nacionalidad',
                    'D.HablaIngles',
                    'D.OtrosIdiomas',
                    'D.DisponibleDomicilio',
                    'D.Latitud',
                    'D.Longitud',
                    'D.DireccionConsultorio',
                    \Illuminate\Support\Facades\DB::raw('ISNULL(MAX(SM.Precio), 90) as CostoConsulta')
                )
                ->where('U.EntidadID', $entidadId)
                ->where('D.Estado', 1)
                ->groupBy(
                    'D.DoctorID', 'D.Nombre', 'D.Apellido', 'E.NombreEspecialidad',
                    'U.EntidadID', 'D.RutaFoto', 'D.EsVerificado', 'D.Estado',
                    'D.Nacionalidad', 'D.HablaIngles', 'D.OtrosIdiomas',
                    'D.DisponibleDomicilio', 'D.Latitud', 'D.Longitud', 'D.DireccionConsultorio'
                )
                ->get();

            return response()->json($doctores, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener doctores de la clínica: ' . $e->getMessage()
            ], 500);
        }
    }
    public function guardarUbicacionConsultorio(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doctor_id'            => 'required|integer',
            'latitud'              => 'required|numeric',
            'longitud'             => 'required|numeric',
            'direccion_consultorio' => 'required|string|max:255',
            'habla_ingles'         => 'nullable|boolean',
            'disponible_domicilio' => 'nullable|boolean',
        ]);

        DB::table('Doctores')
            ->where('DoctorID', $validated['doctor_id'])
            ->update([
                'Latitud'              => $validated['latitud'],
                'Longitud'             => $validated['longitud'],
                'DireccionConsultorio' => $validated['direccion_consultorio'],
                'HablaIngles'          => $validated['habla_ingles'] ?? 0,
                'DisponibleDomicilio'  => $validated['disponible_domicilio'] ?? 0,
            ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Ubicación y configuración del consultorio actualizadas correctamente.'
        ]);
    }
}
