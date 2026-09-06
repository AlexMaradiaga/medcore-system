<?php
namespace App\Http\Controllers\Api; 

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Entity\Domain\EntityScheduleRepositoryInterface;

class EntityScheduleController extends Controller
{
    public function __construct(
        private EntityScheduleRepositoryInterface $repository
    ) {}

    public function index(int $entityId)
    {
        $data = $this->repository->getSchedulesByEntity($entityId);
        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function update(Request $request, int $entityId)
    {
        $validated = $request->validate([
            'schedules' => 'required|array',
            'schedules.*.dia_semana' => 'required|integer|min:1|max:7',
            'schedules.*.hora_apertura' => 'required|string',
            'schedules.*.hora_cierre' => 'required|string',
            'schedules.*.es_inactivo' => 'boolean',
        ]);

        $this->repository->saveSchedules($entityId, $validated['schedules']);

        return response()->json(['status' => 'success', 'message' => 'Horario actualizado.']);
    }
}
