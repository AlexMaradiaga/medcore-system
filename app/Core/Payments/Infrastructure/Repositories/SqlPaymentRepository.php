<?php

namespace App\Core\Payments\Infrastructure\Repositories;

use App\Core\Payments\Domain\Ports\PaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class SqlPaymentRepository implements PaymentRepositoryInterface
{
    public function obtenerCatalogoPrecios(?int $doctorId, int $usuarioId): array
    {
        $idDoctor = $doctorId ?? $this->obtenerDoctorIdPorUsuario($usuarioId);

        if (!$idDoctor) {
            return [];
        }

        return DB::select("EXEC sp_ObtenerPreciosDoctor @DoctorID = ?", [$idDoctor]);
    }

    public function obtenerPerfilUbicacion(?int $doctorId, int $usuarioId): ?object
    {
        $idDoctor = $doctorId ?? $this->obtenerDoctorIdPorUsuario($usuarioId);

        if (!$idDoctor) {
            return null;
        }

        return DB::table('Doctores')
            ->select('DireccionConsultorio', 'Latitud', 'Longitud', 'HablaIngles', 'DisponibleDomicilio')
            ->where('DoctorID', $idDoctor)
            ->first();
    }

    public function guardarCatalogoYUbicacion(array $data, int $usuarioId): bool
    {
        $doctorId = $data['doctor_id'] ?? $this->obtenerDoctorIdPorUsuario($usuarioId);

        if (!$doctorId) {
            throw new Exception("No se encontró el registro de doctor correspondiente.");
        }

        return DB::transaction(function () use ($doctorId, $data) {
            // 1. Actualizar catálogo de servicios del doctor
            if (!empty($data['servicios'])) {
                foreach ($data['servicios'] as $serv) {
                    DB::table('Servicios_Medicos')
                        ->where('ServicioID', $serv['ServicioID'])
                        ->where('DoctorID', $doctorId)
                        ->update(['Precio' => $serv['Precio']]);
                }
            }

            // 2. Actualizar perfil y ubicación en la tabla Doctores
            DB::table('Doctores')
                ->where('DoctorID', $doctorId)
                ->update([
                    'DireccionConsultorio' => $data['direccion_consultorio'] ?? null,
                    'Latitud'              => $data['latitud'] ?? null,
                    'Longitud'             => $data['longitud'] ?? null,
                    'HablaIngles'          => filter_var($data['habla_ingles'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                    'DisponibleDomicilio'  => filter_var($data['disponible_domicilio'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                ]);

            return true;
        });
    }

    public function procesarPago(array $data): bool
    {
        // 1. Buscar la relación aceptando CitaID o ConsultaID
        $relacion = DB::selectOne("
            SELECT TOP 1
                CON.ConsultaID,
                C.EntidadID,
                P.UsuarioID
            FROM Consultas CON WITH (NOLOCK)
            INNER JOIN Citas C WITH (NOLOCK) ON CON.CitaID = C.CitaID
            INNER JOIN Pacientes P WITH (NOLOCK) ON C.PacienteID = P.PacienteID
            WHERE CON.ConsultaID = ? OR CON.CitaID = ?
        ", [$data['consulta_id'], $data['consulta_id']]);

        if (!$relacion) {
            throw new Exception("No se encontró una consulta o relación válida para el ID provisto.");
        }

        $montoTotal = $data['monto'] ?? 0.00;
        $metodoPago = $data['metodo_pago'] ?? 'Efectivo';
        $referenciaPasarela = $data['referencia_pasarela'] ?? 'Efectivo Ventanilla';

        // 2. Ejecutar el Stored Procedure pasando los 8 parámetros requeridos exactos
        return DB::statement("EXEC sp_ProcesarPago ?, ?, ?, ?, ?, ?, ?, ?", [
            $relacion->UsuarioID,        // @UsuarioID INT
            $relacion->EntidadID,        // @EntidadID INT
            $relacion->ConsultaID,       // @ReferenciaID INT
            'Consulta',                  // @TipoConcepto NVARCHAR(50)
            $montoTotal,                 // @MontoTotal DECIMAL(18,2) <-- ¡AGREGADO!
            $metodoPago,                 // @MetodoPago NVARCHAR(50)
            $referenciaPasarela,         // @ReferenciaPasarela NVARCHAR(100)
            'Pagado'                     // @EstadoPago NVARCHAR(20)
        ]);
    }

    private function obtenerDoctorIdPorUsuario(int $usuarioId): ?int
    {
        return DB::table('Doctores')->where('UsuarioID', $usuarioId)->value('DoctorID');
    }
}
