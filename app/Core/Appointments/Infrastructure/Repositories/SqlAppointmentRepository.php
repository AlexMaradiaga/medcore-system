<?php

namespace App\Core\Appointments\Infrastructure\Repositories;

use App\Core\Appointments\Domain\Ports\AppointmentRepositoryInterface;
use Illuminate\Support\Facades\DB;

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
            return DB::statement('EXEC sp_AgendarCita ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
                $data['UsuarioID'],                         // 1
                $data['doctor_id'],                         // 2
                $data['entidad_id'],                        // 3
                $data['fecha_hora'],                        // 4
                $data['motivo'],                            // 5
                $data['estado_cita'] ?? 'Pendiente',        // 6
                $data['sintomas'] ?? null,                  // 7
                $data['alergias'] ?? null,                  // 8
                $data['edad'],                              // 9
                $data['genero'],                            // 10
                $data['aseguradora'] ?? null,               // 11
                $data['numero_poliza'] ?? null,             // 12
                $data['nombre_contacto_emergencia'],        // 13
                $data['telefono_contacto_emergencia'],      // 14
                $data['medicamentos_actuales'] ?? null      // 15
            ]);
        });
    }

    public function getPendingByDoctor(int $doctorId): array {
        return DB::select("
            SELECT
                C.CitaID,
                C.FechaHora,
                C.Motivo,
                C.Sintomas, -- Traemos los síntomas de la tabla Citas
                P.Nombre + ' ' + P.Apellido as Paciente,
                P.Telefono,
                P.Aseguradora, -- Traemos el seguro del perfil del Paciente
                P.NombreContactoEmergencia as ContactoEmergencia
            FROM Citas C
            JOIN Pacientes P ON C.PacienteID = P.PacienteID
            WHERE C.DoctorID = ?
            AND C.EstadoCita = 'Pendiente'
            AND C.Estado = 1
            ORDER BY C.FechaHora ASC
        ", [$doctorId]);
    }

    public function getHistoryByPatient(int $pacienteId): array
    {
        return DB::select("
            SELECT C.CitaID, C.FechaHora, C.EstadoCita,
                   D.Nombre + ' ' + D.Apellido as Doctor,
                   E.NombreEntidad as Clinica
            FROM Citas C
            JOIN Doctores D ON C.DoctorID = D.DoctorID
            JOIN Entidades E ON C.EntidadID = E.EntidadID
            WHERE C.PacienteID = ? AND C.Estado = 1
            ORDER BY C.FechaHora DESC
        ", [$pacienteId]);
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
        return DB::table('Citas')
            ->where('CitaID', $citaId)
            ->update([
                'EstadoCita' => 'Cancelada',
                'Motivo' => $motivoCancelacion ? DB::raw("Motivo + ' (Cancelado: $motivoCancelacion)'") : DB::raw("Motivo")
            ]);
    }

    public function complete(array $data): bool
    {
        $cita = DB::table('Citas')->where('CitaID', $data['cita_id'])->first();

        if (!$cita) {
            throw new \Exception("La cita no existe.");
        }

        if ($cita->EstadoCita !== 'Pendiente') {
            throw new \Exception("No se puede finalizar una cita que está {$cita->EstadoCita}. Solo se pueden finalizar citas 'Pendientes'.");
        }
        return DB::statement("EXEC sp_FinalizarConsulta ?, ?, ?, ?, ?", [
            $data['cita_id'],
            $data['diagnostico'],
            $data['notas_medicas'] ?? null,
            $data['detalle_medicamentos'],
            'Completada'
        ]);
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
}
