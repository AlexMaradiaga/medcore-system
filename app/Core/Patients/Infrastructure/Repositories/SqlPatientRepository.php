<?php
namespace App\Core\Patients\Infrastructure\Repositories;

use App\Core\Patients\Domain\Ports\PatientRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SqlPatientRepository implements PatientRepositoryInterface {
    public function registrar(array $datos): bool {
        $passwordHash = Hash::make($datos['password']);

        return DB::statement('EXEC sp_RegistrarPaciente ?, ?, ?, ?, ?, ?, ?', [
            $datos['email'],
            $passwordHash,
            $datos['dni'],
            $datos['nombre'],
            $datos['apellido'],
            $datos['telefono'],
            3
        ]);
    }

    public function obtenerTodos(): array {
        return DB::select('
            SELECT
                p.PacienteID, p.UsuarioID, p.DNI, p.Nombre, p.Apellido, p.Telefono,
                p.Estado, u.Email, p.FechaNacimiento, p.Genero,
                p.Aseguradora, p.NumeroPoliza, -- Nuevos campos
                p.NombreContactoEmergencia, p.TelefonoContactoEmergencia
            FROM Pacientes p
            INNER JOIN Usuarios u ON p.UsuarioID = u.UsuarioID
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
            DB::table('Usuarios')
                ->join('Pacientes', 'Usuarios.UsuarioID', '=', 'Pacientes.UsuarioID')
                ->where('Pacientes.PacienteID', $id)
                ->update([
                    'Usuarios.Email'     => $datos['email'],
                    'Usuarios.EntidadID' => $datos['entidad_id']
                ]);

            return DB::table('Pacientes')
                ->where('PacienteID', $id)
                ->update([
                    'Nombre'   => $datos['nombre'],
                    'Apellido' => $datos['apellido'],
                    'Telefono' => $datos['telefono']
                ]);
        });
    }

    public function delete(int $id): bool {
        return DB::table('Pacientes')->where('PacienteID', $id)->update(['Estado' => 0]);
    }
}
