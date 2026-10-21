<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\ChecksumService;
use Tests\TestCase;

final class ChecksumServiceTest extends TestCase
{
    public function test_checksums_are_sha256_and_sorted_by_component_name(): void
    {
        $a = tempnam(base_path('storage/framework/testing'), 'backup-'); $b = tempnam(base_path('storage/framework/testing'), 'backup-'); $out = tempnam(base_path('storage/framework/testing'), 'backup-'); file_put_contents($a, 'a'); file_put_contents($b, 'b');
        (new ChecksumService())->write($out, ['z-component' => $b, 'a-component' => $a]);
        $this->assertSame(hash_file('sha256', $a).'  a-component'.PHP_EOL.hash_file('sha256', $b).'  z-component'.PHP_EOL, file_get_contents($out));
        @unlink($a); @unlink($b); @unlink($out);
    }
}
