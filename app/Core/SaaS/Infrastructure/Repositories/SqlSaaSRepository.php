<?php

namespace App\Core\SaaS\Infrastructure\Repositories;

use App\Core\SaaS\Domain\Ports\SaaSRepositoryInterface;
use App\Events\SaaS\PaymentReceived;
use App\Events\SaaS\PlanActivated;
use Illuminate\Support\Facades\DB;
use PDO;

class SqlSaaSRepository implements SaaSRepositoryInterface
{
    public function actualizarPlan(int $usuarioId, string $tipoPlan, int $diasVigencia, string $tokenPasarela): bool
    {
        $plan = DB::table('PlanesSaaS')
            ->where('NombrePlan', $tipoPlan)
            ->where('Estado', 1)
            ->first();

        if (!$plan) {
            throw new \RuntimeException('El plan seleccionado no existe o no está activo.');
        }

        $titular = $this->resolverTitular($usuarioId);
        $monto = round((float) $plan->TarifaMensual, 2);

        return DB::transaction(function () use ($usuarioId, $titular, $plan, $diasVigencia, $monto, $tokenPasarela) {
            $pagoId = DB::table('Pagos')->insertGetId([
                'UsuarioID' => $usuarioId,
                'MontoTotal' => $monto,
                'MetodoPago' => 'card',
                'ReferenciaPasarela' => $tokenPasarela,
                'EstadoPago' => 'PROCESADO',
                'FechaPago' => now(),
            ], 'PagoID');

            DB::table('Detalle_Pagos')->insert([
                'PagoID' => $pagoId,
                'EntidadID' => $titular['entidad_id'],
                'TipoConcepto' => 'SuscripcionSaaS',
                'ReferenciaID' => $plan->PlanID,
                'MontoSubtotal' => $monto,
            ]);

            $subscriptionQuery = DB::table('Sistema_Suscripciones_SaaS')
                ->where('TipoSuscriptor', $titular['tipo']);

            $titular['tipo'] === 'Doctor'
                ? $subscriptionQuery->where('UsuarioID', $usuarioId)
                : $subscriptionQuery->where('EntidadID', $titular['entidad_id']);

            $existing = $subscriptionQuery->orderByDesc('SuscripcionSaaSID')->first();
            $subscriptionData = [
                'EntidadID' => $titular['entidad_id'],
                'UsuarioID' => $usuarioId,
                'TipoSuscriptor' => $titular['tipo'],
                'TipoPlan' => $plan->NombrePlan,
                'EstadoSuscripcion' => 'ACTIVA',
                'FechaVencimiento' => $monto > 0 ? now()->addDays($diasVigencia) : '9999-12-31 00:00:00',
                'TokenPasarela' => $tokenPasarela,
                'ActualizadoEn' => now(),
            ];

            if ($existing) {
                DB::table('Sistema_Suscripciones_SaaS')
                    ->where('SuscripcionSaaSID', $existing->SuscripcionSaaSID)
                    ->update($subscriptionData);
                $suscripcionId = (int) $existing->SuscripcionSaaSID;
            } else {
                $suscripcionId = (int) DB::table('Sistema_Suscripciones_SaaS')
                    ->insertGetId($subscriptionData, 'SuscripcionSaaSID');
            }

            DB::table('HistorialPagosSaaS')->insert([
                'PagoID' => $pagoId,
                'UsuarioID' => $usuarioId,
                'EntidadID' => $titular['entidad_id'],
                'SuscripcionSaaSID' => $suscripcionId,
                'TipoSuscriptor' => $titular['tipo'],
                'PlanID' => $plan->PlanID,
                'MontoPagado' => $monto,
                'TokenPasarela' => $tokenPasarela,
                'FechaPago' => now(),
            ]);

            if ($titular['tipo'] === 'Doctor') {
                DB::table('Membresia')->updateOrInsert(
                    ['UsuarioID' => $usuarioId],
                    [
                        'TipoPlan' => $plan->NombrePlan,
                        'Estado' => 1,
                        'FechaExpiracion' => $monto > 0 ? now()->addDays($diasVigencia) : '9999-12-31 00:00:00',
                        'TokenPasarela' => $tokenPasarela,
                    ]
                );
            }

            $metadata = ['tipo_suscriptor' => $titular['tipo'], 'plan_id' => (int) $plan->PlanID];
            PaymentReceived::dispatch($usuarioId, $titular['entidad_id'], $monto, $tokenPasarela, 'Pasarela_Tarjetas', $metadata);
            PlanActivated::dispatch($usuarioId, $titular['entidad_id'], $plan->NombrePlan, $diasVigencia, $metadata);

            return true;
        });
    }

    public function obtenerPlanes(int $usuarioId): array
    {
        $titular = $this->resolverTitular($usuarioId);
        $query = DB::table('Sistema_Suscripciones_SaaS')
            ->where('TipoSuscriptor', $titular['tipo']);

        $titular['tipo'] === 'Doctor'
            ? $query->where('UsuarioID', $usuarioId)
            : $query->where('EntidadID', $titular['entidad_id']);

        $suscripcion = $query->orderByDesc('SuscripcionSaaSID')->first();

        return [
            'tipo_suscriptor' => $titular['tipo'],
            'entidad_id' => $titular['entidad_id'],
            'plan_actual' => $suscripcion->TipoPlan ?? null,
            'estado_suscripcion' => $suscripcion->EstadoSuscripcion ?? null,
            'fecha_vencimiento' => $suscripcion->FechaVencimiento ?? null,
            'planes' => DB::table('PlanesSaaS')
                ->where('Estado', 1)
                ->orderBy('TarifaMensual')
                ->orderBy('NombrePlan')
                ->get(['PlanID', 'NombrePlan', 'TarifaMensual', 'Descripcion'])
                ->all(),
        ];
    }

    private function resolverTitular(int $usuarioId): array
    {
        $usuario = DB::table('Usuarios')->where('UsuarioID', $usuarioId)->where('Estado', 1)->first();
        if (!$usuario) {
            throw new \RuntimeException('No se encontró el usuario autenticado.');
        }

        $doctor = DB::table('Doctores')->where('UsuarioID', $usuarioId)->where('Estado', 1)->first();
        if ($doctor) {
            return ['tipo' => 'Doctor', 'entidad_id' => null];
        }

        if (!$usuario->EntidadID) {
            throw new \RuntimeException('El usuario no está asociado a un doctor ni a una entidad suscribible.');
        }

        $entidad = DB::table('Entidades')->where('EntidadID', $usuario->EntidadID)->where('Estado', 1)->first();
        if (!$entidad || !in_array($entidad->TipoEntidad, ['Clinica', 'Farmacia', 'Laboratorio'], true)) {
            throw new \RuntimeException('La entidad asociada no es válida para una suscripción SaaS.');
        }

        return ['tipo' => $entidad->TipoEntidad, 'entidad_id' => (int) $entidad->EntidadID];
    }

    public function obtenerMonitoreo(): array
    {
        $pdo = DB::connection('sqlsrv')->getPdo();
        $stmt = $pdo->prepare("EXEC sp_ObtenerMonitoreoSaaS");
        $stmt->execute();

        $data = [];
        $data['kpis'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['MRR' => 0, 'PremiumActivos' => 0, 'PlanesGratis' => 0, 'PorVencer' => 0];
        $stmt->nextRowset();
        $data['suscripciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->nextRowset();
        $data['transacciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();

        return $data;
    }

    public function obtenerPreciosDoctor(int $doctorId): array
    {
        return DB::select("EXEC sp_ObtenerPreciosDoctor @DoctorID = ?", [$doctorId]);
    }

    public function guardarPrecioServicio(array $datos): bool
    {
        return DB::statement("EXEC sp_GuardarServicioMedico ?, ?, ?, ?, ?", [
            $datos['servicio_id'] ?? null,
            $datos['doctor_id'],
            $datos['nombre_servicio'],
            $datos['precio'],
            $datos['estado']
        ]);
    }
}
