<?php

namespace App\Core\SaaS\Infrastructure\Repositories;

use App\Core\SaaS\Domain\Ports\SaaSRepositoryInterface;
use App\Events\SaaS\PaymentReceived;
use App\Events\SaaS\PlanActivated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use PDO;

class SqlSaaSRepository implements SaaSRepositoryInterface
{
    public function actualizarPlan(int $usuarioId, string $tipoPlan, int $diasVigencia, string $tokenPasarela): bool
    {
        // 1. Obtener EntidadID del usuario autenticado (fallback a 1)
        $entidadId = Auth::user()->entidadId ?? 1;

        // 2. Mapeo de IDs de Planes según nombre
        $planMap = [
            'Popular'   => 1,
            'Ejecutivo' => 2,
            'VIP'       => 3,
        ];

        $planId = $planMap[$tipoPlan] ?? 2;
        $monto  = $planId === 3 ? 299.00 : ($planId === 2 ? 99.00 : 0.00);

        // 3. Ejecución dentro de una transacción para asegurar consistencia con los eventos de auditoría
        return DB::transaction(function () use ($usuarioId, $entidadId, $planId, $tipoPlan, $diasVigencia, $monto, $tokenPasarela) {
            $resultado = DB::statement("EXEC sp_ProcesarPago
                @UsuarioID = ?,
                @EntidadID = ?,
                @ReferenciaID = ?,
                @TipoConcepto = ?,
                @MontoTotal = ?,
                @MetodoPago = ?,
                @ReferenciaPasarela = ?,
                @EstadoPago = ?,
                @PlanID = ?",
            [
                $usuarioId,
                $entidadId,
                $planId,                 // ReferenciaID
                'SuscripcionSaaS',      // TipoConcepto
                $monto,                  // MontoTotal
                'card',                  // MetodoPago
                $tokenPasarela,          // ReferenciaPasarela
                'PROCESADO',             // EstadoPago
                $planId                  // PlanID
            ]);

            if ($resultado) {
                // Despachar evento de pago recibido para trazabilidad auditada
                PaymentReceived::dispatch(
                    $usuarioId,
                    $entidadId,
                    $monto,
                    $tokenPasarela,
                    'Pasarela_Tarjetas'
                );

                // Despachar evento de activación de plan
                PlanActivated::dispatch(
                    $usuarioId,
                    $entidadId,
                    $tipoPlan,
                    $diasVigencia
                );
            }

            return $resultado;
        });
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
