<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentRepositoryInterface $repository
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'doctor_id'                    => 'required|integer',
                'entidad_id'                   => 'required|integer',
                'fecha_hora'                   => 'required|date_format:Y-m-d H:i:s',
                'motivo'                       => 'required|string|max:255',
                'sintomas'                     => 'nullable|string',
                'edad'                         => 'required|integer',
                'genero'                       => 'required|string|max:1',
                'telefono'                     => 'nullable|string',
                'alergias'                     => 'nullable|string',
                'aseguradora'                  => 'nullable|string',
                'numero_poliza'                => 'nullable|string',
                'nombre_contacto_emergencia'   => 'nullable|string',
                'telefono_contacto_emergencia' => 'nullable|string',
                'medicamentos_actuales'        => 'nullable|string',
                'cronicas_ids'                 => 'nullable|array',
                'cronicas_ids.*'               => 'integer',
                'UsuarioID'                    => 'nullable|integer',
                'usuario_id'                   => 'nullable|integer',
                'paciente_id'                  => 'nullable|integer'
            ]);

            // Asignación de UsuarioID de la sesión si no se envía explícitamente
            $validated['UsuarioID'] = $validated['UsuarioID']
                ?? $validated['usuario_id']
                ?? $request->user()?->UsuarioID
                ?? $request->user()?->id;

            $this->repository->create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cita agendada correctamente en MedGo+'
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'line'    => $e->getLine()
            ], 400);
        }
    }

    public function getByDoctor($doctorId): JsonResponse
    {
        return response()->json($this->repository->getPendingByDoctor((int)$doctorId));
    }

    public function reschedule(Request $request, $id): JsonResponse
    {
        $request->validate(['fecha_hora' => 'required|date_format:Y-m-d H:i:s']);

        $this->repository->reschedule((int)$id, $request->fecha_hora);

        return response()->json(['status' => 'success', 'message' => 'Cita reprogramada']);
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->cancel((int)$id, "Cancelada desde el portal");
            return response()->json([
                'status' => 'success',
                'message' => 'Cita cancelada correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo cancelar la cita: ' . $e->getMessage()
            ], 400);
        }
    }



    public function getHistoryByPatient($id): \Illuminate\Http\JsonResponse
    {
        try {
            $paramId = (int) $id;

            $citas = \Illuminate\Support\Facades\DB::table('Citas as c')
                ->join('Pacientes as p', 'c.PacienteID', '=', 'p.PacienteID')
                ->leftJoin('Doctores as d', 'c.DoctorID', '=', 'd.DoctorID')
                ->leftJoin('Entidades as e', 'c.EntidadID', '=', 'e.EntidadID')
                ->leftJoin('Consultas as con', 'c.CitaID', '=', 'con.CitaID')
                ->where(function ($query) use ($paramId) {
                    $query->where('p.PacienteID', $paramId)
                        ->orWhere('p.UsuarioID', $paramId);
                })
                ->select([
                    'c.CitaID',
                    'c.CitaID as Folio',
                    'c.FechaHora',
                    'c.EstadoCita',
                    'c.EstadoCita as Estado',
                    \Illuminate\Support\Facades\DB::raw("'General' as TipoCita"),
                    'c.Motivo',
                    'p.PacienteID',
                    'p.Nombre as PacienteNombre',
                    \Illuminate\Support\Facades\DB::raw("COALESCE(CONCAT(d.Nombre, ' ', d.Apellido), 'Dr. Por Asignar') as Doctor"),
                    \Illuminate\Support\Facades\DB::raw("COALESCE(e.NombreEntidad, 'Clínica Principal') as Clinica"),
                    'con.Diagnostico',
                    // 👇 Evita el choque si 'Notas' no existe en 'Consultas'
                    \Illuminate\Support\Facades\DB::raw("NULL as Sintomas")
                ])
                ->orderBy('c.FechaHora', 'DESC')
                ->get();

            return response()->json($citas, 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }


    public function getPrescriptionsByPatient($usuarioId): JsonResponse
    {
        try {
            $recetas = $this->repository->getPrescriptions((int)$usuarioId);
            return response()->json($recetas, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getExamsByPatient($usuarioId): \Illuminate\Http\JsonResponse
    {
        try {
            $examenSistemas = $this->repository->getExams((int)$usuarioId);
            return response()->json($examenSistemas, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener examen físico: ' . $e->getMessage()
            ], 500);
        }
    }

    public function descargarReceta($recetaId): \Illuminate\Http\Response | JsonResponse
    {
        try {
            if (!$recetaId || $recetaId === 'undefined') {
                return response()->json(['error' => 'El folio de la receta proporcionado no es válido'], 400);
            }

            $consulta = \Illuminate\Support\Facades\DB::selectOne("SELECT ConsultaID FROM Recetas WHERE RecetaID = ?", [$recetaId]);

            if (!$consulta) {
                return response()->json(['error' => 'Receta no encontrada'], 404);
            }

            $datos = \Illuminate\Support\Facades\DB::selectOne("
                SELECT
                    CON.ConsultaID as RecetaID,
                    C.FechaHora,
                    D.Nombre + ' ' + D.Apellido as Doctor,
                    ESP.NombreEspecialidad as Especialidad,
                    P.Nombre + ' ' + P.Apellido as Paciente,
                    P.Edad
                FROM Consultas CON
                JOIN Citas C ON CON.CitaID = C.CitaID
                JOIN Doctores D ON C.DoctorID = D.DoctorID
                JOIN Especialidades ESP ON D.EspecialidadID = ESP.EspecialidadID
                JOIN Pacientes P ON C.PacienteID = P.PacienteID
                WHERE CON.ConsultaID = ?
            ", [$consulta->ConsultaID]);

            $medicamentos = \Illuminate\Support\Facades\DB::select("
                SELECT NombreMedicamento, Dosis, Indicaciones
                FROM Recetas
                WHERE ConsultaID = ?
            ", [$consulta->ConsultaID]);

            $textoMedicamentos = "";
            foreach ($medicamentos as $m) {
                $textoMedicamentos .= "• " . $m->NombreMedicamento . " | Dosis: " . $m->Dosis . " | Indicaciones: " . $m->Indicaciones . "\n";
            }

            $datos->DetalleMedicamentos = $textoMedicamentos;

            $pdf = Pdf::loadView('pdf.receta', ['data' => $datos]);

            return $pdf->download("Receta_{$recetaId}.pdf", [
                'Content-Type' => 'application/pdf',
                'Access-Control-Expose-Headers' => 'Content-Disposition'
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDoctorStats($usuarioId): JsonResponse
    {
        try {
            $stats = $this->repository->getDoctorStats((int)$usuarioId);
            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getAppointmentsByDoctorUser($usuarioId): JsonResponse
    {
        try {
            $appointments = $this->repository->getAppointmentsByDoctorUser((int)$usuarioId);
            return response()->json($appointments);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function approve($id): JsonResponse
    {
        try {
            $this->repository->approve((int)$id);
            return response()->json(['status' => 'success', 'message' => 'Cita aprobada']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $request, $id): JsonResponse
    {
        try {
            $motivo = $request->input('motivo', 'Rechazada por el médico');
            $this->repository->cancel((int)$id, $motivo);

            return response()->json(['status' => 'success', 'message' => 'Cita rechazada']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function getCatalogoExamenFisico(): JsonResponse
    {
        try {
            $catalogo = $this->repository->getCatalogoExamenFisico();
            return response()->json($catalogo);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
