<?php

namespace App\Core\Appointments\Infrastructure\Repositories;

use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class SqlAppointmentRepository implements AppointmentRepositoryInterface
{
    private function isDoctorAvailable(int $doctorId, string $fechaHora, ?int $excludeCitaId = null): bool
    {
        $inicioSolicitado = Carbon::parse($fechaHora);
        $diaSemana = $inicioSolicitado->dayOfWeekIso;

        $duracionMinutos = (int) (DB::table('Doctor_Horarios')
            ->where('DoctorID', $doctorId)
            ->where('DiaSemana', $diaSemana)
            ->where('Estado', 1)
            ->value('DuracionCitaMinutos') ?? 30);

        $finSolicitado = $inicioSolicitado->copy()->addMinutes($duracionMinutos);

        $query = DB::table('Citas')
            ->where('DoctorID', $doctorId)
            ->where('Estado', 1)
            ->whereNotIn('EstadoCita', ['Cancelada', 'Rechazada'])
            ->where(function ($q) use ($inicioSolicitado, $finSolicitado, $duracionMinutos) {
                $q->where('FechaHora', '<', $finSolicitado->toDateTimeString())
                  ->whereRaw("DATEADD(minute, ?, FechaHora) > ?", [
                      $duracionMinutos,
                      $inicioSolicitado->toDateTimeString()
                  ]);
            });

        if ($excludeCitaId) {
            $query->where('CitaID', '!=', $excludeCitaId);
        }

        return $query->count() === 0;
    }

    public function create(array $data): int
    {
        $data['doctor_id']  = (int) $data['doctor_id'];
        $data['entidad_id'] = (int) $data['entidad_id'];
        $data['edad']       = (int) $data['edad'];

        if (isset($data['paciente_id'])) {
            $data['paciente_id'] = (int) $data['paciente_id'];
        }

        if (!$this->isDoctorAvailable($data['doctor_id'], $data['fecha_hora'])) {
            throw new \Exception("El doctor ya tiene una cita agendada para esa fecha y hora.");
        }

        $usuarioId = $data['UsuarioID'] ?? $data['usuario_id'] ?? null;
        if ($usuarioId !== null) {
            $usuarioId = (int) $usuarioId;
        }
        
        return DB::transaction(function () use ($data, $usuarioId) {
            DB::statement('EXEC sp_AgendarCita ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
                $usuarioId,
                $data['doctor_id'],
                $data['entidad_id'],
                $data['fecha_hora'],
                $data['motivo'],
                $data['estado_cita'] ?? 'Pendiente',
                $data['sintomas'] ?? null,
                $data['alergias'] ?? null,
                $data['edad'] ?? null,
                $data['genero'] ?? null,
                $data['aseguradora'] ?? null,
                $data['NumeroPoliza'] ?? null,
                $data['nombre_contacto_emergencia'] ?? null,
                $data['telefono_contacto_emergencia'] ?? null,
                $data['medicamentos_actuales'] ?? null
            ]);

            $cita = DB::table('Citas')
                ->where('DoctorID', $data['doctor_id'])
                ->where('FechaHora', $data['fecha_hora'])
                ->where('Estado', 1)
                ->orderBy('CitaID', 'desc')
                ->first();

            if ($cita && isset($data['cronicas_ids']) && is_array($data['cronicas_ids'])) {
                foreach ($data['cronicas_ids'] as $enfermedadId) {
                    DB::table('CitasEnfermedades')->insert([
                        'CitaID'       => (int) $cita->CitaID,
                        'EnfermedadID' => (int) $enfermedadId
                    ]);
                }
            }

            return $cita ? (int) $cita->CitaID : 0;
        });
    }

    public function getPendingByDoctor(int $doctorId): array
    {
        return DB::select("EXEC sp_ObtenerCitasDashboardDoctor ?", [$doctorId]);
    }

    public function getHistoryByPatient(int $id): array
    {
        return DB::table('Citas as c')
            ->join('Pacientes as p', 'c.PacienteID', '=', 'p.PacienteID')
            ->leftJoin('Doctores as d', 'c.DoctorID', '=', 'd.DoctorID')
            ->leftJoin('Entidades as e', 'c.EntidadID', '=', 'e.EntidadID')
            ->leftJoin('Consultas as con', 'c.CitaID', '=', 'con.CitaID')
            ->where(function ($query) use ($id) {
                $query->where('p.PacienteID', $id)
                      ->orWhere('p.UsuarioID', $id);
            })
            ->select([
                'c.CitaID',
                'c.CitaID as Folio',
                'c.FechaHora',
                'c.EstadoCita',
                'c.EstadoCita as Estado',
                DB::raw("'General' as TipoCita"),
                'c.Motivo',
                'p.PacienteID',
                'p.Nombre as PacienteNombre',
                DB::raw("COALESCE(CONCAT(d.Nombre, ' ', d.Apellido), 'Dr. Por Asignar') as Doctor"),
                DB::raw("COALESCE(e.NombreEntidad, 'Clínica Principal') as Clinica"),
                'con.Diagnostico',
                DB::raw("NULL as Sintomas")
            ])
            ->orderBy('c.FechaHora', 'DESC')
            ->get()
            ->toArray();
    }

    public function reschedule(int $citaId, string $nuevaFechaHora): bool
    {
        $cita = DB::table('Citas')->where('CitaID', $citaId)->first();

        if (!$cita) {
            throw new \Exception("No se encontró la cita con ID: $citaId");
        }

        if (!$this->isDoctorAvailable((int) $cita->DoctorID, $nuevaFechaHora, $citaId)) {
            throw new \Exception("El doctor no está disponible en el nuevo horario seleccionado.");
        }

        DB::table('Citas')
            ->where('CitaID', $citaId)
            ->update(['FechaHora' => $nuevaFechaHora]);

        return true;
    }

    public function cancel(int $citaId, ?string $motivoCancelacion = null): bool
    {
        try {
            return DB::table('Citas')
                ->where('CitaID', $citaId)
                ->update([
                    'EstadoCita' => 'Cancelada',
                    'Motivo' => $motivoCancelacion
                        ? DB::raw("ISNULL(Motivo, '') + ' (Cancelado: $motivoCancelacion)'")
                        : 'Cancelada por el paciente'
                ]);
        } catch (\Exception $e) {
            throw new \Exception("Error al cancelar en base de datos: " . $e->getMessage());
        }
    }

    public function getDoctorAgenda(int $doctorId): array
    {
        return DB::table('vw_ReporteCitasLogistica')
            ->where('DoctorID', $doctorId)
            ->where('EstadoCita', 'Pendiente')
            ->orderBy('FechaHora', 'asc')
            ->get()
            ->toArray();
    }

    public function getDetailedReport(array $filters): array
    {
        $query = DB::table('vw_ReporteCitasLogistica');

        if (isset($filters['doctor_id'])) {
            $query->where('DoctorID', $filters['doctor_id']);
        }

        if (isset($filters['fecha_inicio']) && isset($filters['fecha_fin'])) {
            $query->whereBetween('FechaHora', [
                $filters['fecha_inicio'] . ' 00:00:00',
                $filters['fecha_fin'] . ' 23:59:59'
            ]);
        }

        if (isset($filters['estado'])) {
            $query->where('EstadoCita', $filters['estado']);
        }

        return $query->get()->toArray();
    }

    public function getStats(): array
    {
        $result = DB::select("SELECT
            (SELECT COUNT(*) FROM Pacientes WHERE Estado = 1) as total_pacientes,
            (SELECT COUNT(*) FROM Doctores WHERE Estado = 1) as total_doctores,
            (SELECT COUNT(*) FROM Citas WHERE EstadoCita = 'Pendiente') as citas_pendientes");

        return (array) $result[0];
    }

    public function getExams(int $id): array
    {
        return DB::select("
            SELECT
                CES.ExamenSistemaID,
                CES.ExamenSistemaID as ExamenID,
                C.CitaID,
                C.FechaHora,
                CES.SistemaID,
                CES.EsNormal,
                CES.NotasAdicionales,
                D.Nombre + ' ' + D.Apellido as Doctor
            FROM consulta_examen_sistemas CES
            INNER JOIN Consultas CON ON CES.ConsultaID = CON.ConsultaID
            INNER JOIN Citas C ON CON.CitaID = C.CitaID
            INNER JOIN Pacientes P ON C.PacienteID = P.PacienteID
            INNER JOIN Doctores D ON C.DoctorID = D.DoctorID
            WHERE (P.UsuarioID = ? OR P.PacienteID = ?)
            ORDER BY C.FechaHora DESC
        ", [$id, $id]);
    }

    public function getPrescriptions(int $id): array
    {
        return DB::select("
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
            INNER JOIN Consultas CON ON R.ConsultaID = CON.ConsultaID
            INNER JOIN Citas C ON CON.CitaID = C.CitaID
            INNER JOIN Pacientes P ON C.PacienteID = P.PacienteID
            INNER JOIN Doctores D ON C.DoctorID = D.DoctorID
            WHERE (P.UsuarioID = ? OR P.PacienteID = ?)
            ORDER BY R.FechaEmision DESC
        ", [$id, $id]);
    }

    public function descargarReceta($recetaId)
    {
        if (!$recetaId || $recetaId === 'undefined') {
            throw new \InvalidArgumentException('El folio de la receta proporcionado no es válido');
        }

        $recetaInfo = DB::selectOne("
            SELECT ConsultaID, CodigoCanje
            FROM Recetas
            WHERE RecetaID = ?
        ", [$recetaId]);

        if (!$recetaInfo) {
            throw new \Exception('Receta no encontrada');
        }

        $datos = DB::selectOne("
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
        ", [$recetaInfo->ConsultaID]);

        $medicamentos = DB::select("
            SELECT NombreMedicamento, Dosis, Indicaciones
            FROM Recetas
            WHERE ConsultaID = ?
        ", [$recetaInfo->ConsultaID]);

        $textoMedicamentos = "";
        foreach ($medicamentos as $m) {
            $textoMedicamentos .= "• " . $m->NombreMedicamento . " | Dosis: " . $m->Dosis . " | Indicaciones: " . $m->Indicaciones . "\n";
        }

        $datos->DetalleMedicamentos = $textoMedicamentos;
        $datos->CodigoCanje = $recetaInfo->CodigoCanje ?? "REC-{$recetaId}";

        return Pdf::loadView('pdf.receta', ['data' => $datos]);
    }

    public function getDoctorStats(int $usuarioId): array
    {
        $doctor = DB::table('Doctores')->where('UsuarioID', $usuarioId)->first();

        if (!$doctor) return ['citas_hoy' => 0, 'atendidos' => 0, 'pendientes' => 0];

        $hoy = date('Y-m-d');

        return [
            'citas_hoy' => DB::table('Citas')
                ->where('DoctorID', $doctor->DoctorID)
                ->whereDate('FechaHora', $hoy)
                ->where('Estado', 1)
                ->count(),
            'atendidos' => DB::table('Citas')
                ->where('DoctorID', $doctor->DoctorID)
                ->where('EstadoCita', 'Completada')
                ->count(),
            'pendientes' => DB::table('Citas')
                ->where('DoctorID', $doctor->DoctorID)
                ->where('EstadoCita', 'Pendiente')
                ->where('Estado', 1)
                ->count(),
        ];
    }

    public function getAppointmentsByDoctorUser(int $usuarioId): array
    {
        return DB::select("
            SELECT
                C.CitaID,
                C.FechaHora,
                C.Motivo,
                C.Sintomas,
                P.Nombre + ' ' + P.Apellido as Paciente,
                P.Edad,
                P.Genero,
                P.TipoSangre,
                P.Telefono,
                ISNULL(U_Pac.Email, 'Sin correo') as EmailPaciente,
                C.Alergias,
                C.MedicamentosActuales,
                (
                    SELECT STRING_AGG(E.NombreEnfermedad, ', ')
                    FROM CitasEnfermedades CE
                    INNER JOIN EnfermedadesCronicas E ON CE.EnfermedadID = E.EnfermedadID
                    WHERE CE.CitaID = C.CitaID
                ) AS EnfermedadesCronicas,
                U_Doc.Email as EmailDoctor,
                C.EstadoCita,
                P.Aseguradora,
                P.NumeroPoliza,
                P.NombreContactoEmergencia AS NombreContactoEmergencia,
                P.TelefonoContactoEmergencia AS TelefonoContactoEmergencia,
                P.NombreContactoEmergencia AS nombre_contacto_emergencia,
                P.TelefonoContactoEmergencia AS telefono_contacto_emergencia
            FROM Citas C
            INNER JOIN Pacientes P ON C.PacienteID = P.PacienteID
            LEFT JOIN Usuarios U_Pac ON P.UsuarioID = U_Pac.UsuarioID
            INNER JOIN Doctores D ON C.DoctorID = D.DoctorID
            INNER JOIN Usuarios U_Doc ON D.UsuarioID = U_Doc.UsuarioID
            WHERE D.UsuarioID = ?
            AND C.EstadoCita IN ('Pendiente', 'Confirmada', 'Completada')
            AND C.Estado = 1
            ORDER BY C.FechaHora ASC
        ", [$usuarioId]);
    }

    public function approve(int $citaId): bool
    {
        return DB::table('Citas')
            ->where('CitaID', $citaId)
            ->update(['EstadoCita' => 'Confirmada']);
    }

    public function getCatalogoExamenFisico(): array
    {
        $resultado = DB::select("EXEC sp_ObtenerCatalogoExamenFisico");

        return array_map(function($item) {
            return [
                'SistemaID' => $item->SistemaID,
                'NombreSistema' => $item->NombreSistema,
                'Hallazgos' => json_decode($item->Hallazgos, true) ?? []
            ];
        }, $resultado);
    }
}
