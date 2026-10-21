<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

final class ProviderXlsxTableReader
{
    private const MAX_ZIP_ENTRIES = 300;

    private const MAX_UNCOMPRESSED = 96 * 1024 * 1024;

    private const MAX_ROWS = 5000;

    /** @return array{headers:list<string>,rows:list<array{row:int,cells:list<string>}>} */
    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('海外仓回传文件必须是 XLSX。');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('无法打开海外仓 XLSX。');
        }

        try {
            $this->assertSafeArchive($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            if ($sheetPath === null) {
                throw new InvalidArgumentException('海外仓 XLSX 工作表结构无效。');
            }
            $sheetXml = $zip->getFromName($sheetPath);
            if (! is_string($sheetXml) || $sheetXml === '') {
                throw new InvalidArgumentException('海外仓 XLSX 第一工作表为空。');
            }
            $rows = $this->worksheetRows($sheetXml, $this->sharedStrings($zip));
            if ($rows === []) {
                throw new InvalidArgumentException('海外仓 XLSX 没有可读取内容。');
            }

            $headers = array_shift($rows)['cells'] ?? [];

            return ['headers' => array_values($headers), 'rows' => array_values($rows)];
        } finally {
            $zip->close();
        }
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw new InvalidArgumentException('海外仓 XLSX ZIP 条目数超限。');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
                throw new InvalidArgumentException('海外仓 XLSX 包含不安全路径。');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new InvalidArgumentException('海外仓 XLSX 解压大小超限。');
            }
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
        $rels = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (! $workbook || ! $rels) {
            return null;
        }

        $relMap = [];
        $rxp = new DOMXPath($rels);
        $rxp->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rxp->query('//p:Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = ltrim(str_replace('\\', '/', $rel->getAttribute('Target')), '/');
            if ($target === '' || str_contains($target, '..')) {
                continue;
            }
            $relMap[$rel->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        $xp = new DOMXPath($workbook);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheet = $xp->query('//m:sheets/m:sheet[1]')->item(0);
        if (! $sheet) {
            return null;
        }
        $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');

        return $relMap[$id] ?? null;
    }

    /** @param list<string> $shared @return list<array{row:int,cells:list<string>}> */
    private function worksheetRows(string $sheetXml, array $shared): array
    {
        $reader = $this->reader($sheetXml);
        $rows = [];
        $fallback = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $fallback++;
                if ($fallback > self::MAX_ROWS) {
                    throw new InvalidArgumentException('海外仓 XLSX 行数超限。');
                }
                $rowNumber = (int) ($reader->getAttribute('r') ?: $fallback);
                $doc = $this->xml($reader->readOuterXML());
                if (! $doc) {
                    continue;
                }
                $xp = new DOMXPath($doc);
                $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cells = [];
                foreach ($xp->query('//m:c') as $cell) {
                    $index = $this->columnIndex((string) $cell->getAttribute('r'));
                    if ($index === null) {
                        continue;
                    }
                    $type = (string) $cell->getAttribute('t');
                    if ($type === 'inlineStr') {
                        $text = '';
                        foreach ($xp->query('./m:is//m:t', $cell) as $node) {
                            $text .= $node->textContent;
                        }
                        $cells[$index] = trim($text);
                    } else {
                        $value = $xp->query('./m:v', $cell)->item(0)?->textContent ?? '';
                        $cells[$index] = trim($type === 's' ? ($shared[(int) $value] ?? '') : $value);
                    }
                }
                if ($cells === []) {
                    continue;
                }
                ksort($cells);
                $max = max(array_keys($cells));
                $rows[] = ['row' => $rowNumber, 'cells' => array_map(fn ($i) => (string) ($cells[$i] ?? ''), range(0, $max))];
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }
        $reader = $this->reader($xml);
        $out = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }
                $doc = $this->xml($reader->readOuterXML());
                if (! $doc) {
                    $out[] = '';

                    continue;
                }
                $xp = new DOMXPath($doc);
                $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $text = '';
                foreach ($xp->query('//m:t') as $node) {
                    $text .= $node->textContent;
                }
                $out[] = $text;
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    private function columnIndex(string $reference): ?int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $m) !== 1) {
            return null;
        }
        $index = 0;
        foreach (str_split(strtoupper($m[1])) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function reader(string $xml): XMLReader
    {
        $reader = new XMLReader;
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('海外仓 XLSX XML 无法读取。');
        }

        return $reader;
    }

    private function xml(string|false $value): ?DOMDocument
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $doc = new DOMDocument;

        return @$doc->loadXML($value, LIBXML_NONET | LIBXML_COMPACT) ? $doc : null;
    }
}
