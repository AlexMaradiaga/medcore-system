<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Laboratories\Domain\Ports\LaboratoryRepositoryInterface;

class LaboratoryController extends Controller
{
    public function __construct(
        private LaboratoryRepositoryInterface $repository
    ) {}

    public function catalogo(): JsonResponse
    {
        try {
            $data = $this->repository->getCatalogoExamenes();
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function ordenesPaciente($pacienteId): JsonResponse
    {
        try {
            $data = $this->repository->getOrdenesPorPaciente((int)$pacienteId);
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function resultadosOrden($ordenId): JsonResponse
    {
        try {
            $data = $this->repository->getResultadosPorOrden((int)$ordenId);
            return response()->json(['estado' => 'success', 'datos' => $data]);
        } catch (\Exception $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }
}
