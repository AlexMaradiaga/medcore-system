<?php
namespace App\Core\Entity\Infrastructure;

use App\Core\Entity\Domain\EntityScheduleRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SqlEntityScheduleRepository implements EntityScheduleRepositoryInterface
{
    public function getSchedulesByEntity(int $entityId): array
    {
        return DB::select('EXEC sp_ObtenerHorariosEntidad ?', [$entityId]);
    }

    public function saveSchedules(int $entityId, array $schedules): void
    {
        DB::transaction(function () use ($entityId, $schedules) {
            DB::table('Entidad_Horarios')->where('EntidadID', $entityId)->delete();

            foreach ($schedules as $item) {
                DB::table('Entidad_Horarios')->insert([
                    'EntidadID' => $entityId,
                    'DiaSemana' => $item['dia_semana'],
                    'HoraApertura' => $item['hora_apertura'],
                    'HoraCierre' => $item['hora_cierre'],
                    'EsInactivo' => $item['es_inactivo'] ?? 0,
                    'NotasExcepcion' => $item['notas'] ?? null,
                ]);
            }
        });
    }
}
