<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Entity\Domain\EntityRepositoryInterface;

class EntityController extends Controller
{
    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $tipo = $request->query('tipo');
            $entidades = $this->entityRepository->getAll($tipo);

            return response()->json([
                'status' => 'success',
                'data'   => $entidades
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al obtener entidades: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getEntidadesPublicas(Request $request): JsonResponse
    {
        try {
            $tipo = $request->query('tipo');
            $entidades = $this->entityRepository->getEntidadesPublicas($tipo);

            return response()->json($entidades, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al obtener entidades públicas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPendingEntities(): JsonResponse
    {
        try {
            $entidades = $this->entityRepository->getPendingEntities();
            return response()->json($entidades, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al obtener entidades pendientes: ' . $e->getMessage()
            ], 500);
        }
    }

    public function approveEntity(int $id): JsonResponse
    {
        try {
            $this->entityRepository->approveEntity($id);
            return response()->json([
                'status'  => 'success',
                'message' => 'Entidad aprobada e independizada con éxito.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al aprobar la entidad: ' . $e->getMessage()
            ], 500);
        }
    }

    public function registerInstitution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_usuario'          => 'required|email|unique:Usuarios,Email',
            'password_usuario'       => 'required|string|min:6',
            'telefono_principal'     => 'required|string',
            'nombre_comercial'       => 'required|string|max:150',
            'razon_social'           => 'required|string|max:150',
            'rtn'                    => 'required|string|max:20',
            'tipo_entidad'           => 'required|in:Clinica,Farmacia,Laboratorio',
            'email_institucional'    => 'required|email',
            'telefono_institucional' => 'required|string',
            'direccion_completa'     => 'required|string',
            'departamento'           => 'required|string',
            'municipio'              => 'required|string',
            'rep_nombre'             => 'required|string',
            'rep_dni'                => 'required|string',
            'rep_email'              => 'nullable|email',
            'rep_telefono'           => 'nullable|string',
            'rep_cargo'              => 'nullable|string',
            'rep_foto_dni'           => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'san_nombre'             => 'nullable|string',
            'san_profesion'          => 'nullable|string',
            'san_colegiacion'        => 'nullable|string',
            'san_colegio'            => 'nullable|string',
            'san_email'              => 'nullable|email',
            'san_telefono'           => 'nullable|string',
            'san_doc_colegiacion'    => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'latitud'                => 'nullable|numeric',
            'longitud'               => 'nullable|numeric',
        ]);

        try {
            $entidadId = $this->entityRepository->registerInstitution($validated, $request);

            return response()->json([
                'status'     => 'success',
                'message'    => 'Solicitud de registro institucional procesada con éxito.',
                'entidad_id' => $entidadId
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error en el registro institucional: ' . $e->getMessage()
            ], 400);
        }
    }
}
