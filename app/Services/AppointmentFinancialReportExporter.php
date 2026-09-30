<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class AppointmentFinancialReportExporter
{
    public function createExcel(array $report): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP zip es necesaria para generar el archivo Excel.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'medgo_citas_');
        if ($temporary === false) {
            throw new RuntimeException('No fue posible crear el reporte temporal.');
        }
        $path = $temporary . '.xlsx';
        $sheets = [
            'Resumen Ejecutivo' => $this->summaryRows($report),
            'Detalle de Citas' => $this->detailRows($report),
            'Cobros por Médico' => $this->doctorRows($report),
            'Recaudación Mensual' => $this->monthlyRows($report),
        ];

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
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
        @unlink($temporary);
        return $path;
    }

    private function summaryRows(array $report): array
    {
        $s = $report['summary'];
        return [
            [$this->text('MEDGO+ - REPORTE EJECUTIVO Y CONTABLE DE CITAS Y COBROS', 2)],
            ['Generado', $report['meta']['generated_at']],
            ['Interpretación ejecutiva', $report['interpretation']],
            [],
            [$this->text('INDICADORES', 2)],
            ['Total citas', $this->number($s['total_citas'])],
            ['Completadas', $this->number($s['completadas'])],
            ['Canceladas', $this->number($s['canceladas'])],
            ['Citas con cobro', $this->number($s['con_pago'])],
            ['Citas sin cobro aprobado', $this->number($s['saldo_sin_cobro'])],
            ['Monto cobrado', $this->number($s['monto_cobrado'], 3)],
            ['Ticket promedio', $this->number($s['ticket_promedio'], 3)],
            ['Tasa de cobro', $this->number($s['tasa_cobro'] / 100, 4)],
            ['Tasa de finalización', $this->number($s['tasa_finalizacion'] / 100, 4)],
        ];
    }

    private function detailRows(array $report): array
    {
        $rows = [[
            $this->text('Cita', 1), $this->text('Fecha', 1), $this->text('Estado', 1),
            $this->text('Paciente', 1), $this->text('DNI', 1), $this->text('Doctor', 1),
            $this->text('Entidad', 1), $this->text('Consulta', 1), $this->text('Diagnóstico', 1),
            $this->text('Monto cobrado', 1), $this->text('Monto registrado', 1),
            $this->text('Pagos', 1), $this->text('Métodos', 1), $this->text('Estados pago', 1),
        ]];
        foreach ($report['data'] as $row) {
            $rows[] = [
                $this->number($row['CitaID']), $row['FechaHora'], $row['EstadoCita'], $row['Paciente'], $row['DNI'],
                $row['Doctor'], $row['Entidad'] ?? 'Sin entidad', $row['ConsultaID'] ?? '', $row['Diagnostico'] ?? 'Sin diagnóstico',
                $this->number($row['MontoCobrado'], 3), $this->number($row['MontoRegistrado'], 3),
                $this->number($row['CantidadPagos']), $row['MetodosPago'] ?? 'Sin pago', $row['EstadosPago'] ?? 'No aplica',
            ];
        }
        return $rows;
    }

    private function doctorRows(array $report): array
    {
        $rows = [[$this->text('Médico', 1), $this->text('Citas', 1), $this->text('Cobrado', 1), $this->text('Participación', 1)]];
        $total = max(0.0, (float) $report['summary']['monto_cobrado']);
        foreach ($report['analytics']['por_doctor'] as $row) {
            $rows[] = [$row['doctor'], $this->number($row['citas']), $this->number($row['cobrado'], 3), $this->number($total > 0 ? $row['cobrado'] / $total : 0, 4)];
        }
        return $rows;
    }

    private function monthlyRows(array $report): array
    {
        $rows = [[$this->text('Período', 1), $this->text('Monto cobrado', 1)]];
        foreach ($report['analytics']['recaudacion_mensual'] as $row) {
            $rows[] = [$row['periodo'], $this->number($row['monto'], 3)];
        }
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
                $cell = is_array($rawCell) && array_key_exists('value', $rawCell) ? $rawCell : $this->text($rawCell ?? '');
                $reference = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $style = (int) ($cell['style'] ?? 0);
                $cells .= ($cell['type'] ?? 'string') === 'number'
                    ? '<c r="' . $reference . '" s="' . $style . '"><v>' . $cell['value'] . '</v></c>'
                    : '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape($cell['value']) . '</t></is></c>';
            }
            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="20" width="20" customWidth="1"/></cols><sheetData>' . $xmlRows . '</sheetData></worksheet>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;L &quot;#,##0.00"/></numFmts><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FF005596"/><sz val="12"/><name val="Arial"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF005596"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFDDE3EA"/></left><right style="thin"><color rgb="FFDDE3EA"/></right><top style="thin"><color rgb="FFDDE3EA"/></top><bottom style="thin"><color rgb="FFDDE3EA"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right"/></xf><xf numFmtId="10" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
    private function contentTypes(int $count): string { $s=''; for($i=1;$i<=$count;$i++)$s.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'.$s.'</Types>'; }
    private function rootRelationships(): string { return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function workbook(array $names): string { $s=''; foreach($names as $i=>$n)$s.='<sheet name="'.$this->escape($n).'" sheetId="'.($i+1).'" r:id="rId'.($i+1).'"/>'; return '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$s.'</sheets></workbook>'; }
    private function workbookRelationships(int $count): string { $r=''; for($i=1;$i<=$count;$i++)$r.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>'; $r.='<Relationship Id="rId'.($count+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'; return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$r.'</Relationships>'; }
    private function appProperties(array $names): string { return '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>MedGo+</Application><TitlesOfParts><vt:vector size="'.count($names).'" baseType="lpstr">'.implode('',array_map(fn($n)=>'<vt:lpstr>'.$this->escape($n).'</vt:lpstr>',$names)).'</vt:vector></TitlesOfParts></Properties>'; }
    private function coreProperties(): string { $now=gmdate('Y-m-d\TH:i:s\Z'); return '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Reporte de Citas y Cobros MedGo+</dc:title><dc:creator>MedGo+</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created></cp:coreProperties>'; }
    private function columnName(int $number): string { $name=''; while($number>0){$number--; $name=chr(65+($number%26)).$name; $number=intdiv($number,26);} return $name; }
    private function escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_XML1|ENT_QUOTES, 'UTF-8'); }
}
