<?php

namespace App\Core\Clinics\Infrastructure\Repositories;

use App\Core\Clinics\Domain\Ports\ClinicRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlClinicRepository implements ClinicRepositoryInterface
{
    public function getAllActive(): array
    {
        return DB::select("
            SELECT
                e.EntidadID as id,
                e.NombreEntidad as nombre,
                e.RTN,
                s.DireccionCompleta as Direccion,
                e.TelefonoInstitucional as Telefono
            FROM Entidades e
            LEFT JOIN Entidad_Sucursales s ON e.EntidadID = s.EntidadID AND s.EsPrincipal = 1
            WHERE e.TipoEntidad = 'Clinica' AND e.Estado = 1
        ");
    }

    public function store(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            $entidadId = DB::table('Entidades')->insertGetId([
                'NombreEntidad'         => $data['nombre'],
                'TipoEntidad'           => 'Clinica',
                'RTN'                   => $data['rtn'] ?? null,
                'TelefonoInstitucional' => $data['telefono'] ?? null,
                'Estado'                => 1,
                'FechaRegistro'         => now()
            ], 'EntidadID');

            if (!empty($data['direccion'])) {
                DB::table('Entidad_Sucursales')->insert([
                    'EntidadID'         => $entidadId,
                    'NombreSucursal'    => 'Matriz / Principal',
                    'DireccionCompleta' => $data['direccion'],
                    'Departamento'      => 'Francisco Morazán',
                    'Municipio'         => 'Distrito Central',
                    'EsPrincipal'       => 1
                ]);
            }

            return true;
        });
    }

    public function update(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            DB::table('Entidades')->where('EntidadID', $id)->update([
                'NombreEntidad'         => $data['nombre'],
                'RTN'                   => $data['rtn'] ?? null,
                'TelefonoInstitucional' => $data['telefono'] ?? null
            ]);

            if (isset($data['direccion'])) {
                DB::table('Entidad_Sucursales')
                    ->where('EntidadID', $id)
                    ->where('EsPrincipal', 1)
                    ->update(['DireccionCompleta' => $data['direccion']]);
            }

            return true;
        });
    }

    public function delete(int $id): bool
    {
        return DB::table('Entidades')->where('EntidadID', $id)->update(['Estado' => 0]);
    }
}
