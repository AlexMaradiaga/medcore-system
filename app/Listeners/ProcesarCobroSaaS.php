<?php

namespace App\Listeners;

use App\Events\ConsultaFinalizada;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
class ProcesarCobroSaaS
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
   public function handle(ConsultaFinalizada $event)
    {
        $plan = DB::table('PlanesSaaS')->where('DoctorID', $event->doctorId)->first();

        if ($plan && $plan->Tipo === 'Elite') {
            DB::table('Facturacion_SaaS')->insert([
                'DoctorID' => $event->doctorId,
                'Concepto' => 'Conversión Médico: Consulta Cerrada (Cita #'.$event->citaId.')',
                'Monto' => 5.00, 
                'FechaCargo' => now(),
                'Estado' => 'Pendiente'
            ]);
        }
        // Aquí puedes agregar la lógica si es Plan Pro y supera los 12 pacientes (US$25)
    }
}
