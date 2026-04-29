<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; padding: 30px; color: #333; }
        .header { text-align: center; border-bottom: 3px solid #2563eb; margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: bold; color: #2563eb; }
        .section { margin-bottom: 20px; }
        .label { font-size: 10px; text-transform: uppercase; color: #666; font-weight: bold; }
        .content { font-size: 14px; margin-top: 5px; }
        .rx-container { border: 1px solid #e2e8f0; padding: 20px; border-radius: 10px; margin-top: 20px; min-height: 200px; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #aaa; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">MEDCORE GLOBAL+</div>
        <p>Receta Médica Digital</p>
    </div>

    <table width="100%">
        <tr>
            <td width="50%">
                <div class="section">
                    <div class="label">Doctor / Especialidad</div>
                    <div class="content"><strong>Dr. {{ $data->Doctor }}</strong></div>
                    <div class="content">{{ $data->Especialidad }}</div>
                </div>
            </td>
            <td width="50%" align="right">
                <div class="section">
                    <div class="label">Fecha de Emisión</div>
                    <div class="content">{{ $data->FechaHora }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section" style="background: #f1f5f9; padding: 10px;">
        <div class="label">Paciente</div>
        <div class="content">{{ $data->Paciente }} ({{ $data->Edad }} años)</div>
    </div>

    <div class="rx-container">
        <div class="label" style="font-size: 20px; color: #2563eb;">Rx</div>
        <div class="content" style="white-space: pre-line;">
            {{ $data->DetalleMedicamentos }}
        </div>
    </div>

    <div class="footer">
        Este documento es una receta oficial de MedCore Global. ID de Validación: {{ $data->RecetaID }}
    </div>
</body>
</html>
