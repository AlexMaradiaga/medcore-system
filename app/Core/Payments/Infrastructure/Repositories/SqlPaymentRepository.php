<?php

namespace App\Core\Payments\Infrastructure\Repositories;

use App\Core\Payments\Domain\Ports\PaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class SqlPaymentRepository implements PaymentRepositoryInterface
{
    public function procesarPago(array $data): bool
    {
        $relacion = DB::selectOne("
            SELECT C.EntidadID, P.UsuarioID
            FROM Consultas CON
            INNER JOIN Citas C ON CON.CitaID = C.CitaID
            INNER JOIN Pacientes P ON C.PacienteID = P.PacienteID
            WHERE CON.ConsultaID = ?
        ", [$data['consulta_id']]);

        if (!$relacion) {
            throw new Exception("No se encontró una consulta o relación válida para el ID provisto.");
        }

        return DB::statement("EXEC sp_ProcesarPago ?, ?, ?, ?, ?, ?, ?, ?", [
            $relacion->UsuarioID,
            $relacion->EntidadID,
            $data['consulta_id'],
            'Consulta',
            $data['metodo_pago'],
            $data['referencia_pasarela'] ?? 'Efectivo Ventanilla',
            'Pagado' 
        ]);
    }
}
