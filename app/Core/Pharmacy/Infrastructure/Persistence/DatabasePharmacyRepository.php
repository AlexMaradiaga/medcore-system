<?php

namespace App\Core\Pharmacy\Infrastructure\Persistence;

use App\Core\Pharmacy\Domain\Entities\Prescription;
use App\Core\Pharmacy\Domain\Entities\PrescriptionOrder;
use App\Core\Pharmacy\Domain\Repositories\PharmacyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class DatabasePharmacyRepository implements PharmacyRepositoryInterface
{
    public function getDashboardMetrics(?int $farmaciaId = null, int $page = 1, int $perPage = 10, int $yaCanjeada = 0): array
    {
        $queryRecetas = DB::table('Recetas')->where('YaCanjeada', 0);

        // Ajuste en el filtro de fecha usando SQL Server nativo (GETDATE)
        $queryVentas = DB::table('Recetas')
            ->where('YaCanjeada', 1)
            ->whereRaw("CAST(FechaSurtido AS DATE) = CAST(GETDATE() AS DATE)");

        if ($farmaciaId) {
            $queryVentas->where('FarmaciaID', $farmaciaId);
        }

        $pedidosQuery = DB::table('Recetas as R')
            ->leftJoin('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
            ->leftJoin('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
            ->leftJoin('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
            ->leftJoin('Doctores as D', 'C.DoctorID', '=', 'D.DoctorID')
            ->select(
                'R.RecetaID',
                'R.ConsultaID',
                'R.CodigoCanje',
                'R.NombreMedicamento',
                'R.Dosis',
                'R.Indicaciones',
                'R.FechaEmision',
                DB::raw("COALESCE(R.EstadoReceta, 'Emitida') as EstadoReceta"),
                DB::raw("COALESCE(CONCAT(P.Nombre, ' ', P.Apellido), 'Paciente General') as Paciente"),
                DB::raw("COALESCE(P.DNI, 'N/A') as PacienteDNI"),
                DB::raw("COALESCE(CONCAT(D.Nombre, ' ', D.Apellido), 'Médico General') as MedicoTratante")
            )
            ->where('R.YaCanjeada', $yaCanjeada);

        if ($farmaciaId && $yaCanjeada === 1) {
            $pedidosQuery->where('R.FarmaciaID', $farmaciaId);
        }

        $pedidosPaginados = $pedidosQuery
            ->orderBy($yaCanjeada === 1 ? 'R.FechaSurtido' : 'R.FechaEmision', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'inventario_activo'  => DB::table('CatalogoMedicamentos')->count(),
            'recetas_pendientes' => $queryRecetas->count(),
            'alertas_stock'      => 0,
            'facturacion_diaria' => (float) ($queryVentas->sum('PrecioTotal') ?? 0),
            'pedidos_pendientes' => $pedidosPaginados->items(),
            'pagination'         => [
                'current_page' => $pedidosPaginados->currentPage(),
                'last_page'    => $pedidosPaginados->lastPage(),
                'per_page'     => $pedidosPaginados->perPage(),
                'total'        => $pedidosPaginados->total(),
            ]
        ];
    }

    public function findPendingOrders(array $filters = []): array
    {
        return DB::table('Recetas as R')
            ->leftJoin('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
            ->leftJoin('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
            ->leftJoin('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
            ->select(
                'R.RecetaID',
                'R.CodigoCanje',
                'R.NombreMedicamento',
                'R.EstadoReceta',
                DB::raw("COALESCE(CONCAT(P.Nombre, ' ', P.Apellido), 'Paciente General') as Paciente")
            )
            ->where('R.YaCanjeada', 0)
            ->get()
            ->toArray();
    }

    public function findByBarcode(string $code): ?PrescriptionOrder
    {
        $row = DB::table('Recetas as R')
            ->leftJoin('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
            ->leftJoin('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
            ->leftJoin('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
            ->select(
                'R.RecetaID',
                'R.CodigoCanje',
                'R.NombreMedicamento',
                'R.EstadoReceta',
                DB::raw("COALESCE(CONCAT(P.Nombre, ' ', P.Apellido), 'Paciente General') as Paciente")
            )
            ->where('R.CodigoCanje', $code)
            ->first();

        if (!$row) return null;

        return new PrescriptionOrder(
            id: (string) $row->RecetaID,
            patientName: $row->Paciente,
            status: $row->EstadoReceta ?? 'Emitida',
            medications: [$row->NombreMedicamento],
            barcode: $row->CodigoCanje
        );
    }

    public function saveOrder(PrescriptionOrder $order): void
    {
        DB::table('Recetas')->where('RecetaID', $order->getId())->update([
            'EstadoReceta' => $order->getStatus()
        ]);
    }

    public function searchByCriteria(string $criterio): array
    {
        $results = DB::table('Recetas as R')
            ->leftJoin('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
            ->leftJoin('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
            ->leftJoin('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
            ->leftJoin('Doctores as D', 'C.DoctorID', '=', 'D.DoctorID')
            ->select(
                'R.RecetaID',
                'R.ConsultaID',
                'R.CodigoCanje',
                'R.NombreMedicamento',
                'R.Dosis',
                'R.Indicaciones',
                'R.EstadoReceta',
                'R.YaCanjeada',
                'R.FechaEmision',
                'R.PrecioTotal',
                DB::raw("COALESCE(CONCAT(P.Nombre, ' ', P.Apellido), 'Paciente General') as Paciente"),
                DB::raw("COALESCE(P.DNI, 'N/A') as PacienteDNI"),
                DB::raw("COALESCE(P.Telefono, 'N/A') as PacienteTelefono"),
                DB::raw("COALESCE(CONCAT(D.Nombre, ' ', D.Apellido), 'Médico General') as MedicoTratante"),
                'C.DoctorID',
                'R.FarmaciaID'
            )
            ->where(function ($query) use ($criterio) {
                $query->whereRaw("CAST(R.CodigoCanje AS VARCHAR(36)) = ?", [$criterio])
                    ->orWhere('P.DNI', $criterio);
            })
            ->orderBy('R.FechaEmision', 'DESC')
            ->get();

        return $results->map(fn($row) => $this->mapToEntity($row))->toArray();
    }

    public function findPrescriptionById(int $id): ?Prescription
    {
        $row = DB::table('Recetas as R')
            ->leftJoin('Consultas as CON', 'R.ConsultaID', '=', 'CON.ConsultaID')
            ->leftJoin('Citas as C', 'CON.CitaID', '=', 'C.CitaID')
            ->leftJoin('Pacientes as P', 'C.PacienteID', '=', 'P.PacienteID')
            ->leftJoin('Doctores as D', 'C.DoctorID', '=', 'D.DoctorID')
            ->select(
                'R.RecetaID', 'R.CodigoCanje', 'R.NombreMedicamento', 'R.Dosis',
                'R.Indicaciones', 'R.EstadoReceta', 'R.YaCanjeada', 'R.FechaEmision',
                'R.PrecioTotal',
                DB::raw("COALESCE(CONCAT(P.Nombre, ' ', P.Apellido), 'Paciente General') as Paciente"),
                DB::raw("COALESCE(P.DNI, 'N/A') as PacienteDNI"),
                DB::raw("COALESCE(P.Telefono, 'N/A') as PacienteTelefono"),
                DB::raw("COALESCE(CONCAT(D.Nombre, ' ', D.Apellido), 'Médico General') as MedicoTratante"),
                'C.DoctorID', 'R.FarmaciaID'
            )
            ->where('R.RecetaID', $id)
            ->first();

        return $row ? $this->mapToEntity($row) : null;
    }

    public function updateState(int $id, string $nuevoEstado, int $farmaciaId): bool
    {
        return DB::table('Recetas')
            ->where('RecetaID', $id)
            ->where('YaCanjeada', 0)
            ->update([
                'EstadoReceta' => $nuevoEstado,
                'FarmaciaID'   => $farmaciaId
            ]) > 0;
    }

    public function dispense(Prescription $prescription, float $precioTotal, float $comision, int $farmaciaId): void
    {
        DB::transaction(function () use ($prescription, $precioTotal, $comision, $farmaciaId) {
            DB::table('Recetas')
                ->where('RecetaID', $prescription->getId())
                ->update([
                    'EstadoReceta'  => 'Surtida',
                    'YaCanjeada'    => 1,
                    'FarmaciaID'    => $farmaciaId,
                    'PrecioTotal'   => $precioTotal,
                    'ComisionMonto' => $comision,
                    'FechaSurtido'  => now()
                ]);

            if ($comision > 0) {
                DB::table('Facturacion_SaaS')->insert([
                    'DoctorID'   => $prescription->getDoctorId(),
                    'EntidadID'  => $farmaciaId,
                    'Concepto'   => "Comisión Farmacia (3%) - Receta #{$prescription->getCodigoCanje()}",
                    'Monto'      => $comision,
                    'FechaCargo' => now(),
                    'Estado'     => 'Pendiente'
                ]);
            }
        });
    }

    public function dispenseBulk(array $recetaIds, float $precioTotal, float $comisionTotal, int $farmaciaId): void
    {
        DB::beginTransaction();

        try {
            $primeraReceta = DB::table('Recetas as r')
                ->leftJoin('Consultas as c', 'r.ConsultaID', '=', 'c.ConsultaID')
                ->leftJoin('Citas as ci', 'c.CitaID', '=', 'ci.CitaID')
                ->leftJoin('Pacientes as p', 'ci.PacienteID', '=', 'p.PacienteID')
                ->where('r.RecetaID', $recetaIds[0])
                ->select('p.UsuarioID')
                ->first();

            $usuarioId = ($primeraReceta && $primeraReceta->UsuarioID) ? $primeraReceta->UsuarioID : 1;

            $comisionPorReceta = $comisionTotal / count($recetaIds);
            $precioPorReceta   = $precioTotal / count($recetaIds);

            DB::table('Recetas')
                ->whereIn('RecetaID', $recetaIds)
                ->update([
                    'EstadoReceta'  => 'Surtida',
                    'FarmaciaID'    => $farmaciaId,
                    'PrecioTotal'   => $precioPorReceta,
                    'ComisionMonto' => $comisionPorReceta,
                    'FechaSurtido'  => now(),
                    'YaCanjeada'    => 1
                ]);

            foreach ($recetaIds as $recetaId) {
                DB::statement("EXEC sp_ProcesarPago
                    @UsuarioID = ?,
                    @EntidadID = ?,
                    @ReferenciaID = ?,
                    @TipoConcepto = ?,
                    @MontoTotal = ?,
                    @MetodoPago = ?,
                    @ReferenciaPasarela = ?,
                    @EstadoPago = ?", [
                    $usuarioId,
                    $farmaciaId,
                    $recetaId,
                    'Medicamento',
                    $precioPorReceta,
                    'card',
                    'Farmacia POS',
                    'PROCESADO'
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function mapToEntity(object $row): Prescription
    {
        return new Prescription(
            id: (int) $row->RecetaID,
            codigoCanje: (string) ($row->CodigoCanje ?? ''),
            nombreMedicamento: (string) ($row->NombreMedicamento ?? ''),
            dosis: (string) ($row->Dosis ?? ''),
            indicaciones: (string) ($row->Indicaciones ?? ''),
            estadoReceta: (string) ($row->EstadoReceta ?? 'Emitida'),
            yaCanjeada: (bool) $row->YaCanjeada,
            fechaEmision: (string) ($row->FechaEmision ?? ''),
            precioTotal: $row->PrecioTotal ? (float) $row->PrecioTotal : null,
            pacienteNombre: (string) ($row->Paciente ?? 'Paciente General'),
            pacienteDNI: (string) ($row->PacienteDNI ?? 'N/A'),
            pacienteTelefono: (string) ($row->PacienteTelefono ?? 'N/A'),
            medicoTratante: (string) ($row->MedicoTratante ?? 'Médico General'),
            doctorId: $row->DoctorID ? (int) $row->DoctorID : 0,
            farmaciaId: $row->FarmaciaID ? (int) $row->FarmaciaID : null
        );
    }
}
