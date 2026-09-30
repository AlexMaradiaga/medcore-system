<?php

namespace App\Core\Entity\Infrastructure;

use App\Core\Entity\Domain\EntityRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Services\SaaSSubscriptionProvisioner;

class SqlEntityRepository implements EntityRepositoryInterface
{
    public function getAll(?string $tipo = null): array
    {
        $query = DB::table('Entidades')->where('Estado', 1);
        if ($tipo) {
            if (strtolower($tipo) === 'instituciones') {
                $query->whereIn('TipoEntidad', ['Clinica', 'Farmacia', 'Laboratorio']);
            } else {
                $query->where('TipoEntidad', $tipo);
            }
        }
        return $query->get()->toArray();
    }

    public function getEntidadesPublicas(?string $tipo = null): array
    {
        $query = DB::table('Entidades as e')
            ->select([
                'e.EntidadID',
                'e.NombreEntidad',
                'e.RazonSocial',
                'e.TipoEntidad',
                'e.RTN',
                'e.TelefonoInstitucional as Telefono',
                'e.Direccion'
            ])
            ->where('e.Estado', 1);

        if ($tipo) {
            if (in_array(strtolower($tipo), ['instituciones', 'todas'])) {
                $query->whereIn('e.TipoEntidad', ['Clinica', 'Farmacia', 'Laboratorio']);
            } else {
                $query->where('e.TipoEntidad', $tipo);
            }
        }

        return $query->get()->toArray();
    }

    public function registerInstitution(array $data, Request $request): int
    {
        return DB::transaction(function () use ($data, $request) {
            // 1. Entidad Central (Estado = 0 -> Pendiente de aprobación)
            $entidadId = DB::table('Entidades')->insertGetId([
                'NombreEntidad'         => $data['nombre_comercial'],
                'RazonSocial'           => $data['razon_social'],
                'TipoEntidad'           => $data['tipo_entidad'],
                'RTN'                   => $data['rtn'],
                'Direccion'             => $data['direccion_completa'],
                'EmailInstitucional'    => $data['email_institucional'],
                'TelefonoInstitucional' => $data['telefono_institucional'],
                'Estado'                => 0,
                'FechaRegistro'         => now()
            ], 'EntidadID');

            // 2. RolID según el tipo de entidad
            $rolId = match ($data['tipo_entidad']) {
                'Clinica'     => 1,
                'Farmacia'    => 4,
                'Laboratorio' => 5,
                default       => 1
            };

            // 3. Usuario Administrador de la Entidad (Estado = 0 hasta aprobación)
            DB::table('Usuarios')->insert([
                'EntidadID'    => $entidadId,
                'RolID'        => $rolId,
                'Email'        => $data['email_usuario'],
                'PasswordHash' => Hash::make($data['password_usuario']),
                'Estado'       => 0
            ]);

            // 4. Representante Legal
            $rutaRepFoto = $request->hasFile('rep_foto_dni')
                ? $request->file('rep_foto_dni')->store('expedientes/representantes', 'public')
                : null;

            DB::table('Entidad_RepresentantesLegales')->insert([
                'EntidadID'      => $entidadId,
                'NombreCompleto' => $data['rep_nombre'],
                'DNI'            => $data['rep_dni'],
                'Email'          => $data['rep_email'],
                'Telefono'       => $data['rep_telefono'],
                'Cargo'          => $data['rep_cargo'],
                'RutaFotoDNI'    => $rutaRepFoto
            ]);

            // 5. Responsable Sanitario
            $rutaSanDoc = $request->hasFile('san_doc_colegiacion')
                ? $request->file('san_doc_colegiacion')->store('expedientes/sanitarios', 'public')
                : null;

            DB::table('Entidad_ResponsablesSanitarios')->insert([
                'EntidadID'          => $entidadId,
                'NombreCompleto'     => $data['san_nombre'],
                'Profesion'          => $data['san_profesion'],
                'NumeroColegiado'    => $data['san_colegiacion'],
                'ColegioProfesional' => $data['san_colegio'],
                'Email'              => $data['san_email'],
                'Telefono'           => $data['san_telefono'],
                'RutaDocColegiacion' => $rutaSanDoc
            ]);

            // 6. Sucursal / Ubicación Principal
            DB::table('Entidad_Sucursales')->insert([
                'EntidadID'         => $entidadId,
                'NombreSucursal'    => $data['nombre_sucursal'] ?? 'Matriz / Principal',
                'DireccionCompleta' => $data['direccion_completa'],
                'Departamento'      => $data['departamento'],
                'Municipio'         => $data['municipio'],
                'Zona'              => $data['zona'] ?? null,
                'Latitud'           => $data['latitud'] ?? null,
                'Longitud'          => $data['longitud'] ?? null,
                'EsPrincipal'       => 1
            ]);

            return $entidadId;
        });
    }

    public function getPendingEntities(): array
    {
        return DB::table('Entidades as e')
            ->leftJoin('Entidad_RepresentantesLegales as r', 'e.EntidadID', '=', 'r.EntidadID')
            ->leftJoin('Entidad_ResponsablesSanitarios as s', 'e.EntidadID', '=', 's.EntidadID')
            ->leftJoin('Entidad_Sucursales as suc', function ($join) {
                $join->on('e.EntidadID', '=', 'suc.EntidadID')->where('suc.EsPrincipal', '=', 1);
            })
            ->select([
                'e.EntidadID',
                'e.NombreEntidad',
                'e.RazonSocial',
                'e.TipoEntidad',
                'e.RTN',
                'e.EmailInstitucional',
                'e.TelefonoInstitucional',
                'e.FechaRegistro',
                'r.NombreCompleto as RepNombre',
                'r.DNI as RepDNI',
                'r.RutaFotoDNI as RepFoto',
                's.NombreCompleto as SanNombre',
                's.NumeroColegiado as SanColegiacion',
                's.RutaDocColegiacion as SanDoc',
                'suc.DireccionCompleta as Direccion'
            ])
            ->where('e.Estado', 0)
            ->get()
            ->toArray();
    }

    public function approveEntity(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            DB::table('Entidades')->where('EntidadID', $id)->update(['Estado' => 1]);
            DB::table('Usuarios')->where('EntidadID', $id)->update(['Estado' => 1]);
            app(SaaSSubscriptionProvisioner::class)->provisionEntity($id);
            return true;
        });
    }
}
