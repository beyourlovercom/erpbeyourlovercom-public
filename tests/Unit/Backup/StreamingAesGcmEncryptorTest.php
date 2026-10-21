<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\StreamingAesGcmEncryptor;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

final class StreamingAesGcmEncryptorTest extends TestCase
{
    public function test_round_trip_uses_chunked_authenticated_container(): void
    {
        Config::set('backup.encryption.key', base64_encode(str_repeat('k', 32)));
        Config::set('backup.encryption.key_id', 'test-key-v1'); Config::set('backup.encryption.chunk_size', 17);
        $source = tempnam(base_path('storage/framework/testing'), 'backup-'); $encrypted = tempnam(base_path('storage/framework/testing'), 'backup-'); $plain = tempnam(base_path('storage/framework/testing'), 'backup-');
        file_put_contents($source, str_repeat('deterministic payload ', 100));
        $codec = new StreamingAesGcmEncryptor(); $codec->encrypt($source, $encrypted); $codec->decrypt($encrypted, $plain);
        $this->assertSame(file_get_contents($source), file_get_contents($plain)); $this->assertGreaterThan(1, $codec->inspect($encrypted)['chunks']);
        @unlink($source); @unlink($encrypted); @unlink($plain);
    }

    public function test_tampering_is_rejected(): void
    {
        Config::set('backup.encryption.key', base64_encode(str_repeat('k', 32))); Config::set('backup.encryption.chunk_size', 64);
        $source = tempnam(base_path('storage/framework/testing'), 'backup-'); $encrypted = tempnam(base_path('storage/framework/testing'), 'backup-'); $plain = tempnam(base_path('storage/framework/testing'), 'backup-'); file_put_contents($source, 'secret-free test content');
        $codec = new StreamingAesGcmEncryptor(); $codec->encrypt($source, $encrypted); $bytes = file_get_contents($encrypted); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); file_put_contents($encrypted, $bytes);
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('authentication failed'); $codec->decrypt($encrypted, $plain);
        @unlink($source); @unlink($encrypted); @unlink($plain);
    }

    public function test_empty_input_is_rejected_for_backup_transport(): void
    {
        Config::set('backup.encryption.key', base64_encode(str_repeat('k', 32)));
        $source = tempnam(base_path('storage/framework/testing'), 'backup-');
        $encrypted = tempnam(base_path('storage/framework/testing'), 'backup-');
        file_put_contents($source, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be empty');
        try {
            (new StreamingAesGcmEncryptor())->encrypt($source, $encrypted);
        } finally {
            @unlink($source); @unlink($encrypted);
        }
    }

    public function test_truncating_a_complete_final_chunk_is_rejected(): void
    {
        $this->expectContainerFailure(static function (string $bytes, string $encrypted): void {
            $headerLength = unpack('N', substr($bytes, 8, 4))[1];
            $offset = 12 + $headerLength; $last = $offset;
            while ($offset < strlen($bytes)) { $length = unpack('N', substr($bytes, $offset + 4, 4))[1]; $last = $offset; $offset += 8 + $length + 16; }
            file_put_contents($encrypted, substr($bytes, 0, $last));
        });
    }

    public function test_appended_record_and_short_record_are_rejected(): void
    {
        foreach ([
            static function (string $bytes, string $encrypted): void { file_put_contents($encrypted, $bytes.$bytes); },
            static function (string $bytes, string $encrypted): void { file_put_contents($encrypted, substr($bytes, 0, -1)); },
        ] as $mutate) {
            $this->expectContainerFailure($mutate);
        }
    }

    /** @param callable(string,string):void $mutate */
    private function expectContainerFailure(callable $mutate): void
    {
        Config::set('backup.encryption.key', base64_encode(str_repeat('k', 32))); Config::set('backup.encryption.chunk_size', 17);
        $source = tempnam(base_path('storage/framework/testing'), 'backup-'); $encrypted = tempnam(base_path('storage/framework/testing'), 'backup-'); $plain = tempnam(base_path('storage/framework/testing'), 'backup-'); file_put_contents($source, str_repeat('payload', 20));
        $codec = new StreamingAesGcmEncryptor(); $codec->encrypt($source, $encrypted); $bytes = file_get_contents($encrypted); $mutate($bytes, $encrypted);
        try { $codec->decrypt($encrypted, $plain); $this->fail('Malformed container was accepted.'); } catch (RuntimeException $e) { $this->assertStringContainsString('encrypted backup', strtolower($e->getMessage())); }
        $this->assertFileDoesNotExist($plain); @unlink($source); @unlink($encrypted); @unlink($plain);
    }
}
