<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use RuntimeException;
use ZipArchive;

final class ProviderXlsxWriter
{
    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function write(string $sheetName, array $headers, array $rows): string
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
