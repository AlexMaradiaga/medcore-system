<?php

namespace App\Core\Laboratories\Infrastructure\Repositories;

use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlLaboratoryRepository implements LaboratoryRepositoryInterface
{
    public function getCatalogoExamenes(): array
    {
        return DB::table('CatalogoExamenesLab')
            ->where('Estado', 1)
            ->orderBy('Categoria')
            ->get()
            ->toArray();
    }

    public function getOrdenesPorPaciente(int $pacienteId): array
    {
        $ordenes = DB::table('OrdenesLaboratorio as o')
            ->join('Doctores as d', 'o.DoctorID', '=', 'd.DoctorID')

            ->select(
                'o.OrdenID',
                'o.FechaOrden',
                'o.Estado',
                DB::raw("d.Nombre + ' ' + d.Apellido as MedicoTratante"),
                'o.NotasClinicas'
            )
            ->where('o.PacienteID', $pacienteId)
            ->orderBy('o.FechaOrden', 'desc')
            ->get();
    }

    public function getResultadosPorOrden(int $ordenId): array
    {
        return DB::table('OrdenExamenDetalle as od')
            ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
            ->leftJoin('ResultadosLaboratorio as r', 'od.DetalleID', '=', 'r.DetalleID')
            ->where('od.OrdenID', $ordenId)
            ->select(
                'c.NombreExamen',
                'c.Categoria',
                'od.Estado as EstadoExamen',
                'r.ValorResultado',
                'r.RangoReferencia',
                'r.UnidadMedida',
                'r.BanderaAlerta',
                'r.ArchivoPdfPath'
            )
            ->get()
            ->toArray();
    }
}
