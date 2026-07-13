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

        return DB::statement('EXEC sp_RegistrarPaciente ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
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
            $datos['documento_identidad_url'] ?? null
        ]);
    }


    public function obtenerTodos(): array {
        return DB::select('
            SELECT
                p.PacienteID, p.UsuarioID, p.DNI, p.Nombre, p.Apellido, p.Telefono,
                p.Estado, u.Email, p.Edad, p.Genero,
                p.Aseguradora, p.NumeroPoliza,
                p.NombreContactoEmergencia, p.TelefonoContactoEmergencia,
                p.es_dependiente, p.tutor_dni, p.parentesco
            FROM Pacientes p
            LEFT JOIN Usuarios u ON p.UsuarioID = u.UsuarioID
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

            return DB::table('Pacientes')
                ->where('PacienteID', $id)
                ->update([
                    'Nombre'         => $datos['nombre'],
                    'Apellido'       => $datos['apellido'],
                    'Telefono'       => $datos['telefono'],
                    'es_dependiente' => $datos['es_dependiente'] ?? $paciente->es_dependiente,
                    'tutor_dni'      => $datos['tutor_dni'] ?? $paciente->tutor_dni,
                    'parentesco'     => $datos['parentesco'] ?? $paciente->parentesco
                ]);
        });
    }

    public function delete(int $id): bool {
        return DB::table('Pacientes')->where('PacienteID', $id)->update(['Estado' => 0]);
    }

    public function obtenerPorUsuarioId($usuarioId)
    {
        $paciente = DB::table('Pacientes')
            ->where('UsuarioID', $usuarioId)
            ->where('Estado', 1)
            ->first();

        if (!$paciente) {
            return response()->json(['message' => 'Perfil clínico no encontrado'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $paciente
        ], 200);
    }
}
