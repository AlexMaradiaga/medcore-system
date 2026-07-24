<?php

namespace App\Core\Appointments\Infrastructure\Repositories;

use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SqlAppointmentRepository implements AppointmentRepositoryInterface
{
    private function isDoctorAvailable(int $doctorId, string $fechaHora, ?int $excludeCitaId = null): bool
    {
        $query = DB::table('Citas')
            ->where('DoctorID', $doctorId)
            ->where('FechaHora', $fechaHora)
            ->where('EstadoCita', '!=', 'Cancelada')
            ->where('Estado', 1);

        if ($excludeCitaId) {
            $query->where('CitaID', '!=', $excludeCitaId);
        }

        return $query->count() === 0;
    }

    public function create(array $data): bool {
        if (!$this->isDoctorAvailable((int)$data['doctor_id'], $data['fecha_hora'])) {
            throw new \Exception("El doctor ya tiene una cita agendada para esa fecha y hora.");
        }

        return DB::transaction(function () use ($data) {
            DB::statement('EXEC sp_AgendarCita ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
                $data['UsuarioID'],
                $data['doctor_id'],
                $data['entidad_id'],
                $data['fecha_hora'],
                $data['motivo'],
                $data['estado_cita'] ?? 'Pendiente',
                $data['sintomas'] ?? null,
                $data['alergias'] ?? null,
                $data['edad'],
                $data['genero'],
                $data['aseguradora'] ?? null,
                $data['numero_poliza'] ?? null,
                $data['nombre_contacto_emergencia'],
                $data['telefono_contacto_emergencia'],
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
                        'CitaID'       => (int)$cita->CitaID,
                        'EnfermedadID' => (int)$enfermedadId
                    ]);
                }
            }

            return true;
        });
    }

    public function getPendingByDoctor(int $doctorId): array {
       return DB::select("EXEC sp_ObtenerCitasDashboardDoctor ?", [$doctorId]);
    }

    public function getHistoryByPatient(int $usuarioId): array
    {
        return DB::select("
            SELECT
                C.CitaID,
                C.FechaHora,
                C.EstadoCita,
                C.Motivo,
                C.Sintomas,
                C.Alergias,
                C.MedicamentosActuales,
                D.Nombre + ' ' + D.Apellido as Doctor,
                E.NombreEntidad as Clinica,
                P.Edad,
                P.Genero,
                C.EstadoCita
            FROM Citas C
            JOIN Pacientes P ON C.PacienteID = P.PacienteID
            JOIN Doctores D ON C.DoctorID = D.DoctorID
            JOIN Entidades E ON C.EntidadID = E.EntidadID
            WHERE P.UsuarioID = ? AND C.Estado = 1
            ORDER BY C.FechaHora DESC
        ", [$usuarioId]);
    }

    public function reschedule(int $citaId, string $nuevaFechaHora): bool
    {
        $cita = DB::table('Citas')->where('CitaID', $citaId)->first();

        if (!$cita) {
            throw new \Exception("No se encontró la cita con ID: $citaId");
        }

        if (!$this->isDoctorAvailable((int)$cita->DoctorID, $nuevaFechaHora, $citaId)) {
            throw new \Exception("El doctor no está disponible en el nuevo horario seleccionado.");
        }

        DB::table('Citas')
            ->where('CitaID', $citaId)
            ->update(['FechaHora' => $nuevaFechaHora]);

        return true;
    }

    public function cancel(int $citaId, string $motivoCancelacion = null): bool
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

    public function complete(array $data): bool
    {
        $data = json_decode(json_encode($data), true);
        \Log::info('Procesando finalización de consulta masiva por SP:', ['cita_id' => $data['cita_id'] ?? 'N/A']);

        if (!isset($data['signos_vitales']) || !isset($data['examen_fisico_opciones'])) {
            throw new \Exception("La estructura de datos de la consulta está incompleta.");
        }

        $cita = DB::table('Citas')->where('CitaID', $data['cita_id'])->first();
        if (!$cita) {
            throw new \Exception("La cita ID: {$data['cita_id']} no existe.");
        }

        if ($cita->EstadoCita === 'Completada') {
            \Log::warning('La cita ID ' . $data['cita_id'] . ' ya había sido completada previamente. Omitiendo re-ejecución.');
            return true;
        }

        $estadosPermitidos = ['Confirmada', 'confirmada', 'CONFIRMADA'];
        if (!in_array($cita->EstadoCita, $estadosPermitidos)) {
            throw new \Exception("La cita debe estar 'Confirmada' para finalizarla. Estado actual: {$cita->EstadoCita}");
        }

        $payloadJsonStr = json_encode($data);

        DB::statement("EXEC sp_FinalizarConsulta ?, ?, ?, ?, ?", [
            $data['cita_id'],
            $data['diagnostico'],
            $data['notas_medicas'] ?? null,
            $payloadJsonStr,
            'Completada'
        ]);

        return true;
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

    public function getMedicalHistory(int $usuarioId): array {
        return DB::select("
            SELECT
                CON.ConsultaID, C.FechaHora, D.Nombre + ' ' + D.Apellido as Doctor,
                E.NombreEntidad as Clinica, CON.Diagnostico, CON.NotasMedicas,
                ESP.NombreEspecialidad as Especialidad
            FROM Consultas CON
            JOIN Citas C ON CON.CitaID = C.CitaID
            JOIN Pacientes P ON C.PacienteID = P.PacienteID
            JOIN Doctores D ON C.DoctorID = D.DoctorID
            JOIN Entidades E ON C.EntidadID = E.EntidadID
            JOIN Especialidades ESP ON D.EspecialidadID = ESP.EspecialidadID
            WHERE P.UsuarioID = ?
            ORDER BY C.FechaHora DESC
        ", [$usuarioId]);
    }

    public function getExams(int $usuarioId): array {
        return DB::select("
            SELECT ExamenID, NombreExamen as Titulo, FechaExamen as Fecha,
                ResultadoPath as ArchivoUrl, DoctorSolicitante as Doctor
            FROM Examenes E
            JOIN Pacientes P ON E.PacienteID = P.PacienteID
            WHERE P.UsuarioID = ?
            ORDER BY FechaExamen DESC
        ", [$usuarioId]);
    }

    public function getPrescriptions(int $usuarioId): array {
        return DB::select("
            SELECT R.RecetaID, C.FechaHora as Fecha, D.Nombre + ' ' + D.Apellido as Doctor,
                R.DetalleMedicamentos, R.YaCanjeada
            FROM Recetas R
            JOIN Consultas CON ON R.ConsultaID = CON.ConsultaID
            JOIN Citas C ON CON.CitaID = C.CitaID
            JOIN Pacientes P ON C.PacienteID = P.PacienteID
            JOIN Doctores D ON C.DoctorID = D.DoctorID
            WHERE P.UsuarioID = ?
            ORDER BY C.FechaHora DESC
        ", [$usuarioId]);
    }

    public function descargarReceta($recetaId)
    {
        $datos = DB::selectOne("
            SELECT
                R.RecetaID,
                C.FechaHora,
                D.Nombre + ' ' + D.Apellido as Doctor,
                ESP.NombreEspecialidad as Especialidad,
                P.Nombre + ' ' + P.Apellido as Paciente,
                R.DetalleMedicamentos
            FROM Recetas R
            JOIN Consultas CON ON R.ConsultaID = CON.ConsultaID
            JOIN Citas C ON CON.CitaID = C.CitaID
            JOIN Doctores D ON C.DoctorID = D.DoctorID
            JOIN Especialidades ESP ON D.EspecialidadID = ESP.EspecialidadID
            JOIN Pacientes P ON C.PacienteID = P.PacienteID
            WHERE R.RecetaID = ?
        ", [$recetaId]);

        if (!$datos) return response()->json(['error' => 'Receta no encontrada'], 404);

        $pdf = Pdf::loadView('pdf.receta', ['receta' => $datos]);

        return $pdf->download("Receta_#{$recetaId}.pdf");
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
                P.Telefono,
                U_Pac.Email as EmailPaciente,
                C.Alergias,
                C.MedicamentosActuales,
                U_Doc.Email as EmailDoctor,
                C.EstadoCita,
                P.Aseguradora,
                P.NombreContactoEmergencia,
                P.TelefonoContactoEmergencia
            FROM Citas C
            INNER JOIN Pacientes P ON C.PacienteID = P.PacienteID
            INNER JOIN Usuarios U_Pac ON P.UsuarioID = U_Pac.UsuarioID
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

        $catalogo = array_map(function($item) {
            return [
                'SistemaID' => $item->SistemaID,
                'NombreSistema' => $item->NombreSistema,
                'Hallazgos' => json_decode($item->Hallazgos, true) ?? []
            ];
        }, $resultado);

        return $catalogo;
    }
}
