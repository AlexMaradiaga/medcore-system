<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class SubscriptionAuditLogger
{
    public function handle(object $event): void
    {
        $eventName = class_basename($event);

        $usuarioId = $event->usuarioId ?? null;
        $entidadId = $event->entidadId ?? null;

        $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('subscription_audit_logs')->insert([
            'EventName' => $eventName,
            'UsuarioID' => $usuarioId,
            'EntidadID' => $entidadId,
            'Payload'   => $payload,
            'IpAddress' => Request::ip(),
            'UserAgent' => Request::userAgent(),
            'CreatedAt' => now(),
        ]);
    }
}
