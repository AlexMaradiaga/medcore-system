<?php

namespace App\Core\Laboratories\Infrastructure\Repositories;

use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use PDO;

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

    public function actualizarPrecioExamen(int $examId, float $precio): bool
    {
        return DB::table('CatalogoExamenesLab')
            ->where('ExamID', $examId)
            ->update(['Precio' => $precio]) >= 0;
    }

    public function getOrdenesPorPaciente(int $pacienteId): array
    {
        $pacienteIds = DB::table('Pacientes')
            ->where('PacienteID', $pacienteId)
            ->orWhere('UsuarioID', $pacienteId)
            ->pluck('PacienteID')
            ->toArray();

        if (!in_array($pacienteId, $pacienteIds)) {
            $pacienteIds[] = $pacienteId;
        }

        $ordenes = DB::table('OrdenesLaboratorio as OL')
            ->join('Pacientes as P', 'OL.PacienteID', '=', 'P.PacienteID')
            ->leftJoin('Doctores as D', 'OL.DoctorID', '=', 'D.DoctorID')
            ->whereIn('OL.PacienteID', $pacienteIds)
            ->orWhere('P.UsuarioID', $pacienteId)
            ->select(
                'OL.OrdenID',
                'OL.CodigoOrden',
                'OL.Estado',
                'OL.MontoTotal',
                'OL.ComisionMonto',
                'OL.ArchivoPdfPath',
                'OL.FechaOrden',
                'OL.FechaCompletado',
                DB::raw("TRIM(CONCAT(P.Nombre, ' ', COALESCE(P.Apellido, ''))) as Paciente"),
                'P.DNI as PacienteDNI',
                'P.Telefono as PacienteTelefono',
                DB::raw("COALESCE(TRIM(CONCAT(D.Nombre, ' ', COALESCE(D.Apellido, ''))), 'Solicitud Directa') as Doctor")
            )
            ->orderBy('OL.FechaOrden', 'DESC')
            ->get();

        foreach ($ordenes as $ord) {
            try {
                $examenes = DB::table('OrdenExamenDetalle as od')
                    ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
                    ->where('od.OrdenID', $ord->OrdenID)
                    ->where('od.Estado', '!=', 'Cancelado')
                    ->pluck('c.NombreExamen')
                    ->toArray();
                $ord->Examen = !empty($examenes) ? implode(', ', $examenes) : 'Análisis Clínico';
            } catch (Exception $e) {
                $ord->Examen = 'Análisis Clínico';
            }
        }

        return $ordenes->toArray();
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

    public function obtenerOrdenesOperativas(int $laboratorioId, ?string $estado = null): array
    {
        $query = DB::table('OrdenesLaboratorio as OL')
            ->join('Pacientes as P', 'OL.PacienteID', '=', 'P.PacienteID')
            ->leftJoin('Doctores as D', 'OL.DoctorID', '=', 'D.DoctorID')
            ->select(
                'OL.OrdenID',
                'OL.CodigoOrden',
                'OL.ConsultaID',
                'OL.Estado',
                'OL.MontoTotal',
                'OL.ComisionMonto',
                'OL.NotasClinicas',
                'OL.ArchivoPdfPath',
                'OL.FechaOrden',
                'OL.FechaCompletado',
                DB::raw("TRIM(CONCAT(P.Nombre, ' ', COALESCE(P.Apellido, ''))) as Paciente"),
                'P.DNI as PacienteDNI',
                'P.Telefono as PacienteTelefono',
                DB::raw("COALESCE(TRIM(CONCAT(D.Nombre, ' ', COALESCE(D.Apellido, ''))), 'Solicitud Directa') as Doctor")
            )
            ->where('OL.LaboratorioID', $laboratorioId);

        if ($estado && $estado !== 'Todos') {
            $query->where('OL.Estado', $estado);
        }

        $ordenes = $query->orderBy('OL.FechaOrden', 'DESC')->get();

        foreach ($ordenes as $ord) {
            try {
                $detalles = DB::table('OrdenExamenDetalle as od')
                    ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
                    ->where('od.OrdenID', $ord->OrdenID)
                    ->select('c.ExamID', 'c.NombreExamen', 'c.Categoria', 'c.Precio', 'od.Estado')
                    ->get()
                    ->toArray();

                $ord->examenes = $detalles;

                $activos = array_filter($detalles, fn($item) => $item->Estado !== 'Cancelado');
                $nombres = array_map(fn($item) => $item->NombreExamen, $activos);
                $ord->Examen = !empty($nombres) ? implode(', ', $nombres) : 'Análisis Clínico';
            } catch (Exception $e) {
                $ord->examenes = [];
                $ord->Examen = 'Análisis Clínico';
            }
        }

        return $ordenes->toArray();
    }

    public function getExamenesPorOrden(int $ordenId): array
    {
        return DB::table('OrdenExamenDetalle as od')
            ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
            ->where('od.OrdenID', $ordenId)
            ->select('c.ExamID', 'c.NombreExamen', 'c.Categoria', 'c.Precio', 'od.Estado')
            ->get()
            ->toArray();
    }

    public function aceptarOrden(int $ordenId): bool
    {
        return DB::table('OrdenesLaboratorio')
            ->where('OrdenID', $ordenId)
            ->where('Estado', 'Emitida')
            ->update(['Estado' => 'Aceptada']) > 0;
    }

    public function validarQR(string $codigoOrden, int $laboratorioId): array
    {
        $orden = DB::table('OrdenesLaboratorio')
            ->where('CodigoOrden', $codigoOrden)
            ->where('LaboratorioID', $laboratorioId)
            ->first();

        if (!$orden) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Código QR / Orden no encontrada para este laboratorio.'
            ];
        }

        if ($orden->Estado === 'Completada') {
            return [
                'status'  => 'warning',
                'code'    => 400,
                'message' => 'Esta orden ya fue procesada y completada anteriormente.'
            ];
        }

        DB::table('OrdenesLaboratorio')
            ->where('OrdenID', $orden->OrdenID)
            ->update(['Estado' => 'Paciente Recibido']);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Recepción de paciente validada correctamente.',
            'orden'   => $orden
        ];
    }

    public function subirResultadosPDF(int $ordenId, UploadedFile $archivoPdf): array
    {
        $orden = DB::table('OrdenesLaboratorio')->where('OrdenID', $ordenId)->first();

        if (!$orden) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Orden no encontrada.'
            ];
        }

        if (!Storage::disk('public')->exists('laboratorios/resultados')) {
            Storage::disk('public')->makeDirectory('laboratorios/resultados');
        }

        $pathPdf = $archivoPdf->store('laboratorios/resultados', 'public');
        $montoTotal = floatval($orden->MontoTotal ?? 0);
        $comisionCalculada = round($montoTotal * 0.05, 2);

        DB::beginTransaction();
        try {
            DB::table('OrdenesLaboratorio')
                ->where('OrdenID', $ordenId)
                ->update([
                    'Estado'          => 'Completada',
                    'ArchivoPdfPath'  => $pathPdf,
                    'ComisionMonto'   => $comisionCalculada,
                    'FechaCompletado' => now()
                ]);

            if ($comisionCalculada > 0) {
                try {
                    $payloadFactura = [
                        'Concepto'   => "Comisión 5% Laboratorio - Orden " . ($orden->CodigoOrden ?? "ORD-{$ordenId}"),
                        'Monto'      => $comisionCalculada,
                        'FechaCargo' => now(),
                        'Estado'     => 'Pendiente'
                    ];
                    if (!empty($orden->DoctorID)) {
                        $payloadFactura['DoctorID'] = $orden->DoctorID;
                    }
                    if (!empty($orden->LaboratorioID)) {
                        $payloadFactura['LaboratorioID'] = $orden->LaboratorioID;
                    }
                    DB::table('Facturacion_SaaS')->insert($payloadFactura);
                } catch (Exception $eFactura) {
                    logger()->warning("Aviso Facturación SaaS: " . $eFactura->getMessage());
                }
            }
            DB::commit();

            return [
                'status'            => 'success',
                'code'              => 200,
                'message'           => 'Resultados adjuntados exitosamente. Orden finalizada.',
                'comision_generada' => $comisionCalculada,
                'pdf_url'           => Storage::url($pathPdf)
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function crearSolicitudDigital(array $datos, ?object $authUser = null): array
    {
        $idEntrante = $datos['paciente_id'] ?? $authUser?->UsuarioID ?? $authUser?->id;
        $pacienteIdReal = null;
        if ($idEntrante) {
            $pacienteIdReal = DB::table('Pacientes')
                ->where('UsuarioID', $idEntrante)
                ->value('PacienteID')
                ?? DB::table('Pacientes')
                    ->where('PacienteID', $idEntrante)
                    ->value('PacienteID');
        }
        $pacienteIdFinal = $pacienteIdReal ?? 1;

        $doctorIdEntrante = $datos['doctor_id'] ?? null;
        $doctorIdReal = null;

        if ($doctorIdEntrante) {
            $doctorIdReal = DB::table('Doctores')
                ->where('DoctorID', $doctorIdEntrante)
                ->value('DoctorID')
                ?? DB::table('Doctores')
                    ->where('UsuarioID', $doctorIdEntrante)
                    ->value('DoctorID');
        }

        if (!$doctorIdReal && $authUser) {
            $usuarioAuthId = $authUser->UsuarioID ?? $authUser->id;
            $doctorIdReal = DB::table('Doctores')
                ->where('UsuarioID', $usuarioAuthId)
                ->value('DoctorID');
        }

        return DB::transaction(function () use ($datos, $pacienteIdFinal, $doctorIdReal) {
            $codigoOrden = 'ORD-' . date('Y') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $ordenId = DB::table('OrdenesLaboratorio')->insertGetId([
                'CodigoOrden'   => $codigoOrden,
                'PacienteID'    => $pacienteIdFinal,
                'LaboratorioID' => $datos['laboratorio_id'],
                'DoctorID'      => $doctorIdReal,
                'ConsultaID'    => $datos['consulta_id'] ?? null,
                'NotasClinicas' => $datos['notas_clinicas'] ?? null,
                'Estado'        => 'Emitida',
                'MontoTotal'    => $datos['monto_total'],
                'ComisionMonto' => 0.00,
                'FechaOrden'    => now()
            ]);

            foreach ($datos['examenes'] as $examId) {
                DB::table('OrdenExamenDetalle')->insert([
                    'OrdenID' => $ordenId,
                    'ExamID'  => $examId,
                    'Estado'  => 'Pendiente'
                ]);
            }

            return [
                'codigo_orden' => $codigoOrden,
                'orden_id'     => $ordenId
            ];
        });
    }

    public function getDashboardMetrics(int $laboratorioId): array
    {
        $pdo = DB::connection('sqlsrv')->getPdo();
        $stmt = $pdo->prepare("EXEC sp_ObtenerDashboardLaboratorio ?");
        $stmt->execute([$laboratorioId]);

        $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->nextRowset();
        $ordenesRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $kpisResult = $kpis ? $kpis : [];
        $ordenesRecientesResult = $ordenesRecientes ? $ordenesRecientes : [];

        foreach ($ordenesRecientesResult as &$ord) {
            if (empty($ord['Examen']) && isset($ord['OrdenID'])) {
                try {
                    $examenes = DB::table('OrdenExamenDetalle as od')
                        ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
                        ->where('od.OrdenID', $ord['OrdenID'])
                        ->where('od.Estado', '!=', 'Cancelado')
                        ->pluck('c.NombreExamen')
                        ->toArray();
                    $ord['Examen'] = !empty($examenes) ? implode(', ', $examenes) : 'Análisis Clínico';
                } catch (Exception $e) {
                    $ord['Examen'] = 'Análisis Clínico';
                }
            }
        }

        return [
            'kpis'              => $kpisResult,
            'ordenes_recientes' => $ordenesRecientesResult
        ];
    }

    public function actualizarExamenesOrden(int $ordenId, array $examenesSeleccionadosIds): array
    {
        return DB::transaction(function () use ($ordenId, $examenesSeleccionadosIds) {
            $detalles = DB::table('OrdenExamenDetalle')->where('OrdenID', $ordenId)->get();

            foreach ($detalles as $det) {
                $nuevoEstado = in_array($det->ExamID, $examenesSeleccionadosIds) ? 'Pendiente' : 'Cancelado';

                DB::table('OrdenExamenDetalle')
                    ->where('DetalleID', $det->DetalleID)
                    ->update(['Estado' => $nuevoEstado]);
            }

            $nuevoMonto = (float) DB::table('OrdenExamenDetalle as od')
                ->join('CatalogoExamenesLab as c', 'od.ExamID', '=', 'c.ExamID')
                ->where('od.OrdenID', $ordenId)
                ->whereIn('od.Estado', ['Pendiente', 'Realizado'])
                ->sum('c.Precio');

            DB::table('OrdenesLaboratorio')
                ->where('OrdenID', $ordenId)
                ->update(['MontoTotal' => $nuevoMonto]);

            return [
                'status'      => 'success',
                'monto_total' => $nuevoMonto,
                'message'     => 'Exámenes y total de la orden actualizados correctamente.'
            ];
        });
    }
}
