<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class WarehouseRestockPackageService
{
    private const PI_TEMPLATE_VERSION = '007-pi-sea-canonical-v1';
    private const PI_TEMPLATE_SHA256 = '68157db503a12fc721421cae80409958f532208b57b8cb8acd58137776a68a54';
    private const MAX_PI_ITEMS = 18;
    private const MAX_ARTIFACT_BYTES = 10_485_760;

    public function storeWorkerArtifact(
        WarehouseRestockDocumentJob $job,
        string $kind,
        string $labelSku,
        UploadedFile $file,
        string $expectedSha256,
    ): array {
        if (! in_array($kind, ['btw', 'pdf'], true)) {
            throw ValidationException::withMessages(['worker_artifact' => '不支持的蓝标文件类型。']);
        }

        $expectedItems = collect((array) ($job->payload['items'] ?? []))
            ->keyBy(fn (array $item): string => (string) ($item['label_sku'] ?? ''));
        if (! $expectedItems->has($labelSku)) {
            throw ValidationException::withMessages(['worker_artifact' => '文件 Label SKU 不属于当前冻结任务。']);
        }

        if ($file->getSize() === false || $file->getSize() < 1 || $file->getSize() > self::MAX_ARTIFACT_BYTES) {
            throw ValidationException::withMessages(['worker_artifact' => '蓝标文件大小无效。']);
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw ValidationException::withMessages(['worker_artifact' => '缺少有效 SHA-256。']);
        }

        $real = $file->getRealPath();
        $actual = is_string($real) ? hash_file('sha256', $real) : false;
        if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
            throw ValidationException::withMessages(['worker_artifact' => '蓝标文件 SHA-256 不匹配。']);
        }

        $path = $this->artifactPath($job, $kind, $labelSku);
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            $existing = hash('sha256', (string) $disk->get($path));
            if (! hash_equals($actual, $existing)) {
                throw ValidationException::withMessages(['worker_artifact' => '已存在不同内容的同名蓝标文件。']);
            }

            return ['path' => $path, 'sha256' => $actual, 'bytes' => (int) $file->getSize()];
        }

        $bytes = file_get_contents($real);
        if (! is_string($bytes) || ! $disk->put($path, $bytes)) {
            throw ValidationException::withMessages(['worker_artifact' => '蓝标文件写入 ERP 私有存储失败。']);
        }

        return ['path' => $path, 'sha256' => $actual, 'bytes' => strlen($bytes)];
    }

    /** @return array{root:string,manifest:array,pi_path:string,package_path:string} */
    public function finalize(
        WarehouseRestockDocumentJob $job,
        array $workerManifest,
        string $workerOutputRoot,
    ): array {
        $root = $this->root($job);
        $disk = Storage::disk('local');

        foreach ((array) ($workerManifest['artifacts'] ?? []) as $artifact) {
            $labelSku = (string) ($artifact['label_sku'] ?? '');
            foreach (['btw', 'pdf'] as $kind) {
                $path = $this->artifactPath($job, $kind, $labelSku);
                if (! $disk->exists($path)) {
                    throw ValidationException::withMessages([
                        'worker_job' => 'ERP 尚未收到蓝标文件：'.$labelSku.'.'.$kind,
                    ]);
                }

                $actual = hash('sha256', (string) $disk->get($path));
                $expected = (string) ($artifact[$kind.'_sha256'] ?? '');
                if ($expected === '' || ! hash_equals($expected, $actual)) {
                    throw ValidationException::withMessages([
                        'worker_job' => 'ERP 蓝标文件 SHA-256 与 manifest 不一致：'.$labelSku.'.'.$kind,
                    ]);
                }
            }
        }

        $piPath = $root.'/PI.xlsx';
        $piBytes = $this->generatePi($job);
        if (! $disk->put($piPath, $piBytes)) {
            throw ValidationException::withMessages(['worker_job' => 'PI.xlsx 写入失败。']);
        }

        $packageManifest = $workerManifest;
        $packageManifest['erp_package'] = [
            'pi' => 'PI.xlsx',
            'pi_sha256' => hash('sha256', $piBytes),
            'worker_output_root' => $workerOutputRoot,
            'storage' => 'erp_private_local_v1',
        ];

        $manifestPath = $root.'/manifest.json';
        $manifestBytes = json_encode(
            $packageManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
        $disk->put($manifestPath, $manifestBytes);

        $packagePath = $root.'/'.$job->reference.'.zip';
        $absolutePackage = $disk->path($packagePath);
        @mkdir(dirname($absolutePackage), 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($absolutePackage, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages(['worker_job' => '无法创建成品 ZIP。']);
        }

        try {
            $zip->addFromString('PI.xlsx', $piBytes);
            $zip->addFromString('manifest.json', $manifestBytes);

            foreach ((array) ($workerManifest['artifacts'] ?? []) as $artifact) {
                $labelSku = (string) $artifact['label_sku'];
                $zip->addFromString(
                    '蓝标/'.$labelSku.'.btw',
                    (string) $disk->get($this->artifactPath($job, 'btw', $labelSku)),
                );
                $zip->addFromString(
                    'sku标签/'.$labelSku.'.pdf',
                    (string) $disk->get($this->artifactPath($job, 'pdf', $labelSku)),
                );
            }
        } finally {
            $zip->close();
        }

        $packageSha = hash_file('sha256', $absolutePackage);
        if (! is_string($packageSha)) {
            throw ValidationException::withMessages(['worker_job' => '成品 ZIP SHA-256 计算失败。']);
        }

        $packageManifest['erp_package']['zip'] = $job->reference.'.zip';
        $packageManifest['erp_package']['zip_sha256'] = $packageSha;

        return [
            'root' => $root,
            'manifest' => $packageManifest,
            'pi_path' => $piPath,
            'package_path' => $packagePath,
        ];
    }

    public function discardIncomplete(WarehouseRestockDocumentJob $job): void
    {
        if ($job->status === WarehouseRestockDocumentJobService::STATUS_SUCCEEDED) {
            return;
        }

        Storage::disk('local')->deleteDirectory($this->root($job));
    }

    public function artifactProgress(WarehouseRestockDocumentJob $job): array
    {
        $items = array_values((array) ($job->payload['items'] ?? []));
        $expected = count($items) * 2;
        $received = 0;

        foreach ($items as $item) {
            $labelSku = trim((string) ($item['label_sku'] ?? ''));
            if ($labelSku === '') continue;

            foreach (['btw', 'pdf'] as $kind) {
                if (Storage::disk('local')->exists($this->artifactPath($job, $kind, $labelSku))) {
                    $received++;
                }
            }
        }

        return [
            'received' => $received,
            'expected' => $expected,
        ];
    }

    public function piPath(WarehouseRestockDocumentJob $job): string
    {
        return $this->root($job).'/PI.xlsx';
    }

    public function packagePath(WarehouseRestockDocumentJob $job): string
    {
        return $this->root($job).'/'.$job->reference.'.zip';
    }

    private function root(WarehouseRestockDocumentJob $job): string
    {
        return 'inventory/restock-documents/'.$job->reference;
    }

    private function artifactPath(
        WarehouseRestockDocumentJob $job,
        string $kind,
        string $labelSku,
    ): string {
        $directory = $kind === 'btw' ? 'blue-labels' : 'sku-labels';

        return $this->root($job).'/'.$directory.'/'.$labelSku.'.'.$kind;
    }

    private function generatePi(WarehouseRestockDocumentJob $job): string
    {
        $template = base_path('ops/label-generator/templates/007-PI-sea-canonical-v1.xlsx');
        if (! is_file($template) || ! hash_equals(self::PI_TEMPLATE_SHA256, (string) hash_file('sha256', $template))) {
            throw ValidationException::withMessages(['worker_job' => 'Canonical PI 模板缺失或 SHA-256 漂移。']);
        }

        $items = array_values((array) ($job->payload['items'] ?? []));
        if (count($items) < 1 || count($items) > self::MAX_PI_ITEMS) {
            throw ValidationException::withMessages([
                'worker_job' => 'PI V1 仅支持 1–'.self::MAX_PI_ITEMS.' 个 SKU。',
            ]);
        }

        $temp = tempnam(sys_get_temp_dir(), 'byl-pi-');
        if (! is_string($temp) || ! copy($template, $temp)) {
            throw ValidationException::withMessages(['worker_job' => 'PI 模板复制失败。']);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($temp) !== true) {
                throw ValidationException::withMessages(['worker_job' => 'PI 模板无法打开。']);
            }

            try {
                $sheetPath = 'xl/worksheets/sheet1.xml';
                $xml = $zip->getFromName($sheetPath);
                if (! is_string($xml) || $xml === '') {
                    throw ValidationException::withMessages(['worker_job' => 'PI 模板缺少 PI worksheet。']);
                }

                $updated = $this->rewritePiSheet($xml, $job, $items);
                if (! $zip->addFromString($sheetPath, $updated)) {
                    throw ValidationException::withMessages(['worker_job' => 'PI worksheet 写入失败。']);
                }
            } finally {
                $zip->close();
            }

            $bytes = file_get_contents($temp);
            if (! is_string($bytes)) {
                throw ValidationException::withMessages(['worker_job' => 'PI.xlsx 读取失败。']);
            }

            return $bytes;
        } finally {
            @unlink($temp);
        }
    }

    private function rewritePiSheet(
        string $xml,
        WarehouseRestockDocumentJob $job,
        array $items,
    ): string {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (! $doc->loadXML($xml, LIBXML_NONET)) {
            throw ValidationException::withMessages(['worker_job' => 'PI worksheet XML 无效。']);
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $this->setCell($doc, $xpath, 'B2', (string) ($job->payload['plan_reference'] ?? $job->reference), true);
        $this->setCell($doc, $xpath, 'B3', (string) ($job->payload['confirmed_date'] ?? ''), true);

        $total = 0;
        for ($slot = 0; $slot < self::MAX_PI_ITEMS; $slot++) {
            $row = 5 + $slot;
            $item = $items[$slot] ?? null;

            if (! is_array($item)) {
                foreach (range('A', 'N') as $column) $this->setCell($doc, $xpath, $column.$row, null, false);
                continue;
            }

            $quantity = (int) ($item['restock_qty'] ?? 0);
            $total += $quantity;

            $this->setCell($doc, $xpath, 'A'.$row, $slot + 1, false);
            $this->setCell($doc, $xpath, 'B'.$row, (string) ($item['label_sku'] ?? ''), true);
            $this->setCell($doc, $xpath, 'C'.$row, (string) ($item['product_name'] ?? ''), true);
            $this->setCell($doc, $xpath, 'D'.$row, $quantity, false);
            $this->setCell($doc, $xpath, 'E'.$row, $item['units_per_carton'] ?? null, false);
            $this->setCell($doc, $xpath, 'F'.$row, $item['carton_count'] ?? null, false);
            $this->setCell($doc, $xpath, 'G'.$row, $this->dimensions($item), true);
            $this->setCell($doc, $xpath, 'H'.$row, $item['packaged_weight_kg'] ?? null, false);
            $this->setCell($doc, $xpath, 'I'.$row, null, false);
            $this->setCell($doc, $xpath, 'J'.$row, null, false);
            $this->setCell($doc, $xpath, 'K'.$row, $item['quantity_available'] ?? null, false);
            $this->setCell($doc, $xpath, 'L'.$row, null, false);
            $this->setCell($doc, $xpath, 'M'.$row, null, false);
            $this->setCell($doc, $xpath, 'N'.$row, null, false);
        }

        $this->setCell($doc, $xpath, 'D24', $total, false, preserveFormula: true);

        return (string) $doc->saveXML();
    }

    private function setCell(
        \DOMDocument $doc,
        \DOMXPath $xpath,
        string $reference,
        mixed $value,
        bool $text,
        bool $preserveFormula = false,
    ): void {
        $cell = $xpath->query('//m:c[@r="'.$reference.'"]')->item(0);
        if (! $cell instanceof \DOMElement) {
            throw ValidationException::withMessages(['worker_job' => 'PI 模板缺少单元格：'.$reference]);
        }

        foreach (iterator_to_array($cell->childNodes) as $child) {
            if ($preserveFormula && $child instanceof \DOMElement && $child->localName === 'f') continue;
            $cell->removeChild($child);
        }

        if ($value === null || $value === '') {
            $cell->removeAttribute('t');
            return;
        }

        if ($text) {
            $cell->setAttribute('t', 'inlineStr');
            $is = $doc->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
            $t = $doc->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($doc->createTextNode((string) $value));
            $is->appendChild($t);
            $cell->appendChild($is);
            return;
        }

        $cell->removeAttribute('t');
        $v = $doc->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');
        $v->appendChild($doc->createTextNode($this->number($value)));
        $cell->appendChild($v);
    }

    private function dimensions(array $item): ?string
    {
        $values = [
            $item['packaging_length_cm'] ?? null,
            $item['packaging_width_cm'] ?? null,
            $item['packaging_height_cm'] ?? null,
        ];
        foreach ($values as $value) if ($value === null || $value === '') return null;

        return implode('×', array_map(fn ($value): string => $this->number($value), $values)).' cm';
    }

    private function number(mixed $value): string
    {
        if (is_int($value)) return (string) $value;
        $formatted = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
