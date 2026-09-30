<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class StrategicReportExporter
{
    public function createExcel(array $report): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP zip es necesaria para generar el archivo Excel.');
        }

        $path = tempnam(sys_get_temp_dir(), 'medgo_report_');
        if ($path === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal del reporte.');
        }
        $xlsxPath = $path . '.xlsx';

        $sheets = [
            'Resumen Ejecutivo' => $this->summaryRows($report),
            'Recaudación' => $this->revenueRows($report),
            'Suscriptores y Planes' => $this->subscriptionRows($report),
            'Recetas y Laboratorio' => $this->clinicalRows($report),
            'Proyecciones' => $this->projectionRows($report),
        ];

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No fue posible construir el archivo Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties(array_keys($sheets)));
        $zip->addFromString('docProps/core.xml', $this->coreProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbook(array_keys($sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        $index = 1;
        foreach ($sheets as $rows) {
            $zip->addFromString("xl/worksheets/sheet{$index}.xml", $this->worksheet($rows));
            $index++;
        }
        $zip->close();
        @unlink($path);

        return $xlsxPath;
    }

    private function summaryRows(array $report): array
    {
        $kpi = $report['kpis'];
        $period = $report['periodo'];
        $rows = [
            [$this->text('REPORTE EJECUTIVO Y CONTABLE MEDGO+', 2)],
            ['Período', ($period['desde'] ?: 'Inicio de registros') . ' al ' . ($period['hasta'] ?: 'Fecha actual')],
            ['Generado', $report['meta']['generated_at']],
            [],
            [$this->text('INDICADORES FINANCIEROS', 2)],
            ['Recaudación total', $this->number($kpi['recaudacion_total'], 3)],
            ['Consultas', $this->number($kpi['recaudacion_consultas'], 3)],
            ['Farmacia', $this->number($kpi['recaudacion_farmacia'], 3)],
            ['Laboratorio', $this->number($kpi['recaudacion_laboratorio'], 3)],
            ['Suscripciones SaaS', $this->number($kpi['recaudacion_saas'], 3)],
            ['Otros conceptos', $this->number($kpi['recaudacion_otros'], 3)],
            ['Transacciones cobradas', $this->number($kpi['transacciones_cobradas'])],
            ['Ticket promedio', $this->number($kpi['ticket_promedio'], 3)],
            ['MRR actual', $this->number($kpi['mrr_actual'], 3)],
            [],
            [$this->text('INDICADORES OPERATIVOS', 2)],
            ['Suscriptores activos', $this->number($kpi['entidades_activas'])],
            ['Recetas emitidas', $this->number($kpi['recetas_emitidas'])],
            ['Recetas entregadas', $this->number($kpi['recetas_entregadas'])],
            ['Tasa entrega recetas', $this->number($kpi['tasa_entrega_recetas'] / 100, 4)],
            ['Exámenes solicitados', $this->number($kpi['examenes_solicitados'])],
            ['Exámenes entregados', $this->number($kpi['examenes_entregados'])],
            ['Tasa entrega exámenes', $this->number($kpi['tasa_entrega_examenes'] / 100, 4)],
            ['Cobertura clínica', $this->number($kpi['cobertura_clinica'] / 100, 4)],
            [],
            [$this->text('LECTURA EJECUTIVA DE LAS GRÁFICAS', 2)],
        ];

        foreach ($report['interpretaciones'] as $title => $explanation) {
            $rows[] = [ucfirst(str_replace('_', ' ', $title)), $explanation];
        }

        return $rows;
    }

    private function revenueRows(array $report): array
    {
        $rows = [[
            $this->text('Entidad', 1), $this->text('Tipo', 1), $this->text('Consultas', 1),
            $this->text('Farmacia', 1), $this->text('Laboratorio', 1), $this->text('SaaS', 1),
            $this->text('Total', 1), $this->text('Transacciones', 1), $this->text('Último pago', 1),
        ]];
        foreach ($report['recaudacion_entidades']['data'] as $row) {
            $rows[] = [
                $row->NombreEntidad, $row->TipoEntidad,
                $this->number($row->RecaudacionConsultas, 3), $this->number($row->RecaudacionFarmacia, 3),
                $this->number($row->RecaudacionLaboratorio, 3), $this->number($row->RecaudacionSaaS, 3),
                $this->number($row->TotalRecaudado, 3), $this->number($row->CantidadTransacciones), $row->UltimoPago,
            ];
        }
        return $rows;
    }

    private function subscriptionRows(array $report): array
    {
        $rows = [[
            $this->text('Tipo suscriptor', 1), $this->text('Plan', 1),
            $this->text('Suscriptores activos', 1), $this->text('MRR', 1),
        ]];
        foreach ($report['entidades_planes']['data'] as $block) {
            foreach ($block['Planes'] as $plan) {
                $rows[] = [$block['TipoEntidad'], $plan['NombrePlan'], $this->number($plan['EntidadesActivas']), $this->number($plan['MRR'], 3)];
            }
        }
        return $rows;
    }

    private function clinicalRows(array $report): array
    {
        $rows = [[
            $this->text('Consulta', 1), $this->text('Cita', 1), $this->text('Fecha', 1),
            $this->text('Médico', 1), $this->text('Paciente', 1), $this->text('Entidad', 1),
            $this->text('Recetas emitidas', 1), $this->text('Recetas entregadas', 1),
            $this->text('Órdenes emitidas', 1), $this->text('Órdenes entregadas', 1),
            $this->text('Exámenes solicitados', 1), $this->text('Exámenes entregados', 1),
            $this->text('Diagnóstico', 1),
        ]];
        foreach ($report['entregas_clinicas']['data'] as $row) {
            $rows[] = [
                $this->number($row->ConsultaID), $this->number($row->CitaID), $row->FechaHora,
                $row->Doctor, $row->Paciente, $row->Entidad ?? 'Sin entidad',
                $this->number($row->RecetasEmitidas), $this->number($row->RecetasEntregadas),
                $this->number($row->OrdenesEmitidas), $this->number($row->OrdenesEntregadas),
                $this->number($row->ExamenesSolicitados), $this->number($row->ExamenesEntregados),
                $row->Diagnostico ?? 'Sin diagnóstico',
            ];
        }
        return $rows;
    }

    private function projectionRows(array $report): array
    {
        $rows = [[
            $this->text('Período', 1), $this->text('Recaudación real', 1),
            $this->text('Recaudación proyectada', 1), $this->text('Clasificación', 1),
        ]];
        foreach ($report['graficas']['recaudacion_proyeccion'] as $row) {
            $rows[] = [
                $row['Etiqueta'],
                $row['Real'] === null ? '' : $this->number($row['Real'], 3),
                $row['Proyectado'] === null ? '' : $this->number($row['Proyectado'], 3),
                $row['EsProyeccion'] ? 'Proyección estadística' : 'Dato real',
            ];
        }
        $rows[] = [];
        $rows[] = [$this->text('Explicación', 2), $report['interpretaciones']['proyeccion']];
        return $rows;
    }

    private function text(mixed $value, int $style = 0): array { return ['value' => (string) $value, 'style' => $style, 'type' => 'string']; }
    private function number(mixed $value, int $style = 0): array { return ['value' => (float) $value, 'style' => $style, 'type' => 'number']; }

    private function worksheet(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $rawCell) {
                $cell = is_array($rawCell) && array_key_exists('value', $rawCell)
                    ? $rawCell
                    : $this->text($rawCell ?? '');
                $reference = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $style = (int) ($cell['style'] ?? 0);
                if (($cell['type'] ?? 'string') === 'number') {
                    $cells .= '<c r="' . $reference . '" s="' . $style . '"><v>' . $cell['value'] . '</v></c>';
                } else {
                    $cells .= '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape($cell['value']) . '</t></is></c>';
                }
            }
            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="20" width="20" customWidth="1"/></cols>'
            . '<sheetData>' . $xmlRows . '</sheetData></worksheet>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;USD &quot;#,##0.00"/></numFmts>'
            . '<fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FF1E1B4B"/><sz val="12"/><name val="Arial"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border/><border><left style="thin"><color rgb="FFDDE3EA"/></left><right style="thin"><color rgb="FFDDE3EA"/></right><top style="thin"><color rgb="FFDDE3EA"/></top><bottom style="thin"><color rgb="FFDDE3EA"/></bottom></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="10" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function contentTypes(int $count): string
    {
        $sheets = '';
        for ($i = 1; $i <= $count; $i++) $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . $sheets . '</Types>';
    }

    private function rootRelationships(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function workbook(array $names): string { $s = ''; foreach ($names as $i => $name) $s .= '<sheet name="' . $this->escape($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>'; return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $s . '</sheets></workbook>'; }
    private function workbookRelationships(int $count): string { $r = ''; for ($i = 1; $i <= $count; $i++) $r .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>'; $r .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'; return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $r . '</Relationships>'; }
    private function appProperties(array $names): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>MedGo+</Application><TitlesOfParts><vt:vector size="' . count($names) . '" baseType="lpstr">' . implode('', array_map(fn ($n) => '<vt:lpstr>' . $this->escape($n) . '</vt:lpstr>', $names)) . '</vt:vector></TitlesOfParts></Properties>'; }
    private function coreProperties(): string { $now = gmdate('Y-m-d\TH:i:s\Z'); return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Reporte Ejecutivo y Contable MedGo+</dc:title><dc:creator>MedGo+</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>'; }
    private function columnName(int $number): string { $name = ''; while ($number > 0) { $number--; $name = chr(65 + ($number % 26)) . $name; $number = intdiv($number, 26); } return $name; }
    private function escape(mixed $value): string { return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}
