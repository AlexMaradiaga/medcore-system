<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        \App\Events\SaaS\PlanActivated::class => [
            \App\Listeners\SubscriptionAuditLogger::class,
        ],
        \App\Events\SaaS\PlanRenewed::class => [
            \App\Listeners\SubscriptionAuditLogger::class,
        ],
        \App\Events\SaaS\PlanCancelled::class => [
            \App\Listeners\SubscriptionAuditLogger::class,
        ],
        \App\Events\SaaS\PlanExpired::class => [
            \App\Listeners\SubscriptionAuditLogger::class,
        ],
        \App\Events\SaaS\PaymentReceived::class => [
            \App\Listeners\SubscriptionAuditLogger::class,
        ],
    ];
}
