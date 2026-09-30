<?php

namespace App\Core\Reports\Infrastructure\Repositories;

use App\Core\Reports\Domain\Ports\StrategicAnalyticsRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SqlStrategicAnalyticsRepository implements StrategicAnalyticsRepositoryInterface
{
    private const PAID_STATUSES = ['PAGADO', 'PROCESADO', 'APROBADO'];

    public function getDashboard(array $filters): array
    {
        $perPage = min(20, max(3, (int) ($filters['per_page'] ?? 5)));
        $entityPage = max(1, (int) ($filters['entity_page'] ?? 1));
        $revenuePage = max(1, (int) ($filters['revenue_page'] ?? 1));
        $clinicalPage = max(1, (int) ($filters['clinical_page'] ?? 1));

        $entityPlanRows = $this->getEntityPlanRows();
        $entityBlocks = $this->groupEntitiesByType($entityPlanRows);
        $revenueRows = $this->getRevenueByEntity($filters);
        $revenueTotals = $this->getRevenueTotals($filters);
        $clinicalRows = $this->getClinicalDelivery($filters);
        $clinicalTrend = $this->getClinicalTrend();
        $revenueTrend = $this->getRevenueTrendAndProjection();
        $targetPlan = $this->getTargetPlan();

        $totalRevenue = round((float) ($revenueTotals->TotalRecaudado ?? 0), 2);
        $consultationRevenue = round((float) ($revenueTotals->RecaudacionConsultas ?? 0), 2);
        $pharmacyRevenue = round((float) ($revenueTotals->RecaudacionFarmacia ?? 0), 2);
        $laboratoryRevenue = round((float) ($revenueTotals->RecaudacionLaboratorio ?? 0), 2);
        $saasRevenue = round((float) ($revenueTotals->RecaudacionSaaS ?? 0), 2);
        $otherRevenue = round(max(0, $totalRevenue - $consultationRevenue - $pharmacyRevenue - $laboratoryRevenue - $saasRevenue), 2);
        $totalPrescriptions = (int) $clinicalRows->sum(fn ($row) => (int) $row->RecetasEmitidas);
        $deliveredPrescriptions = (int) $clinicalRows->sum(fn ($row) => (int) $row->RecetasEntregadas);
        $deliveredExams = (int) $clinicalRows->sum(fn ($row) => (int) $row->ExamenesEntregados);
        $requestedExams = (int) $clinicalRows->sum(fn ($row) => (int) $row->ExamenesSolicitados);
        $transactionCount = (int) ($revenueTotals->CantidadTransacciones ?? 0);
        $consultationsWithOutputs = $clinicalRows->count();
        $totalConsultationsQuery = DB::table('Consultas as coverage_con')
            ->join('Citas as coverage_c', 'coverage_c.CitaID', '=', 'coverage_con.CitaID')
            ->where('coverage_con.Estado', 1);
        $this->applyDateFilters($totalConsultationsQuery, 'coverage_c.FechaHora', $filters);
        $totalConsultations = (int) $totalConsultationsQuery->count();

        $orphanOrdersQuery = DB::table('OrdenesLaboratorio')->whereNull('ConsultaID');
        $this->applyDateFilters($orphanOrdersQuery, 'FechaOrden', $filters);

        $projectionsByType = $entityBlocks->map(function (array $block) use ($targetPlan) {
            $projectedMrr = round($block['TotalActivas'] * (float) $targetPlan['TarifaMensual'], 2);

            return [
                'TipoEntidad' => $block['TipoEntidad'],
                'EntidadesActivas' => $block['TotalActivas'],
                'MRRActual' => $block['MRRActual'],
                'PlanObjetivo' => $targetPlan['NombrePlan'],
                'TarifaObjetivo' => $targetPlan['TarifaMensual'],
                'MRRProyectado' => $projectedMrr,
                'IncrementoPotencial' => round(max(0, $projectedMrr - $block['MRRActual']), 2),
            ];
        })->values()->all();

        return [
            'kpis' => [
                'entidades_activas' => (int) $entityPlanRows->sum(fn ($row) => (int) $row->TotalActivas),
                'mrr_actual' => round((float) $entityPlanRows->sum(fn ($row) => (float) $row->MRRActual), 2),
                'recaudacion_total' => $totalRevenue,
                'recaudacion_consultas' => $consultationRevenue,
                'recaudacion_farmacia' => $pharmacyRevenue,
                'recaudacion_laboratorio' => $laboratoryRevenue,
                'recaudacion_saas' => $saasRevenue,
                'recaudacion_otros' => $otherRevenue,
                'transacciones_cobradas' => $transactionCount,
                'ticket_promedio' => $transactionCount > 0 ? round($totalRevenue / $transactionCount, 2) : 0,
                'recetas_emitidas' => $totalPrescriptions,
                'recetas_entregadas' => $deliveredPrescriptions,
                'tasa_entrega_recetas' => $totalPrescriptions > 0 ? round(($deliveredPrescriptions / $totalPrescriptions) * 100, 1) : 0,
                'examenes_solicitados' => $requestedExams,
                'examenes_entregados' => $deliveredExams,
                'tasa_entrega_examenes' => $requestedExams > 0 ? round(($deliveredExams / $requestedExams) * 100, 1) : 0,
                'cobertura_clinica' => $totalConsultations > 0
                    ? round(($consultationsWithOutputs / $totalConsultations) * 100, 1)
                    : 0,
                'ordenes_sin_consulta' => (int) $orphanOrdersQuery->count(),
                'recaudacion_saas_historica' => round((float) DB::table('HistorialPagosSaaS')->sum('MontoPagado'), 2),
            ],
            'entidades_planes' => $this->paginateCollection($entityBlocks, $entityPage, $perPage),
            'recaudacion_entidades' => $this->paginateCollection($revenueRows, $revenuePage, $perPage),
            'entregas_clinicas' => $this->paginateCollection($clinicalRows, $clinicalPage, $perPage),
            'graficas' => [
                'entidades_por_plan' => $entityPlanRows->values()->all(),
                'recaudacion_top' => $revenueRows->take(10)->values()->all(),
                'entregas_mensuales' => $clinicalTrend,
                'recaudacion_proyeccion' => $revenueTrend,
            ],
            'proyeccion_entidades' => $projectionsByType,
            'interpretaciones' => $this->buildInterpretations(
                $entityPlanRows,
                $revenueRows,
                $clinicalTrend,
                $revenueTrend,
                $totalRevenue,
                $totalPrescriptions,
                $deliveredPrescriptions,
                $requestedExams,
                $deliveredExams
            ),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'target_plan' => $targetPlan,
                'projection_method' => 'Regresión lineal sobre los últimos 6 meses; valores negativos se ajustan a cero.',
                'paid_statuses' => self::PAID_STATUSES,
            ],
        ];
    }

    public function getExecutiveReportData(array $filters): array
    {
        $data = $this->getDashboard(array_merge($filters, [
            'entity_page' => 1,
            'revenue_page' => 1,
            'clinical_page' => 1,
            'per_page' => 20,
        ]));

        $entityBlocks = $this->groupEntitiesByType($this->getEntityPlanRows());
        $revenueRows = $this->getRevenueByEntity($filters);
        $clinicalRows = $this->getClinicalDelivery($filters);

        $data['entidades_planes'] = [
            'data' => $entityBlocks->values()->all(),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $entityBlocks->count(), 'total' => $entityBlocks->count()],
        ];
        $data['recaudacion_entidades'] = [
            'data' => $revenueRows->values()->all(),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $revenueRows->count(), 'total' => $revenueRows->count()],
        ];
        $data['entregas_clinicas'] = [
            'data' => $clinicalRows->values()->all(),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $clinicalRows->count(), 'total' => $clinicalRows->count()],
        ];
        $data['periodo'] = [
            'desde' => $filters['fecha_inicio'] ?? null,
            'hasta' => $filters['fecha_fin'] ?? null,
        ];

        return $data;
    }

    private function getEntityPlanRows(): Collection
    {
        /*
         * UsuarioID no identifica necesariamente a una entidad. Para Doctor el
         * titular es UsuarioID; para los prestadores institucionales es EntidadID.
         * La migración materializa también el plan Gratis, por lo que aquí no se
         * inventan filas ni se infiere un plan por ausencia de suscripción.
         */
        $latestDoctors = DB::table('Sistema_Suscripciones_SaaS as sd')
            ->selectRaw("sd.TipoSuscriptor, sd.UsuarioID as TitularID, MAX(sd.SuscripcionSaaSID) as SuscripcionSaaSID")
            ->where('sd.TipoSuscriptor', 'Doctor')
            ->whereNotNull('sd.UsuarioID')
            ->groupBy('sd.TipoSuscriptor', 'sd.UsuarioID');

        $latestInstitutions = DB::table('Sistema_Suscripciones_SaaS as se')
            ->selectRaw("se.TipoSuscriptor, se.EntidadID as TitularID, MAX(se.SuscripcionSaaSID) as SuscripcionSaaSID")
            ->whereIn('se.TipoSuscriptor', ['Clinica', 'Farmacia', 'Laboratorio'])
            ->whereNotNull('se.EntidadID')
            ->groupBy('se.TipoSuscriptor', 'se.EntidadID');

        $latestSubscribers = $latestDoctors->unionAll($latestInstitutions);

        $realSubscriptions = DB::query()
            ->fromSub($latestSubscribers, 'titulares')
            ->join('Sistema_Suscripciones_SaaS as ss', 'ss.SuscripcionSaaSID', '=', 'titulares.SuscripcionSaaSID')
            ->join('PlanesSaaS as ps', 'ps.NombrePlan', '=', 'ss.TipoPlan')
            ->where('ps.Estado', 1)
            ->whereIn(DB::raw("UPPER(LTRIM(RTRIM(ss.EstadoSuscripcion)))"), ['ACTIVO', 'ACTIVA', 'VIGENTE'])
            ->where(function (Builder $query) {
                $query->whereNull('ss.FechaVencimiento')
                    ->orWhere('ss.FechaVencimiento', '>=', now());
            })
            ->selectRaw('titulares.TipoSuscriptor as TipoEntidad')
            ->addSelect('ss.TipoPlan as NombrePlan')
            ->selectRaw('COUNT(*) as TotalActivas')
            ->selectRaw('SUM(ps.TarifaMensual) as MRRActual')
            ->groupBy('titulares.TipoSuscriptor', 'ss.TipoPlan')
            ->orderBy('titulares.TipoSuscriptor')
            ->orderBy('ss.TipoPlan')
            ->get();

        $subscriberTypes = collect(['Doctor', 'Clinica', 'Farmacia', 'Laboratorio']);

        $activePlans = DB::table('PlanesSaaS')
            ->where('Estado', 1)
            ->orderBy('TarifaMensual')
            ->orderBy('NombrePlan')
            ->get(['NombrePlan', 'TarifaMensual']);

        return $subscriberTypes->flatMap(function (string $subscriberType) use ($activePlans, $realSubscriptions) {
            return $activePlans->map(function ($plan) use ($subscriberType, $realSubscriptions) {
                $real = $realSubscriptions->first(function ($row) use ($subscriberType, $plan) {
                    return mb_strtolower(trim((string) $row->TipoEntidad)) === mb_strtolower(trim($subscriberType))
                        && mb_strtolower(trim((string) $row->NombrePlan)) === mb_strtolower(trim((string) $plan->NombrePlan));
                });

                return (object) [
                    'TipoEntidad' => $subscriberType,
                    'NombrePlan' => (string) $plan->NombrePlan,
                    'TotalActivas' => (int) ($real->TotalActivas ?? 0),
                    'MRRActual' => round((float) ($real->MRRActual ?? 0), 2),
                ];
            });
        })->values();
    }

    private function groupEntitiesByType(Collection $rows): Collection
    {
        return $rows
            ->groupBy('TipoEntidad')
            ->map(function (Collection $plans, string $type) {
                return [
                    'TipoEntidad' => $type,
                    'TotalActivas' => (int) $plans->sum(fn ($row) => (int) $row->TotalActivas),
                    'MRRActual' => round((float) $plans->sum(fn ($row) => (float) $row->MRRActual), 2),
                    'Planes' => $plans->map(fn ($row) => [
                        'NombrePlan' => $row->NombrePlan,
                        'EntidadesActivas' => (int) $row->TotalActivas,
                        'MRR' => round((float) $row->MRRActual, 2),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('TotalActivas')
            ->values();
    }

    private function getRevenueByEntity(array $filters): Collection
    {
        $query = DB::table('Detalle_Pagos as dp')
            ->join('Pagos as p', 'p.PagoID', '=', 'dp.PagoID')
            ->join('Entidades as e', 'e.EntidadID', '=', 'dp.EntidadID')
            ->whereIn(DB::raw("UPPER(LTRIM(RTRIM(ISNULL(p.EstadoPago, ''))))"), self::PAID_STATUSES)
            ->select([
                'e.EntidadID',
                'e.NombreEntidad',
                DB::raw("ISNULL(NULLIF(LTRIM(RTRIM(e.TipoEntidad)), ''), 'Otro') as TipoEntidad"),
                DB::raw('SUM(dp.MontoSubtotal) as TotalRecaudado'),
                DB::raw('COUNT(DISTINCT p.PagoID) as CantidadTransacciones'),
                DB::raw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'CONSULTA' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionConsultas"),
                DB::raw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'MEDICAMENTO' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionFarmacia"),
                DB::raw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'ESTUDIO' OR UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) LIKE '%LAB%' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionLaboratorio"),
                DB::raw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) LIKE '%SUSCRIPCION%' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionSaaS"),
                DB::raw('MAX(p.FechaPago) as UltimoPago'),
            ])
            ->groupBy('e.EntidadID', 'e.NombreEntidad', 'e.TipoEntidad')
            ->orderByDesc('TotalRecaudado');

        $this->applyDateFilters($query, 'p.FechaPago', $filters);

        return $query->get();
    }

    private function getRevenueTotals(array $filters): object
    {
        $query = DB::table('Detalle_Pagos as dp')
            ->join('Pagos as p', 'p.PagoID', '=', 'dp.PagoID')
            ->whereIn(DB::raw("UPPER(LTRIM(RTRIM(ISNULL(p.EstadoPago, ''))))"), self::PAID_STATUSES)
            ->selectRaw('SUM(dp.MontoSubtotal) as TotalRecaudado')
            ->selectRaw('COUNT(DISTINCT p.PagoID) as CantidadTransacciones')
            ->selectRaw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'CONSULTA' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionConsultas")
            ->selectRaw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'MEDICAMENTO' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionFarmacia")
            ->selectRaw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) = 'ESTUDIO' OR UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) LIKE '%LAB%' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionLaboratorio")
            ->selectRaw("SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(dp.TipoConcepto, '')))) LIKE '%SUSCRIPCION%' THEN dp.MontoSubtotal ELSE 0 END) as RecaudacionSaaS");

        $this->applyDateFilters($query, 'p.FechaPago', $filters);

        return $query->first() ?? (object) [];
    }

    private function getClinicalDelivery(array $filters): Collection
    {
        $deliveredPrescription = "r.YaCanjeada = 1 OR r.FechaSurtido IS NOT NULL OR UPPER(ISNULL(r.EstadoReceta, '')) IN ('SURTIDA', 'ENTREGADA', 'CANJEADA')";
        $prescriptionKey = "COALESCE(CONVERT(varchar(36), r.CodigoCanje), CONCAT('REC-', r.RecetaID))";

        $prescriptions = DB::table('Recetas as r')
            ->where('r.Estado', 1)
            ->select([
                'r.ConsultaID',
                DB::raw("COUNT(DISTINCT $prescriptionKey) as RecetasEmitidas"),
                DB::raw("COUNT(DISTINCT CASE WHEN $deliveredPrescription THEN $prescriptionKey END) as RecetasEntregadas"),
                DB::raw('COUNT(r.RecetaID) as MedicamentosRecetados'),
            ])
            ->groupBy('r.ConsultaID');

        $deliveredLab = "ol.FechaCompletado IS NOT NULL OR ol.ArchivoPdfPath IS NOT NULL OR UPPER(ISNULL(ol.Estado, '')) IN ('COMPLETADA', 'ENTREGADA', 'FINALIZADA')";
        $labs = DB::table('OrdenesLaboratorio as ol')
            ->leftJoin('OrdenExamenDetalle as oed', 'oed.OrdenID', '=', 'ol.OrdenID')
            ->whereNotNull('ol.ConsultaID')
            ->select([
                'ol.ConsultaID',
                DB::raw('COUNT(DISTINCT ol.OrdenID) as OrdenesEmitidas'),
                DB::raw("COUNT(DISTINCT CASE WHEN $deliveredLab THEN ol.OrdenID END) as OrdenesEntregadas"),
                DB::raw('COUNT(oed.DetalleID) as ExamenesSolicitados'),
                DB::raw("COUNT(CASE WHEN $deliveredLab THEN oed.DetalleID END) as ExamenesEntregados"),
            ])
            ->groupBy('ol.ConsultaID');

        $query = DB::table('Consultas as con')
            ->join('Citas as c', 'c.CitaID', '=', 'con.CitaID')
            ->join('Pacientes as pa', 'pa.PacienteID', '=', 'c.PacienteID')
            ->join('Doctores as d', 'd.DoctorID', '=', 'c.DoctorID')
            ->leftJoin('Entidades as e', 'e.EntidadID', '=', 'c.EntidadID')
            ->leftJoinSub($prescriptions, 'rx', fn ($join) => $join->on('rx.ConsultaID', '=', 'con.ConsultaID'))
            ->leftJoinSub($labs, 'lab', fn ($join) => $join->on('lab.ConsultaID', '=', 'con.ConsultaID'))
            ->where(function ($q) {
                $q->whereRaw('ISNULL(rx.RecetasEmitidas, 0) > 0')
                    ->orWhereRaw('ISNULL(lab.OrdenesEmitidas, 0) > 0');
            })
            ->select([
                'con.ConsultaID',
                'c.CitaID',
                'c.FechaHora',
                'con.Diagnostico',
                DB::raw("LTRIM(RTRIM(CONCAT(d.Nombre, ' ', d.Apellido))) as Doctor"),
                DB::raw("LTRIM(RTRIM(CONCAT(pa.Nombre, ' ', pa.Apellido))) as Paciente"),
                'e.NombreEntidad as Entidad',
                DB::raw('ISNULL(rx.RecetasEmitidas, 0) as RecetasEmitidas'),
                DB::raw('ISNULL(rx.RecetasEntregadas, 0) as RecetasEntregadas'),
                DB::raw('ISNULL(rx.MedicamentosRecetados, 0) as MedicamentosRecetados'),
                DB::raw('ISNULL(lab.OrdenesEmitidas, 0) as OrdenesEmitidas'),
                DB::raw('ISNULL(lab.OrdenesEntregadas, 0) as OrdenesEntregadas'),
                DB::raw('ISNULL(lab.ExamenesSolicitados, 0) as ExamenesSolicitados'),
                DB::raw('ISNULL(lab.ExamenesEntregados, 0) as ExamenesEntregados'),
            ])
            ->orderByDesc('c.FechaHora');

        $this->applyDateFilters($query, 'c.FechaHora', $filters);

        return $query->get();
    }

    private function getClinicalTrend(): array
    {
        $months = $this->lastMonths(6);
        $prescriptionKey = "COALESCE(CONVERT(varchar(36), CodigoCanje), CONCAT('REC-', RecetaID))";
        $prescriptions = DB::table('Recetas')
            ->where('Estado', 1)
            ->where('FechaEmision', '>=', $months->first()['start'])
            ->selectRaw("YEAR(FechaEmision) as Anio, MONTH(FechaEmision) as Mes, COUNT(DISTINCT $prescriptionKey) as Emitidas")
            ->selectRaw("COUNT(DISTINCT CASE WHEN YaCanjeada = 1 OR FechaSurtido IS NOT NULL OR UPPER(ISNULL(EstadoReceta, '')) IN ('SURTIDA', 'ENTREGADA', 'CANJEADA') THEN $prescriptionKey END) as Entregadas")
            ->groupByRaw('YEAR(FechaEmision), MONTH(FechaEmision)')
            ->get()
            ->keyBy(fn ($row) => sprintf('%04d-%02d', $row->Anio, $row->Mes));

        $labs = DB::table('OrdenesLaboratorio as ol')
            ->leftJoin('OrdenExamenDetalle as oed', 'oed.OrdenID', '=', 'ol.OrdenID')
            ->where('ol.FechaOrden', '>=', $months->first()['start'])
            ->selectRaw('YEAR(ol.FechaOrden) as Anio, MONTH(ol.FechaOrden) as Mes, COUNT(oed.DetalleID) as Solicitados')
            ->selectRaw("COUNT(CASE WHEN ol.FechaCompletado IS NOT NULL OR ol.ArchivoPdfPath IS NOT NULL OR UPPER(ISNULL(ol.Estado, '')) IN ('COMPLETADA', 'ENTREGADA', 'FINALIZADA') THEN oed.DetalleID END) as Entregados")
            ->groupByRaw('YEAR(ol.FechaOrden), MONTH(ol.FechaOrden)')
            ->get()
            ->keyBy(fn ($row) => sprintf('%04d-%02d', $row->Anio, $row->Mes));

        return $months->map(function (array $month) use ($prescriptions, $labs) {
            $rx = $prescriptions->get($month['key']);
            $lab = $labs->get($month['key']);

            return [
                'Periodo' => $month['key'],
                'Etiqueta' => $month['label'],
                'RecetasEmitidas' => (int) ($rx->Emitidas ?? 0),
                'RecetasEntregadas' => (int) ($rx->Entregadas ?? 0),
                'ExamenesSolicitados' => (int) ($lab->Solicitados ?? 0),
                'ExamenesEntregados' => (int) ($lab->Entregados ?? 0),
            ];
        })->all();
    }

    private function getRevenueTrendAndProjection(): array
    {
        $months = $this->lastMonths(6);
        $actualRows = DB::table('Detalle_Pagos as dp')
            ->join('Pagos as p', 'p.PagoID', '=', 'dp.PagoID')
            ->whereIn(DB::raw("UPPER(LTRIM(RTRIM(ISNULL(p.EstadoPago, ''))))"), self::PAID_STATUSES)
            ->where('p.FechaPago', '>=', $months->first()['start'])
            ->selectRaw('YEAR(p.FechaPago) as Anio, MONTH(p.FechaPago) as Mes, SUM(dp.MontoSubtotal) as Total')
            ->groupByRaw('YEAR(p.FechaPago), MONTH(p.FechaPago)')
            ->get()
            ->keyBy(fn ($row) => sprintf('%04d-%02d', $row->Anio, $row->Mes));

        $values = $months->map(fn (array $month) => (float) ($actualRows->get($month['key'])->Total ?? 0))->values();
        [$slope, $intercept] = $this->linearRegression($values->all());

        $result = $months->map(function (array $month, int $index) use ($values) {
            return [
                'Periodo' => $month['key'],
                'Etiqueta' => $month['label'],
                'Real' => round((float) $values[$index], 2),
                'Proyectado' => null,
                'EsProyeccion' => false,
            ];
        });

        $lastMonth = Carbon::parse($months->last()['start']);
        for ($future = 1; $future <= 3; $future++) {
            $date = $lastMonth->copy()->addMonths($future);
            $x = $values->count() + $future;
            $result->push([
                'Periodo' => $date->format('Y-m'),
                'Etiqueta' => ucfirst($date->locale('es')->translatedFormat('M Y')),
                'Real' => null,
                'Proyectado' => round(max(0, $intercept + ($slope * $x)), 2),
                'EsProyeccion' => true,
            ]);
        }

        return $result->all();
    }

    private function getTargetPlan(): array
    {
        $plan = DB::table('PlanesSaaS')
            ->where('Estado', 1)
            ->where('TarifaMensual', '>', 0)
            ->orderByDesc('TarifaMensual')
            ->first();

        return [
            'PlanID' => (int) ($plan->PlanID ?? 0),
            'NombrePlan' => (string) ($plan->NombrePlan ?? 'Plan de pago'),
            'TarifaMensual' => round((float) ($plan->TarifaMensual ?? 0), 2),
        ];
    }

    private function buildInterpretations(
        Collection $entityPlanRows,
        Collection $revenueRows,
        array $clinicalTrend,
        array $revenueTrend,
        float $totalRevenue,
        int $prescriptionsIssued,
        int $prescriptionsDelivered,
        int $examsRequested,
        int $examsDelivered
    ): array {
        $totalSubscribers = (int) $entityPlanRows->sum(fn ($row) => (int) $row->TotalActivas);
        $paidSubscribers = (int) $entityPlanRows
            ->filter(fn ($row) => (float) $row->MRRActual > 0)
            ->sum(fn ($row) => (int) $row->TotalActivas);
        $conversionRate = $totalSubscribers > 0 ? round(($paidSubscribers / $totalSubscribers) * 100, 1) : 0;

        $topEntity = $revenueRows->sortByDesc(fn ($row) => (float) $row->TotalRecaudado)->first();
        $topShare = $topEntity && $totalRevenue > 0
            ? round(((float) $topEntity->TotalRecaudado / $totalRevenue) * 100, 1)
            : 0;

        $prescriptionRate = $prescriptionsIssued > 0
            ? round(($prescriptionsDelivered / $prescriptionsIssued) * 100, 1)
            : 0;
        $examRate = $examsRequested > 0
            ? round(($examsDelivered / $examsRequested) * 100, 1)
            : 0;

        $actual = collect($revenueTrend)->where('EsProyeccion', false)->last();
        $future = collect($revenueTrend)->where('EsProyeccion', true)->last();
        $actualAmount = (float) ($actual['Real'] ?? 0);
        $futureAmount = (float) ($future['Proyectado'] ?? 0);
        $projectionChange = $actualAmount > 0
            ? round((($futureAmount - $actualAmount) / $actualAmount) * 100, 1)
            : 0;

        $clinicalLast = collect($clinicalTrend)->last();

        return [
            'suscripciones' => sprintf(
                'MedGo+ registra %d suscriptores activos. %d utilizan un plan con tarifa, equivalente a una conversión comercial de %.1f%%. El resto permanece en planes gratuitos y constituye la principal oportunidad de crecimiento del MRR.',
                $totalSubscribers,
                $paidSubscribers,
                $conversionRate
            ),
            'recaudacion' => $topEntity
                ? sprintf(
                    'La entidad con mayor recaudación es %s con USD %s, equivalente al %.1f%% del total procesado. Una participación elevada indica concentración de ingresos y recomienda diversificar la actividad entre más prestadores.',
                    $topEntity->NombreEntidad,
                    number_format((float) $topEntity->TotalRecaudado, 2, '.', ','),
                    $topShare
                )
                : 'No existen cobros aprobados en el período seleccionado; no es posible evaluar concentración de ingresos.',
            'entregas_clinicas' => sprintf(
                'La tasa acumulada de entrega es %.1f%% para recetas y %.1f%% para exámenes. En el último mes analizado se registraron %d recetas entregadas y %d exámenes entregados. Los pendientes deben revisarse por médico, paciente y entidad.',
                $prescriptionRate,
                $examRate,
                (int) ($clinicalLast['RecetasEntregadas'] ?? 0),
                (int) ($clinicalLast['ExamenesEntregados'] ?? 0)
            ),
            'proyeccion' => sprintf(
                'La tendencia lineal proyecta USD %s para el tercer mes futuro, una variación de %.1f%% respecto al último mes real de USD %s. Es una estimación estadística y no sustituye un presupuesto aprobado.',
                number_format($futureAmount, 2, '.', ','),
                $projectionChange,
                number_format($actualAmount, 2, '.', ',')
            ),
        ];
    }

    private function applyDateFilters(Builder $query, string $column, array $filters): void
    {
        if (!empty($filters['fecha_inicio'])) {
            $query->where($column, '>=', $filters['fecha_inicio'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_fin'])) {
            $query->where($column, '<=', $filters['fecha_fin'] . ' 23:59:59.997');
        }
    }

    private function paginateCollection(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);

        return [
            'data' => $items->forPage($currentPage, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function lastMonths(int $count): Collection
    {
        $start = now()->startOfMonth()->subMonths($count - 1);

        return collect(range(0, $count - 1))->map(function (int $offset) use ($start) {
            $date = $start->copy()->addMonths($offset);

            return [
                'key' => $date->format('Y-m'),
                'label' => ucfirst($date->locale('es')->translatedFormat('M Y')),
                'start' => $date->toDateTimeString(),
            ];
        });
    }

    private function linearRegression(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return [0.0, 0.0];
        }

        $sumX = $sumY = $sumXY = $sumX2 = 0.0;
        foreach ($values as $index => $value) {
            $x = $index + 1;
            $sumX += $x;
            $sumY += $value;
            $sumXY += $x * $value;
            $sumX2 += $x * $x;
        }

        $denominator = ($count * $sumX2) - ($sumX * $sumX);
        $slope = $denominator === 0.0 ? 0.0 : (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $count;

        return [$slope, $intercept];
    }
}
