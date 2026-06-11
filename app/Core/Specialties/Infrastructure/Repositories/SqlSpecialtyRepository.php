<?php
namespace App\Core\Specialties\Infrastructure\Repositories;

use App\Core\Specialties\Domain\Ports\SpecialtyRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlSpecialtyRepository implements SpecialtyRepositoryInterface {

    public function getAllActive(): array {
        return DB::select('SELECT EspecialidadID as EspecialidadID, NombreEspecialidad as NombreEspecialidad FROM Especialidades WHERE Estado = 1');
    }

    public function getAll(): array {
        return DB::select('SELECT EspecialidadID, NombreEspecialidad, Estado FROM Especialidades');
    }

    public function findById(int $id): ?object {
        return DB::table('Especialidades')->where('EspecialidadID', $id)->first();
    }

    public function store(array $data): bool {
        return DB::table('Especialidades')->insert([
            'NombreEspecialidad' => $data['nombre'],
            'Estado' => 1
        ]);
    }

    public function update(int $id, array $data): bool {
        return DB::table('Especialidades')
            ->where('EspecialidadID', $id)
            ->update(['NombreEspecialidad' => $data['nombre']]);
    }

    public function delete(int $id): bool {
        return DB::table('Especialidades')
            ->where('EspecialidadID', $id)
            ->update(['Estado' => 0]);
    }
}
