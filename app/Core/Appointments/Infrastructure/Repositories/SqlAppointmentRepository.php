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
            ->leftJoin('Especialidades as esp', 'd.EspecialidadID', '=', 'esp.EspecialidadID')
            ->leftJoin('Entidades as e', 'c.EntidadID', '=', 'e.EntidadID')
            ->leftJoin('Consultas as con', 'c.CitaID', '=', 'con.CitaID')
            ->where(function ($query) use ($id) {
                $query->where('p.PacienteID', $id)
                      ->orWhere('p.UsuarioID', $id);
            })
            ->select([
                'c.CitaID',
                'c.CitaID as Folio',
                'con.ConsultaID',
                'c.FechaHora',
                'c.EstadoCita',
                'c.EstadoCita as Estado',
                DB::raw("'General' as TipoCita"),
                'c.Motivo',
                'c.Sintomas',
                'p.PacienteID',
                'p.Nombre as PacienteNombre',
                DB::raw("COALESCE(CONCAT(d.Nombre, ' ', d.Apellido), 'Dr. Por Asignar') as Doctor"),
                DB::raw("COALESCE(e.NombreEntidad, 'Clínica Principal') as Clinica"),
                DB::raw("COALESCE(esp.NombreEspecialidad, 'Medicina General') as Especialidad"),
                'con.Diagnostico',
                'con.NotasMedicas'
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

    /**
     * Reporte administrativo de citas, atención clínica y cobros.
     *
     * Los pagos se agregan primero por cita para que una consulta con varios
     * detalles de pago no multiplique las filas ni los importes del reporte.
     */
    public function getFinancialReport(array $filters): array
    {
        $effectiveStatusSql = "CASE
            WHEN UPPER(ISNULL(c.EstadoCita, '')) LIKE 'CANCELAD%'
              OR UPPER(ISNULL(c.Motivo, '')) LIKE '%(CANCELADO:%'
              OR UPPER(ISNULL(c.Motivo, '')) LIKE '%(CANCELADA:%'
            THEN 'Cancelada'
            ELSE ISNULL(c.EstadoCita, 'Sin estado')
        END";

        $latestConsultation = DB::table('Consultas as lc')
            ->select('lc.CitaID', DB::raw('MAX(lc.ConsultaID) as ConsultaID'))
            ->groupBy('lc.CitaID');

        $paymentsByAppointment = DB::table('Consultas as pc')
            ->join('Detalle_Pagos as dp', function ($join) {
                $join->on('dp.ReferenciaID', '=', 'pc.ConsultaID')
                    ->whereRaw("UPPER(LTRIM(RTRIM(dp.TipoConcepto))) = 'CONSULTA'");
            })
            ->join('Pagos as pg', 'pg.PagoID', '=', 'dp.PagoID')
            ->select([
                'pc.CitaID',
                DB::raw("SUM(CASE
                    WHEN UPPER(LTRIM(RTRIM(ISNULL(pg.EstadoPago, '')))) IN ('PAGADO', 'PROCESADO', 'APROBADO')
                    THEN ISNULL(dp.MontoSubtotal, 0)
                    ELSE 0
                END) as MontoCobrado"),
                DB::raw('SUM(ISNULL(dp.MontoSubtotal, 0)) as MontoRegistrado'),
                DB::raw('COUNT(DISTINCT pg.PagoID) as CantidadPagos'),
                DB::raw("STRING_AGG(CAST(ISNULL(pg.MetodoPago, 'No especificado') AS nvarchar(max)), ', ') as MetodosPago"),
                DB::raw("STRING_AGG(CAST(ISNULL(pg.EstadoPago, 'Sin estado') AS nvarchar(max)), ', ') as EstadosPago"),
                DB::raw('MAX(pg.FechaPago) as UltimaFechaPago'),
                DB::raw('MAX(pg.UsuarioID) as UsuarioCobroID')
            ])
            ->groupBy('pc.CitaID');

        $query = DB::table('Citas as c')
            ->join('Pacientes as pa', 'c.PacienteID', '=', 'pa.PacienteID')
            ->join('Doctores as d', 'c.DoctorID', '=', 'd.DoctorID')
            ->leftJoin('Entidades as e', 'c.EntidadID', '=', 'e.EntidadID')
            ->leftJoin('Usuarios as up', 'pa.UsuarioID', '=', 'up.UsuarioID')
            ->leftJoinSub($latestConsultation, 'lc', function ($join) {
                $join->on('lc.CitaID', '=', 'c.CitaID');
            })
            ->leftJoin('Consultas as con', 'con.ConsultaID', '=', 'lc.ConsultaID')
            ->leftJoinSub($paymentsByAppointment, 'pay', function ($join) {
                $join->on('pay.CitaID', '=', 'c.CitaID');
            })
            ->leftJoin('Usuarios as uc', 'pay.UsuarioCobroID', '=', 'uc.UsuarioID')
            ->select([
                'c.CitaID',
                'c.FechaHora',
                DB::raw("$effectiveStatusSql as EstadoCita"),
                'c.EstadoCita as EstadoOriginal',
                'c.Estado as RegistroActivo',
                'c.Motivo',
                'c.Sintomas',
                'c.Alergias',
                'c.MedicamentosActuales',
                'pa.PacienteID',
                DB::raw("LTRIM(RTRIM(CONCAT(pa.Nombre, ' ', pa.Apellido))) as Paciente"),
                'pa.DNI',
                'pa.Telefono as TelefonoPaciente',
                'pa.Aseguradora',
                'pa.NumeroPoliza',
                'up.Email as EmailPaciente',
                'd.DoctorID',
                DB::raw("LTRIM(RTRIM(CONCAT(d.Nombre, ' ', d.Apellido))) as Doctor"),
                'd.NumeroColegiado',
                'c.EntidadID',
                'e.NombreEntidad as Entidad',
                'e.TipoEntidad',
                'con.ConsultaID',
                'con.FechaCreacion as FechaAtencion',
                'con.Diagnostico',
                'con.NotasMedicas',
                DB::raw('ISNULL(pay.MontoCobrado, 0) as MontoCobrado'),
                DB::raw('ISNULL(pay.MontoRegistrado, 0) as MontoRegistrado'),
                DB::raw('ISNULL(pay.CantidadPagos, 0) as CantidadPagos'),
                'pay.MetodosPago',
                'pay.EstadosPago',
                'pay.UltimaFechaPago',
                'uc.Email as UsuarioCobro'
            ]);

        if (!empty($filters['doctor_id'])) {
            $query->where('c.DoctorID', (int) $filters['doctor_id']);
        }

        if (!empty($filters['entidad_id'])) {
            $query->where('c.EntidadID', (int) $filters['entidad_id']);
        }

        if (!empty($filters['fecha_inicio'])) {
            $query->where('c.FechaHora', '>=', $filters['fecha_inicio'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_fin'])) {
            $query->where('c.FechaHora', '<=', $filters['fecha_fin'] . ' 23:59:59.997');
        }

        if (!empty($filters['estado'])) {
            $query->whereRaw("$effectiveStatusSql = ?", [$filters['estado']]);
        }

        if (!empty($filters['paciente'])) {
            $search = '%' . trim($filters['paciente']) . '%';
            $query->where(function ($patientQuery) use ($search) {
                $patientQuery
                    ->whereRaw("CONCAT(pa.Nombre, ' ', pa.Apellido) LIKE ?", [$search])
                    ->orWhere('pa.DNI', 'like', $search)
                    ->orWhere('up.Email', 'like', $search);
            });
        }

        if (!empty($filters['metodo_pago'])) {
            $method = $filters['metodo_pago'];
            $query->whereExists(function ($paymentQuery) use ($method) {
                $paymentQuery->selectRaw('1')
                    ->from('Consultas as fcon')
                    ->join('Detalle_Pagos as fdp', function ($join) {
                        $join->on('fdp.ReferenciaID', '=', 'fcon.ConsultaID')
                            ->whereRaw("UPPER(LTRIM(RTRIM(fdp.TipoConcepto))) = 'CONSULTA'");
                    })
                    ->join('Pagos as fpg', 'fpg.PagoID', '=', 'fdp.PagoID')
                    ->whereColumn('fcon.CitaID', 'c.CitaID')
                    ->where('fpg.MetodoPago', $method);
            });
        }

        if (!empty($filters['estado_pago'])) {
            $paymentStatus = $filters['estado_pago'];
            $query->whereExists(function ($paymentQuery) use ($paymentStatus) {
                $paymentQuery->selectRaw('1')
                    ->from('Consultas as fcon')
                    ->join('Detalle_Pagos as fdp', function ($join) {
                        $join->on('fdp.ReferenciaID', '=', 'fcon.ConsultaID')
                            ->whereRaw("UPPER(LTRIM(RTRIM(fdp.TipoConcepto))) = 'CONSULTA'");
                    })
                    ->join('Pagos as fpg', 'fpg.PagoID', '=', 'fdp.PagoID')
                    ->whereColumn('fcon.CitaID', 'c.CitaID')
                    ->where('fpg.EstadoPago', $paymentStatus);
            });
        }

        if (!empty($filters['solo_con_pago'])) {
            $query->whereRaw('ISNULL(pay.CantidadPagos, 0) > 0');
        }

        return $query
            ->orderByDesc('c.FechaHora')
            ->limit(5000)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function getFinancialReportCatalogs(): array
    {
        $doctors = DB::table('Doctores')
            ->select('DoctorID', DB::raw("LTRIM(RTRIM(CONCAT(Nombre, ' ', Apellido))) as Nombre"))
            ->orderBy('Nombre')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $entities = DB::table('Entidades')
            ->select('EntidadID', 'NombreEntidad')
            ->orderBy('NombreEntidad')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $paymentMethods = DB::table('Pagos')
            ->whereNotNull('MetodoPago')
            ->distinct()
            ->orderBy('MetodoPago')
            ->pluck('MetodoPago')
            ->all();

        $paymentStatuses = DB::table('Pagos')
            ->whereNotNull('EstadoPago')
            ->distinct()
            ->orderBy('EstadoPago')
            ->pluck('EstadoPago')
            ->all();

        $appointmentStatuses = DB::table('Citas')
            ->whereNotNull('EstadoCita')
            ->distinct()
            ->orderBy('EstadoCita')
            ->pluck('EstadoCita')
            ->map(fn ($status) => stripos((string) $status, 'cancelad') === 0 ? 'Cancelada' : (string) $status)
            ->push('Cancelada')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'doctores' => $doctors,
            'entidades' => $entities,
            'estados_cita' => $appointmentStatuses,
            'metodos_pago' => $paymentMethods,
            'estados_pago' => $paymentStatuses,
        ];
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
