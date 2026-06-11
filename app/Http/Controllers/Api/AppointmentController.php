<?php

namespace App\Http\Controllers\Api;

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
                'UsuarioID' => 'required|integer',
                'doctor_id'   => 'required|integer',
                'entidad_id'  => 'required|integer',
                'fecha_hora'  => 'required|date_format:Y-m-d H:i:s',
                'motivo'      => 'required|string|max:255',
                'sintomas'    => 'nullable|string',
                'alergias'    => 'nullable|string',
                'edad'        => 'required|integer',
                'genero'      => 'required|string|max:1',
                'medicamentos_actuales' => 'nullable|string',
                'aseguradora' => 'nullable|string|max:100',
                'numero_poliza' => 'nullable|string|max:50',
                'nombre_contacto_emergencia' => 'nullable|string|max:100',
                'telefono_contacto_emergencia' => 'nullable|string|max:20',
            ]);

            $this->repository->create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Cita agendada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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



    public function getHistoryByPatient($usuarioId): JsonResponse
    {
        try {
            $history = $this->repository->getHistoryByPatient((int)$usuarioId);

            return response()->json($history);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function getPrescriptionsByPatient($usuarioId): JsonResponse
    {
        try {
            $recetas = \Illuminate\Support\Facades\DB::select("
                SELECT
                    R.RecetaID,
                    R.ConsultaID,
                    R.CodigoCanje,
                    R.NombreMedicamento,
                    R.Dosis,
                    R.Indicaciones,
                    R.YaCanjeada,
                    R.FechaEmision,
                    D.Nombre + ' ' + D.Apellido as Doctor
                FROM Recetas R
                JOIN Consultas CON ON R.ConsultaID = CON.ConsultaID
                JOIN Citas C ON CON.CitaID = C.CitaID
                JOIN Doctores D ON C.DoctorID = D.DoctorID
                WHERE C.PacienteID = (SELECT PacienteID FROM Pacientes WHERE UsuarioID = ?)
                ORDER BY R.FechaEmision DESC
            ", [$usuarioId]);

            return response()->json($recetas, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
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

    public function getExamsByPatient($usuarioId): \Illuminate\Http\JsonResponse
    {
        try {
            $examenSistemas = \Illuminate\Support\Facades\DB::select("
                SELECT
                    CES.ExamenSistemaID,
                    C.CitaID,
                    CON.ConsultaID,
                    C.FechaHora,
                    D.Nombre + ' ' + D.Apellido as Doctor,
                    CES.SistemaID,
                    CES.EsNormal,
                    CES.NotasAdicionales
                FROM consulta_examen_sistemas CES
                JOIN Consultas CON ON CES.ConsultaID = CON.ConsultaID
                JOIN Citas C ON CON.CitaID = C.CitaID
                JOIN Doctores D ON C.DoctorID = D.DoctorID
                WHERE C.PacienteID = (SELECT PacienteID FROM Pacientes WHERE UsuarioID = ?)
                ORDER BY C.FechaHora DESC
            ", [$usuarioId]);

            return response()->json($examenSistemas, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener examen físico: ' . $e->getMessage()
            ], 500);
        }
    }
}
