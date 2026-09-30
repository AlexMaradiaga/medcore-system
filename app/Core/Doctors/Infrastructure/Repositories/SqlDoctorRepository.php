<?php
namespace App\Core\Doctors\Infrastructure\Repositories;

use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Exception;

class SqlDoctorRepository implements DoctorRepositoryInterface {

    public function registrar(array $datos): bool {
        $passwordHash = Hash::make($datos['password']);

        $rol = DB::table('Roles')
            ->where('NombreRol', 'Doctor')
            ->where('Estado', 1)
            ->first();

        if (!$rol) {
            throw new \Exception("El rol 'Doctor' no está configurado en la base de datos.");
        }

        $hablaIngles = filter_var($datos['habla_ingles'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $disponibleDomicilio = filter_var($datos['disponible_domicilio'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        return DB::statement('EXEC sp_RegistrarDoctor ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
            $datos['email'],
            $passwordHash,
            $rol->RolID,
            $datos['entidad_id'] ?? null,
            $datos['especialidad_id'],
            $datos['nombre'],
            $datos['apellido'],
            $datos['numero_colegiado'],
            $datos['ruta_foto'] ?? null,
            $datos['ruta_titulo_medico'] ?? null,
            $datos['ruta_titulo_especialista'] ?? null,
            $datos['ruta_constancia_colegio'] ?? null,
            $datos['ruta_dni'] ?? null,
            $datos['nacionalidad'] ?? 'Hondureña',
            $hablaIngles,
            $datos['otros_idiomas'] ?? null,
            $disponibleDomicilio,
            $datos['latitud'] ?? null,
            $datos['longitud'] ?? null,
            $datos['direccion_consultorio'] ?? null
        ]);
    }

    public function obtenerPorEspecialidad(int $id): array {
        return DB::select('SELECT * FROM Doctores WHERE EspecialidadID = ? AND Estado = 1', [$id]);
    }

    public function update(int $id, array $datos): bool {
        $especialidadExiste = DB::table('Especialidades')
            ->where('EspecialidadID', $datos['especialidad_id'])
            ->where('Estado', 1)
            ->exists();

        $entidadExiste = DB::table('Entidades')
            ->where('EntidadID', $datos['entidad_id'])
            ->where('Estado', 1)
            ->exists();

        if (!$especialidadExiste || !$entidadExiste) {
            throw new \Exception("La Especialidad o la Clínica seleccionada no son válidas o están inactivas.");
        }

        return DB::transaction(function () use ($id, $datos) {
            DB::table('Usuarios')
                ->join('Doctores', 'Usuarios.UsuarioID', '=', 'Doctores.UsuarioID')
                ->where('Doctores.DoctorID', $id)
                ->update([
                    'Usuarios.EntidadID' => $datos['entidad_id'],
                    'Usuarios.Email'     => $datos['email']
                ]);

            $hablaIngles = filter_var($datos['habla_ingles'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $disponibleDomicilio = filter_var($datos['disponible_domicilio'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

            return DB::table('Doctores')
                ->where('DoctorID', $id)
                ->update([
                    'EspecialidadID'       => $datos['especialidad_id'],
                    'Nombre'               => $datos['nombre'],
                    'Apellido'             => $datos['apellido'],
                    'NumeroColegiado'      => $datos['numero_colegiado'],
                    'Nacionalidad'         => $datos['nacionalidad'] ?? 'Hondureña',
                    'HablaIngles'          => $hablaIngles,
                    'OtrosIdiomas'         => $datos['otros_idiomas'] ?? null,
                    'DisponibleDomicilio'  => $disponibleDomicilio,
                    'Latitud'              => $datos['latitud'] ?? null,
                    'Longitud'             => $datos['longitud'] ?? null,
                    'DireccionConsultorio' => $datos['direccion_consultorio'] ?? null,
                ]);
        });
    }

    public function delete(int $id): bool {
        return DB::transaction(function () use ($id) {
            DB::table('Doctores')->where('DoctorID', $id)->update(['Estado' => 0]);

            $usuarioId = DB::table('Doctores')->where('DoctorID', $id)->value('UsuarioID');
            return DB::table('Usuarios')->where('UsuarioID', $usuarioId)->update(['Estado' => 0]);
        });
    }

    public function getAllActive(array $filters = []): array {
        $query = DB::table('Doctores as D')
            ->join('Especialidades as E', 'D.EspecialidadID', '=', 'E.EspecialidadID')
            ->join('Usuarios as U', 'D.UsuarioID', '=', 'U.UsuarioID')
            ->select(
                'D.DoctorID',
                'D.UsuarioID',
                'D.EspecialidadID',
                'D.Nombre',
                'D.Apellido',
                'E.NombreEspecialidad as Especialidad',
                'D.EsVerificado',
                'D.Estado',
                'D.Nacionalidad',
                'D.HablaIngles',
                'D.OtrosIdiomas',
                'D.DisponibleDomicilio',
                'D.Latitud',
                'D.Longitud',
                'D.DireccionConsultorio',
                'U.EsFounder',
                'U.NivelFounder',
                DB::raw("
                    CASE
                        -- 1. Verifica el horario configurado para hoy con tu fórmula exacta (+1)
                        WHEN EXISTS (
                            SELECT 1 FROM Doctor_Horarios H WITH (NOLOCK)
                            WHERE H.DoctorID = D.DoctorID
                            AND H.DiaSemana = ((DATEDIFF(dd, 0, GETDATE()) % 7) + 1)
                            AND H.Estado = 1
                        )
                        -- 2. Descarta si existe un bloqueo activo en el instante actual de la BD
                        AND NOT EXISTS (
                            SELECT 1 FROM Doctor_Bloqueos B WITH (NOLOCK)
                            WHERE B.DoctorID = D.DoctorID
                            AND GETDATE() BETWEEN B.FechaInicio AND B.FechaFin
                            AND B.Estado = 1
                        ) THEN 1 ELSE 0
                    END as DisponibleAhora
                "),
                DB::raw("
                    CASE
                        -- Muestra 'Fuera de Servicio' si el médico está bloqueado en este momento
                        WHEN EXISTS (
                            SELECT 1 FROM Doctor_Bloqueos B WITH (NOLOCK)
                            WHERE B.DoctorID = D.DoctorID
                            AND GETDATE() BETWEEN B.FechaInicio AND B.FechaFin
                            AND B.Estado = 1
                        ) THEN 'Fuera de Servicio'
                        -- De lo contrario, calcula el rango de horario habitual
                        ELSE (
                            SELECT TOP 1 CONCAT(
                                FORMAT(CAST(HoraInicio AS datetime), 'hh:mm tt'),
                                ' - ',
                                FORMAT(CAST(HoraFin AS datetime), 'hh:mm tt')
                            )
                            FROM Doctor_Horarios H WITH (NOLOCK)
                            WHERE H.DoctorID = D.DoctorID
                            AND H.DiaSemana = ((DATEDIFF(dd, 0, GETDATE()) % 7) + 1)
                            AND H.Estado = 1
                        )
                    END as horario_resumen
                ")
            )
            ->where('D.Estado', 1);

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function($q) use ($searchTerm) {
                $q->where('D.Nombre', 'like', $searchTerm)
                ->orWhere('D.Apellido', 'like', $searchTerm);
            });
        }

        if (!empty($filters['especialidad'])) {
            $query->where('E.NombreEspecialidad', $filters['especialidad']);
        }

        return $query->get()->toArray();
    }
    public function getFullHistory(int $pacienteId, int $doctorId): array {
        $results = DB::select("EXEC sp_ObtenerHistorialClinico ?, ?", [$pacienteId, $doctorId]);

        return [
            'consultations' => $results,
            'comparatives' => [],
            'labResults' => []
        ];
    }

    public function obtenerMisPacientesAtendidos(int $doctorId): array {
        return DB::table('Pacientes as P')
            ->join('Citas as C', 'P.PacienteID', '=', 'C.PacienteID')
            ->join('Consultas as Co', 'C.CitaID', '=', 'Co.CitaID')
            ->select(
                'P.PacienteID',
                DB::raw("CONCAT(P.Nombre, ' ', P.Apellido) as Nombre"),
                'P.DNI as Identidad',
                'P.Edad',
                'P.Genero',
                'P.Telefono',
                DB::raw("MAX(C.FechaHora) as UltimaConsulta")
            )
            ->where('C.DoctorID', $doctorId)
            ->where('P.Estado', 1)
            ->groupBy('P.PacienteID', 'P.Nombre', 'P.Apellido', 'P.DNI', 'P.Edad', 'P.Genero', 'P.Telefono')
            ->orderBy('UltimaConsulta', 'DESC')
            ->get()
            ->toArray();
    }

    public function complete(array $data): bool {
        $data = json_decode(json_encode($data), true);
        $cita = DB::table('Citas')->where('CitaID', $data['cita_id'])->first();

        if (!$cita) {
            throw new \Exception("La cita ID: {$data['cita_id']} no existe.");
        }
        if ($cita->EstadoCita !== 'Confirmada') {
            throw new \Exception("La cita debe estar 'Confirmada' para finalizarla. Estado actual: {$cita->EstadoCita}");
        }

        if (isset($data['presupuesto_total'])) {
            $data['presupuesto_total'] = (float)$data['presupuesto_total'];
        }

        return DB::transaction(function () use ($data, $cita) {
            $payloadJsonStr = json_encode($data);

            DB::statement("EXEC sp_FinalizarConsulta ?, ?, ?, ?, ?", [
                $data['cita_id'],
                $data['diagnostico'],
                $data['notas_medicas'] ?? null,
                $payloadJsonStr,
                'Completada'
            ]);

            $crearSeguimiento = filter_var($data['crear_seguimiento'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $fechaSeguimiento = $data['seguimiento_fecha_hora'] ?? null;

            if ($crearSeguimiento && !empty($fechaSeguimiento)) {
                $motivoSeguimiento = 'Cita de revisión programada post-consulta #' . $data['cita_id'];

                DB::table('Citas')->insert([
                    'PacienteID'           => $cita->PacienteID,
                    'DoctorID'             => $cita->DoctorID,
                    'EntidadID'            => $cita->EntidadID,
                    'FechaHora'            => $fechaSeguimiento,
                    'Motivo'               => $motivoSeguimiento,
                    'EstadoCita'           => 'Confirmada',
                    'Estado'               => 1,
                    'Sintomas'             => 'Seguimiento clínico automatizado.',
                    'Alergias'             => $cita->Alergias ?? null,
                    'MedicamentosActuales' => $cita->MedicamentosActuales ?? null
                ]);
            }

            return true;
        });
    }

    public function guardarHorarios(int $doctorId, array $horarios): bool {
        $horariosLimpios = array_map(function ($h) {
            return [
                'dia_semana'       => (int)$h['dia_semana'],
                'hora_inicio'      => strlen($h['hora_inicio']) === 5 ? $h['hora_inicio'] . ':00' : $h['hora_inicio'],
                'hora_fin'         => strlen($h['hora_fin']) === 5 ? $h['hora_fin'] . ':00' : $h['hora_fin'],
                'duracion_minutos' => (int)($h['duracion_minutos'] ?? 30),
            ];
        }, $horarios);

        try {
            return DB::transaction(function () use ($doctorId, $horariosLimpios) {
                $diasEnviados = array_column($horariosLimpios, 'dia_semana');

                DB::table('Doctor_Horarios')
                    ->where('DoctorID', $doctorId)
                    ->whereNotIn('DiaSemana', $diasEnviados)
                    ->update(['Estado' => 0]);

                foreach ($horariosLimpios as $h) {
                    DB::table('Doctor_Horarios')->updateOrInsert(
                        [
                            'DoctorID'  => $doctorId,
                            'DiaSemana' => $h['dia_semana'],
                        ],
                        [
                            'HoraInicio'          => $h['hora_inicio'],
                            'HoraFin'             => $h['hora_fin'],
                            'DuracionCitaMinutos' => $h['duracion_minutos'],
                            'Estado'              => 1,
                        ]
                    );
                }

                return true;
            });
        } catch (\Exception $e) {
            \Log::error("Error en guardarHorarios DoctorID {$doctorId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function registrarBloqueo(int $doctorId, array $datos): bool {
        return DB::table('Doctor_Bloqueos')->insert([
            'DoctorID'    => $doctorId,
            'FechaInicio' => $datos['fecha_inicio'],
            'FechaFin'    => $datos['fecha_fin'],
            'Motivo'      => $datos['motivo'] ?? 'No disponible',
            'Estado'      => 1
        ]);
    }

    public function eliminarBloqueo(int $bloqueoId): bool {
        return DB::table('Doctor_Bloqueos')
            ->where('BloqueoID', $bloqueoId)
            ->update(['Estado' => 0]) > 0;
    }

    public function obtenerDisponibilidad(int $doctorId): array {
        $horarios = DB::table('Doctor_Horarios')->where('DoctorID', $doctorId)->where('Estado', 1)->get();
        $bloqueos = DB::table('Doctor_Bloqueos')->where('DoctorID', $doctorId)->where('Estado', 1)->get();

        return [
            'horarios' => $horarios,
            'bloqueos' => $bloqueos
        ];
    }

    public function obtenerPorClinica(int $entidadId): array {
        return DB::table('Doctores as D')
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
                'U.EsFounder',
                'U.NivelFounder',
                DB::raw('ISNULL(MAX(SM.Precio), 90) as CostoConsulta')
            )
            ->where('U.EntidadID', $entidadId)
            ->where('D.Estado', 1)
            ->groupBy(
                'D.DoctorID', 'D.Nombre', 'D.Apellido', 'E.NombreEspecialidad',
                'U.EntidadID', 'D.RutaFoto', 'D.EsVerificado', 'D.Estado',
                'D.Nacionalidad', 'D.HablaIngles', 'D.OtrosIdiomas', 'U.EsFounder', 'U.NivelFounder',
                'D.DisponibleDomicilio', 'D.Latitud', 'D.Longitud', 'D.DireccionConsultorio'
            )
            ->get()
            ->toArray();
    }

    public function guardarUbicacionConsultorio(array $datos): bool {
        return DB::table('Doctores')
            ->where('DoctorID', $datos['doctor_id'])
            ->update([
                'Latitud'              => $datos['latitud'],
                'Longitud'             => $datos['longitud'],
                'DireccionConsultorio' => $datos['direccion_consultorio'],
                'HablaIngles'          => $datos['habla_ingles'] ?? 0,
                'DisponibleDomicilio'  => $datos['disponible_domicilio'] ?? 0,
            ]) > 0;
    }
}
