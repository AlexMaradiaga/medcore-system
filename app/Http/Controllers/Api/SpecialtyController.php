<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Core\Specialties\Domain\Ports\SpecialtyRepositoryInterface;
use Illuminate\Http\Request;

class SpecialtyController extends Controller {
    public function __construct(private SpecialtyRepositoryInterface $repo) {}

    public function index() {
        return response()->json($this->repo->getAllActive());
    }

    public function store(Request $request) {
        $this->repo->store($request->validate(['nombre' => 'required|unique:Especialidades,NombreEspecialidad']));
        return response()->json(['message' => 'Creada'], 201);
    }

    public function update(Request $request, $id) {
        $this->repo->update($id, $request->validate(['nombre' => 'required']));
        return response()->json(['message' => 'Actualizada']);
    }

    public function destroy($id) {
        $this->repo->delete($id);
        return response()->json(['message' => 'Desactivada']);
    }
}