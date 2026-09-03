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

    public function getAllActive(array $filters = []): array
    {
        $query = DB::table('Doctores as D')
            ->join('Especialidades as E', 'D.EspecialidadID', '=', 'E.EspecialidadID')
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
                'D.DireccionConsultorio'
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

            // 1. Finalizar la consulta actual
            DB::statement("EXEC sp_FinalizarConsulta ?, ?, ?, ?, ?", [
                $data['cita_id'],
                $data['diagnostico'],
                $data['notas_medicas'] ?? null,
                $payloadJsonStr,
                'Completada'
            ]);

            // 2. Lógica para crear la cita de seguimiento automatizada
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
                    'EstadoCita'           => 'Confirmada', // Estado confirmada para que aparezca en agenda
                    'Estado'               => 1,
                    'Sintomas'             => 'Seguimiento clínico automatizado.',
                    'Alergias'             => $cita->Alergias ?? null,
                    'MedicamentosActuales' => $cita->MedicamentosActuales ?? null
                ]);
            }

            return true;
        });
    }
}
