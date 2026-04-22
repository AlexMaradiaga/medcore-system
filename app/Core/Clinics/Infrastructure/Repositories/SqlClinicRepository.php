<?php
namespace App\Core\Clinics\Infrastructure\Repositories;

use App\Core\Clinics\Domain\Ports\ClinicRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlClinicRepository implements ClinicRepositoryInterface {
    public function getAllActive(): array {
        return DB::select("SELECT EntidadID as id, NombreEntidad as nombre, RTN, Direccion, Telefono FROM Entidades WHERE TipoEntidad = 'Clinica' AND Estado = 1");
    }

    public function store(array $data): bool {
        return DB::table('Entidades')->insert([
            'NombreEntidad' => $data['nombre'],
            'TipoEntidad'   => 'Clinica',
            'RTN'           => $data['rtn'] ?? null,
            'Direccion'     => $data['direccion'] ?? null,
            'Telefono'      => $data['telefono'] ?? null,
            'Estado'        => 1
        ]);
    }

    public function update(int $id, array $data): bool {
        return DB::table('Entidades')->where('EntidadID', $id)->update([
            'NombreEntidad' => $data['nombre'],
            'RTN'           => $data['rtn'],
            'Direccion'     => $data['direccion'],
            'Telefono'      => $data['telefono']
        ]);
    }

    public function delete(int $id): bool {
        return DB::table('Entidades')->where('EntidadID', $id)->update(['Estado' => 0]);
    }
}