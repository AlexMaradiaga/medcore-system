<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Ejecutivo y Contable MedGo+</title>
    <style>
        @page { size: A4 landscape; margin: 26px 32px 32px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 8.5px; line-height: 1.35; }
        .header { border-bottom: 3px solid #0f766e; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-size: 22px; font-weight: bold; color: #0f766e; }
        .brand span { color: #1e3a8a; }
        .title { font-size: 15px; font-weight: bold; text-transform: uppercase; margin-top: 2px; }
        .subtitle { color: #64748b; margin-top: 3px; }
        .meta { float: right; width: 260px; text-align: right; color: #475569; }
        .clear { clear: both; }
        .section { margin-top: 15px; }
        .section-title { background: #1e3a8a; color: white; padding: 7px 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .4px; }
        .kpi-table { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        .kpi { border: 4px solid #fff; background: #f8fafc; padding: 10px; vertical-align: top; width: 20%; page-break-inside: avoid; }
        .kpi-label { color: #64748b; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .kpi-value { color: #0f172a; font-size: 15px; font-weight: bold; margin-top: 3px; }
        .analysis { border-left: 4px solid #14b8a6; background: #f0fdfa; padding: 9px 12px; margin: 8px 0 12px; color: #334155; }
        .analysis strong { color: #0f766e; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 7px; }
        table.data th { background: #e2e8f0; color: #334155; padding: 5px 4px; border: 1px solid #cbd5e1; text-align: left; font-size: 6.8px; text-transform: uppercase; }
        table.data td { padding: 5px 4px; border: 1px solid #e2e8f0; vertical-align: top; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        .right { text-align: right; }
        .center { text-align: center; }
        .money { font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }
        .positive { color: #047857; font-weight: bold; }
        .muted { color: #64748b; }
        .section-separator { height: 12px; border-bottom: 1px solid #dbe4ee; margin-bottom: 10px; }
        .note { margin-top: 12px; padding: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
        .footer { margin-top: 14px; border-top: 1px solid #cbd5e1; padding-top: 5px; color: #64748b; font-size: 7px; }
        .footer-right { float: right; }
    </style>
</head>
<body>
@php
    $k = $report['kpis'];
    $periodo = ($report['periodo']['desde'] ?: 'Inicio de registros') . ' al ' . ($report['periodo']['hasta'] ?: 'Fecha actual');
    $money = fn ($value) => 'USD ' . number_format((float) $value, 2, '.', ',');
@endphp

<div class="header">
    <div class="meta"><strong>Período analizado</strong><br>{{ $periodo }}<br><span class="muted">Estados cobrados: {{ implode(', ', $report['meta']['paid_statuses']) }}</span></div>
    <div class="brand">MedGo<span>+</span></div>
    <div class="title">Reporte Ejecutivo y Contable</div>
    <div class="subtitle">Desempeño financiero, comercial y clínico para administración</div>
    <div class="clear"></div>
</div>

<div class="section-title">1. Resumen para toma de decisiones</div>
<table class="kpi-table"><tr>
    <td class="kpi"><div class="kpi-label">Recaudación procesada</div><div class="kpi-value">{{ $money($k['recaudacion_total']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Transacciones cobradas</div><div class="kpi-value">{{ number_format($k['transacciones_cobradas']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Ticket promedio</div><div class="kpi-value">{{ $money($k['ticket_promedio']) }}</div></td>
    <td class="kpi"><div class="kpi-label">MRR actual</div><div class="kpi-value">{{ $money($k['mrr_actual']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Suscriptores activos</div><div class="kpi-value">{{ number_format($k['entidades_activas']) }}</div></td>
</tr></table>
<table class="kpi-table"><tr>
    <td class="kpi"><div class="kpi-label">Consultas</div><div class="kpi-value">{{ $money($k['recaudacion_consultas']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Farmacia</div><div class="kpi-value">{{ $money($k['recaudacion_farmacia']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Laboratorio</div><div class="kpi-value">{{ $money($k['recaudacion_laboratorio']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Suscripciones SaaS</div><div class="kpi-value">{{ $money($k['recaudacion_saas']) }}</div></td>
    <td class="kpi"><div class="kpi-label">Otros conceptos</div><div class="kpi-value">{{ $money($k['recaudacion_otros']) }}</div></td>
</tr></table>

<div class="analysis"><strong>Lectura financiera:</strong> {{ $report['interpretaciones']['recaudacion'] }}</div>
<div class="analysis"><strong>Lectura comercial:</strong> {{ $report['interpretaciones']['suscripciones'] }}</div>
<div class="analysis"><strong>Lectura clínica:</strong> {{ $report['interpretaciones']['entregas_clinicas'] }}</div>
<div class="analysis"><strong>Lectura de proyección:</strong> {{ $report['interpretaciones']['proyeccion'] }}</div>

<div class="section">
    <div class="section-title">2. Recaudación por entidad y línea de negocio</div>
    <table class="data">
        <thead><tr><th>Entidad</th><th>Tipo</th><th class="right">Consultas</th><th class="right">Farmacia</th><th class="right">Laboratorio</th><th class="right">SaaS</th><th class="right">Total</th><th class="center">Trans.</th><th>Último pago</th></tr></thead>
        <tbody>
        @forelse($report['recaudacion_entidades']['data'] as $row)
            <tr>
                <td><strong>{{ $row->NombreEntidad }}</strong></td><td>{{ $row->TipoEntidad }}</td>
                <td class="right money">{{ $money($row->RecaudacionConsultas) }}</td><td class="right money">{{ $money($row->RecaudacionFarmacia) }}</td>
                <td class="right money">{{ $money($row->RecaudacionLaboratorio) }}</td><td class="right money">{{ $money($row->RecaudacionSaaS) }}</td>
                <td class="right money positive">{{ $money($row->TotalRecaudado) }}</td><td class="center">{{ $row->CantidadTransacciones }}</td><td>{{ $row->UltimoPago ?: 'Sin fecha' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="center muted">No hay cobros aprobados en el período seleccionado.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="section-separator"></div>
<div class="header"><div class="brand">MedGo<span>+</span></div><div class="title">Suscripciones y desempeño comercial</div></div>
<div class="analysis"><strong>Explicación de la gráfica de suscriptores:</strong> {{ $report['interpretaciones']['suscripciones'] }}</div>
<table class="data">
    <thead><tr><th>Tipo de suscriptor</th><th>Plan</th><th class="center">Suscriptores</th><th class="right">MRR</th><th class="center">Situación</th></tr></thead>
    <tbody>
    @foreach($report['entidades_planes']['data'] as $block)
        @foreach($block['Planes'] as $plan)
            <tr><td><strong>{{ $block['TipoEntidad'] }}</strong></td><td>{{ $plan['NombrePlan'] }}</td><td class="center">{{ $plan['EntidadesActivas'] }}</td><td class="right money">{{ $money($plan['MRR']) }}</td><td class="center">{{ $plan['EntidadesActivas'] > 0 ? 'Con suscriptores' : 'Sin suscriptores' }}</td></tr>
        @endforeach
    @endforeach
    </tbody>
</table>

<div class="section">
    <div class="section-title">3. Proyección de recaudación</div>
    <div class="analysis"><strong>Explicación de la gráfica:</strong> {{ $report['interpretaciones']['proyeccion'] }}</div>
    <table class="data">
        <thead><tr><th>Período</th><th class="right">Valor real</th><th class="right">Valor proyectado</th><th>Clasificación</th></tr></thead>
        <tbody>@foreach($report['graficas']['recaudacion_proyeccion'] as $row)
            <tr><td>{{ $row['Etiqueta'] }}</td><td class="right money">{{ $row['Real'] === null ? '-' : $money($row['Real']) }}</td><td class="right money">{{ $row['Proyectado'] === null ? '-' : $money($row['Proyectado']) }}</td><td>{{ $row['EsProyeccion'] ? 'Estimación estadística' : 'Dato real contabilizado' }}</td></tr>
        @endforeach</tbody>
    </table>
    <div class="note"><strong>Nota administrativa:</strong> la proyección usa regresión lineal sobre seis meses. Debe utilizarse como señal de tendencia y no como sustituto de presupuesto, flujo de caja o estados financieros auditados.</div>
</div>

<div class="section-separator"></div>
<div class="header"><div class="brand">MedGo<span>+</span></div><div class="title">Cumplimiento clínico y trazabilidad</div></div>
<table class="kpi-table"><tr>
    <td class="kpi"><div class="kpi-label">Recetas entregadas</div><div class="kpi-value">{{ $k['recetas_entregadas'] }} / {{ $k['recetas_emitidas'] }}</div></td>
    <td class="kpi"><div class="kpi-label">Tasa recetas</div><div class="kpi-value">{{ number_format($k['tasa_entrega_recetas'], 1) }}%</div></td>
    <td class="kpi"><div class="kpi-label">Exámenes entregados</div><div class="kpi-value">{{ $k['examenes_entregados'] }} / {{ $k['examenes_solicitados'] }}</div></td>
    <td class="kpi"><div class="kpi-label">Tasa exámenes</div><div class="kpi-value">{{ number_format($k['tasa_entrega_examenes'], 1) }}%</div></td>
    <td class="kpi"><div class="kpi-label">Cobertura clínica</div><div class="kpi-value">{{ number_format($k['cobertura_clinica'], 1) }}%</div></td>
</tr></table>
<div class="analysis"><strong>Explicación de la gráfica clínica:</strong> {{ $report['interpretaciones']['entregas_clinicas'] }}</div>
<table class="data">
    <thead><tr><th>Consulta / fecha</th><th>Médico</th><th>Paciente</th><th>Entidad</th><th class="center">Recetas E/E</th><th class="center">Órdenes E/E</th><th class="center">Exámenes E/E</th><th>Diagnóstico</th></tr></thead>
    <tbody>
    @forelse($report['entregas_clinicas']['data'] as $row)
        <tr><td><strong>#{{ $row->ConsultaID }}</strong><br>{{ $row->FechaHora }}</td><td>{{ $row->Doctor }}</td><td>{{ $row->Paciente }}</td><td>{{ $row->Entidad ?: 'Sin entidad' }}</td><td class="center">{{ $row->RecetasEntregadas }}/{{ $row->RecetasEmitidas }}</td><td class="center">{{ $row->OrdenesEntregadas }}/{{ $row->OrdenesEmitidas }}</td><td class="center">{{ $row->ExamenesEntregados }}/{{ $row->ExamenesSolicitados }}</td><td>{{ $row->Diagnostico ?: 'Sin diagnóstico' }}</td></tr>
    @empty
        <tr><td colspan="8" class="center muted">No hay recetas u órdenes vinculadas a consultas en el período.</td></tr>
    @endforelse
    </tbody>
</table>
<div class="footer">
    MedGo+ - Información gerencial confidencial
    <span class="footer-right">Generado desde SQL Server: {{ $report['meta']['generated_at'] }}</span>
</div>
</body>
</html>
