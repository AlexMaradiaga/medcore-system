<?php
namespace App\Core\Patients\Infrastructure\Repositories;

use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SqlPatientRepository implements PatientRepositoryInterface {


    public function registrar(array $datos): bool {
        $rol = DB::table('Roles')->where('NombreRol', 'Paciente')->first();

        if (!$rol) {
            throw new \Exception("Error de Configuración: El rol 'Paciente' no existe en la base de datos.");
        }

        $es_dependiente = filter_var($datos['es_dependiente'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $email = $es_dependiente ? ($datos['tutor_email'] ?? null) : ($datos['email'] ?? null);
        $passwordHash = isset($datos['password']) ? Hash::make($datos['password']) : null;
        $telefono = $es_dependiente ? null : ($datos['telefono'] ?? null);

        return DB::statement('EXEC sp_RegistrarPaciente ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
            $email,                                        
            $passwordHash,
            $datos['dni'],
            $datos['nombre'],
            $datos['apellido'],
            $telefono,
            $rol->RolID,
            $es_dependiente,
            $datos['tutor_dni'] ?? null,
            $datos['parentesco'] ?? null,
            $datos['tutor_nombre'] ?? null,
            $datos['tutor_telefono'] ?? null,
            $datos['documento_identidad_url'] ?? null,
            $datos['nacionalidad'] ?? 'Hondureña',
            $datos['tipo_sangre'] ?? null
        ]);
    }


    public function obtenerTodos(): array {
        return DB::select('
            SELECT
                p.PacienteID, p.UsuarioID, p.DNI, p.Nombre, p.Apellido, p.Telefono,
                p.Nacionalidad, p.TipoSangre,
                p.Estado, u.Email, p.Edad, p.Genero,
                p.Aseguradora, p.NumeroPoliza,
                p.NombreContactoEmergencia, p.TelefonoContactoEmergencia,
                p.es_dependiente, t.DNI as tutor_dni, p.parentesco
            FROM Pacientes p
            LEFT JOIN Usuarios u ON p.UsuarioID = u.UsuarioID
            LEFT JOIN Tutores t ON p.TutorID = t.TutorID
            WHERE p.Estado = 1
        ');
    }

    public function update(int $id, array $datos): bool
    {
        $entidadExiste = DB::table('Entidades')
            ->where('EntidadID', $datos['entidad_id'])
            ->exists();

        if (!$entidadExiste) {
            throw new \Exception("La sede de referencia no es válida.");
        }

        return DB::transaction(function () use ($id, $datos) {
            $paciente = DB::table('Pacientes')->where('PacienteID', $id)->first();

            if ($paciente && $paciente->UsuarioID) {
                DB::table('Usuarios')
                    ->where('UsuarioID', $paciente->UsuarioID)
                    ->update([
                        'Email'     => $datos['email'],
                        'EntidadID' => $datos['entidad_id']
                    ]);
            }

            // Si es dependiente y se envía tutor_dni, actualizamos la tabla Tutores
            if ($paciente && $paciente->TutorID && isset($datos['tutor_dni'])) {
                DB::table('Tutores')
                    ->where('TutorID', $paciente->TutorID)
                    ->update(['DNI' => $datos['tutor_dni']]);
            }

            // CORREGIDO: Se removió la asignación de tutor_dni directo sobre la tabla Pacientes
            return DB::table('Pacientes')
                ->where('PacienteID', $id)
                ->update([
                    'Nombre'         => $datos['nombre'],
                    'Apellido'       => $datos['apellido'],
                    'Telefono'       => $datos['telefono'],
                    'Nacionalidad'   => $datos['nacionalidad'] ?? $paciente->Nacionalidad,
                    'TipoSangre'     => $datos['tipo_sangre'] ?? $paciente->TipoSangre,
                    'es_dependiente' => $datos['es_dependiente'] ?? $paciente->es_dependiente,
                    'parentesco'     => $datos['parentesco'] ?? $paciente->parentesco
                ]);
        });
    }

    public function delete(int $id): bool {
        return DB::table('Pacientes')->where('PacienteID', $id)->update(['Estado' => 0]);
    }

    public function obtenerPorUsuarioId(int $usuarioId): ?object
    {
        // 1. Buscamos al paciente asociado directamente al UsuarioID autenticado
        $paciente = DB::table('Pacientes as P')
            ->leftJoin('Tutores as T', 'P.TutorID', '=', 'T.TutorID')
            ->leftJoin('Usuarios as U', 'P.UsuarioID', '=', 'U.UsuarioID')
            ->where('P.UsuarioID', $usuarioId)
            ->where('P.Estado', 1)
            ->select([
                'P.PacienteID as id',
                'P.PacienteID as PacienteID',
                'P.UsuarioID as usuario_id',
                'P.Nombre as nombre',
                'P.Nombre as Nombre',
                'P.Apellido as apellido',
                'P.DNI as dni',
                'P.DNI as DNI',
                'P.Telefono as telefono',
                'P.Edad as fecha_nacimiento',
                'P.Genero as genero',
                'P.TipoSangre as tipo_sangre',
                'P.Aseguradora as aseguradora',
                'P.NumeroPoliza as poliza',
                'P.NombreContactoEmergencia as nombre_contacto_emergencia',
                'P.TelefonoContactoEmergencia as telefono_contacto_emergencia',
                'P.es_dependiente',
                'P.TutorID as tutor_id',
                'P.parentesco',
                'U.Email as usuario_email'
            ])
            ->first();

        if ($paciente) {
            // BUSCAR TUTORID ASOCIADO:
            // a) Por P.TutorID si existe
            // b) Buscando en la tabla Tutores por el DNI o Email del Paciente/Usuario
            $tutorRecord = null;

            if (!empty($paciente->tutor_id)) {
                $tutorRecord = DB::table('Tutores')->where('TutorID', $paciente->tutor_id)->first();
            }

            if (!$tutorRecord && !empty($paciente->dni)) {
                $tutorRecord = DB::table('Tutores')->where('DNI', $paciente->dni)->first();
            }

            if (!$tutorRecord && !empty($paciente->usuario_email)) {
                $tutorRecord = DB::table('Tutores')->where('Email', $paciente->usuario_email)->first();
            }

            $dependientes = [];
            if ($tutorRecord) {
                $dependientes = $this->obtenerDependientesPorTutorId((int)$tutorRecord->TutorID);
            }

            return (object) [
                'es_tutor'     => count($dependientes) > 0,
                'data'         => $paciente,
                'dependientes' => $dependientes
            ];
        }

        // 2. Si el usuario no tiene registro directo en Pacientes, consultar si existe en la tabla Tutores
        $tutor = DB::table('Tutores as T')
            ->join('Usuarios as U', 'U.Email', '=', 'T.Email')
            ->where('U.UsuarioID', $usuarioId)
            ->select('T.TutorID', 'T.DNI', 'T.NombreCompleto', 'T.Telefono', 'T.Email')
            ->first();

        if ($tutor) {
            $dependientes = $this->obtenerDependientesPorTutorId((int)$tutor->TutorID);

            return (object) [
                'es_tutor'     => true,
                'data'         => [
                    'id'             => null,
                    'usuario_id'     => $usuarioId,
                    'nombre'         => $tutor->NombreCompleto,
                    'dni'            => $tutor->DNI,
                    'telefono'       => $tutor->Telefono,
                    'es_dependiente' => 0,
                    'tutor_id'       => $tutor->TutorID
                ],
                'dependientes' => $dependientes
            ];
        }

        return null;
    }

    public function obtenerDependientesPorTutorId(int $tutorId): array
    {
        return DB::table('Pacientes as p')
            ->leftJoin('Tutores as t', 'p.TutorID', '=', 't.TutorID')
            ->where('p.TutorID', $tutorId)
            ->where('p.Estado', 1)
            ->select([
                'p.PacienteID as id',
                'p.PacienteID as PacienteID',
                'p.UsuarioID as usuario_id',
                'p.Nombre as nombre',
                'p.Nombre as Nombre',
                'p.Apellido as apellido',
                'p.DNI as dni',
                'p.Telefono as telefono',
                'p.Edad as fecha_nacimiento',
                'p.Genero as genero',
                'p.TipoSangre as tipo_sangre',
                'p.Aseguradora as aseguradora',
                'p.NumeroPoliza as poliza',
                'p.NombreContactoEmergencia as nombre_contacto_emergencia',
                'p.TelefonoContactoEmergencia as telefono_contacto_emergencia',
                'p.es_dependiente',
                'p.TutorID as tutor_id',
                'p.TutorID as TutorID',
                'p.parentesco',
                't.DNI as tutor_dni',
                't.NombreCompleto as tutor_nombre',
                't.Telefono as tutor_telefono',
                't.Email as tutor_email'
            ])
            ->get()
            ->toArray();
    }
}
