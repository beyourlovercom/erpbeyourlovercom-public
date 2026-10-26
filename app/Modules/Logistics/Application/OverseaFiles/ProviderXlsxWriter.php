<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use RuntimeException;
use ZipArchive;

final class ProviderXlsxWriter
{
    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function write(string $sheetName, array $headers, array $rows): string
    {
        $sheet = $this->worksheetXml($headers, $rows);

        $path = tempnam(sys_get_temp_dir(), 'oversea-provider-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to create provider XLSX temporary file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Unable to create provider XLSX archive.');
        }
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        } finally {
            $zip->close();
        }
        $bytes = file_get_contents($path);
        @unlink($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read provider XLSX archive.');
        }

        return $bytes;
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function worksheetXml(array $headers, array $rows): string
    {
        $all = [$headers, ...$rows];
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($all as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $sheet .= '<row r="'.$number.'">';
            foreach ($row as $columnIndex => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = $this->columnName($columnIndex + 1).$number;
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                        .$this->xml((string) $value).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        return $sheet;
    }

    /**
     * Append a clearly separated INTERNAL worksheet without touching the provider
     * format on Sheet 1. Problem orders are not represented as valid provider rows.
     *
     * @param list<array{order_id:int|null,order_number:string,reason:string}> $issues
     */
    public function appendIssuesSheet(string $bytes, array $issues): string
    {
        if ($issues === []) {
            return $bytes;
        }
        $path = tempnam(sys_get_temp_dir(), 'oversea-export-issues-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Unable to prepare overseas export exceptions.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new RuntimeException('Unable to open overseas export for exception report.');
        }
        try {
            $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $officeNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $packageNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
            $contentNs = 'http://schemas.openxmlformats.org/package/2006/content-types';
            $workbook = new \DOMDocument;
            $rels = new \DOMDocument;
            $types = new \DOMDocument;
            if (! $workbook->loadXML((string) $zip->getFromName('xl/workbook.xml'), LIBXML_NONET)
                || ! $rels->loadXML((string) $zip->getFromName('xl/_rels/workbook.xml.rels'), LIBXML_NONET)
                || ! $types->loadXML((string) $zip->getFromName('[Content_Types].xml'), LIBXML_NONET)) {
                throw new RuntimeException('Overseas XLSX missing workbook metadata.');
            }
            $sheets = $workbook->getElementsByTagNameNS($mainNs, 'sheets')->item(0);
            if (! $sheets) {
                throw new RuntimeException('Overseas XLSX missing sheets.');
            }
            $sheetNode = $workbook->createElementNS($mainNs, 'sheet');
            $sheetNode->setAttribute('name', '异常订单-请勿提交仓库');
            $sheetNode->setAttribute('sheetId', '2');
            $sheetNode->setAttributeNS($officeNs, 'r:id', 'rId2');
            $sheets->appendChild($sheetNode);

            $relation = $rels->createElementNS($packageNs, 'Relationship');
            $relation->setAttribute('Id', 'rId2');
            $relation->setAttribute('Type', $officeNs.'/worksheet');
            $relation->setAttribute('Target', 'worksheets/sheet2.xml');
            $rels->documentElement->appendChild($relation);

            $override = $types->createElementNS($contentNs, 'Override');
            $override->setAttribute('PartName', '/xl/worksheets/sheet2.xml');
            $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
            $types->documentElement->appendChild($override);

            $rows = array_map(fn (array $issue): array => [
                $issue['order_number'], $issue['order_id'], $issue['reason'], '未包含在仓库下单表，须返回 ERP 修复',
            ], $issues);
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->worksheetXml(
                ['ERP订单号', '订单ID', '异常原因', '处理要求'], $rows
            ));
            $zip->addFromString('xl/workbook.xml', (string) $workbook->saveXML());
            $zip->addFromString('xl/_rels/workbook.xml.rels', (string) $rels->saveXML());
            $zip->addFromString('[Content_Types].xml', (string) $types->saveXML());
        } finally {
            $zip->close();
        }
        $out = file_get_contents($path);
        @unlink($path);
        if ($out === false) {
            throw new RuntimeException('Unable to finalize overseas exception workbook.');
        }

        return $out;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
