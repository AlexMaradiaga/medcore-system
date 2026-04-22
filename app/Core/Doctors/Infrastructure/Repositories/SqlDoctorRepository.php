<?php
namespace App\Core\Doctors\Infrastructure\Repositories;

use App\Core\Doctors\Domain\Ports\DoctorRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SqlDoctorRepository implements DoctorRepositoryInterface {
    public function registrar(array $datos): bool {
        $passwordHash = Hash::make($datos['password']);

        $rol = DB::table('Roles')
            ->where('NombreRol', 'Doctor')
            ->where('Estado', 1)
            ->first();

        if (!$rol) {
            throw new Exception("El rol 'Doctor' no está configurado en la base de datos.");
        }

        return DB::statement('EXEC sp_RegistrarDoctor ?, ?, ?, ?, ?, ?, ?, ?, ?', [
            $datos['email'],
            $passwordHash,
            $rol->RolID,
            $datos['especialidad_id'],
            $datos['entidad_id'],
            $datos['nombre'],
            $datos['apellido'],
            $datos['numero_colegiado'],
            $datos['ruta_documento'] ?? null
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

            return DB::table('Doctores')
                ->where('DoctorID', $id)
                ->update([
                    'EspecialidadID'   => $datos['especialidad_id'],
                    'Nombre'           => $datos['nombre'],
                    'Apellido'         => $datos['apellido'],
                    'NumeroColegiado'  => $datos['numero_colegiado']
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
        // Iniciamos la consulta uniendo Doctores con Especialidades para obtener el nombre
        $query = DB::table('Doctores as D')
            ->join('Especialidades as E', 'D.EspecialidadID', '=', 'E.EspecialidadID')
            ->select(
                'D.DoctorID',
                'D.Nombre',
                'D.Apellido',
                'E.NombreEspecialidad as Especialidad',
                'D.EsVerificado',
                'D.Estado'
            )
            ->where('D.Estado', 1);

        // Filtro por nombre o apellido (Búsqueda solicitada)
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function($q) use ($searchTerm) {
                $q->where('D.Nombre', 'like', $searchTerm)
                ->orWhere('D.Apellido', 'like', $searchTerm);
            });
        }

        // Filtro por nombre de especialidad (v-model="filters.especialidad" en Vue)
        if (!empty($filters['especialidad'])) {
            $query->where('E.NombreEspecialidad', $filters['especialidad']);
        }

        // Ejecutar y retornar como array
        return $query->get()->toArray();
    }
}
