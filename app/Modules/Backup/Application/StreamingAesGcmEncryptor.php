<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\BackupEncryptor;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use RuntimeException;

final class StreamingAesGcmEncryptor implements BackupEncryptor, RestoreEncryptor
{
    private const MAGIC = 'BYLENC01';
    private const TAG_BYTES = 16;
    private const NONCE_PREFIX_BYTES = 8;
    private const MAX_CHUNK_SIZE = 64 * 1024 * 1024;
    private const MAX_HEADER_SIZE = 65536;

    public function encrypt(string $source, string $destination): void
    {
        $key = $this->key();
        $chunkSize = $this->validatedChunkSize((int) config('backup.encryption.chunk_size', 1024 * 1024));
        $plainSize = @filesize($source);
        if ($plainSize === false || $plainSize < 0) throw new RuntimeException('Unable to determine backup encryption input size.');
        if ($plainSize === 0) throw new RuntimeException('Backup encryption input must not be empty.');
        $chunkCount = intdiv($plainSize, $chunkSize) + (($plainSize % $chunkSize) === 0 ? 0 : 1);
        if ($chunkCount > 0xffffffff) throw new RuntimeException('Backup encryption input is too large.');
        $baseNonce = random_bytes(self::NONCE_PREFIX_BYTES);
        $header = json_encode(['version' => 1, 'algorithm' => 'AES-256-GCM', 'chunk_size' => $chunkSize, 'plaintext_size' => $plainSize, 'chunk_count' => $chunkCount, 'nonce_prefix' => bin2hex($baseNonce), 'key_id' => config('backup.encryption.key_id')], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($header) > self::MAX_HEADER_SIZE) throw new RuntimeException('Backup encryption header is too large.');
        $in = fopen($source, 'rb'); $out = null;
        try {
            $out = fopen($destination, 'wb');
            if (! is_resource($out) || ! is_resource($in)) throw new RuntimeException('Unable to open backup encryption streams.');
            $this->writeAll($out, self::MAGIC.pack('N', strlen($header)).$header);
            for ($index = 0; $index < $chunkCount; $index++) {
                $remaining = $plainSize - ($index * $chunkSize);
                $expectedLength = min($chunkSize, $remaining);
                $plain = $this->readExact($in, $expectedLength);
                $aad = $header.pack('N2', $index, $expectedLength); $tag = '';
                $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $this->nonce($baseNonce, $index), $tag, $aad);
                if ($cipher === false || strlen($tag) !== self::TAG_BYTES) throw new RuntimeException('AES-256-GCM encryption failed.');
                $this->writeAll($out, pack('N2', $index, strlen($cipher)).$cipher.$tag);
            }
            if ($this->readAtMost($in, 1) !== '') throw new RuntimeException('Backup encryption input changed during encryption.');
        } catch (\Throwable $e) {
            if (is_resource($out)) fclose($out); if (is_resource($in)) fclose($in); @unlink($destination); throw $e;
        }
        fclose($in); fclose($out);
    }

    /** @return array{header: array<string,mixed>, chunks: int} */
    public function inspect(string $source): array
    {
        $in = fopen($source, 'rb'); if (! is_resource($in)) throw new RuntimeException('Unable to open encrypted backup.');
        try { $headerJson = $this->readHeader($in); $header = $this->validateHeader($headerJson); $chunks = 0; $bytes = 0;
            $expectedIndex = 0;
            while (($record = $this->readAtMost($in, 8)) !== '') { if (strlen($record) !== 8) throw new RuntimeException('Truncated encrypted backup container.'); $index = $this->readUIntFrom($record, 0); $length = $this->readUIntFrom($record, 4); if ($index !== $expectedIndex || $length !== $this->expectedRecordLength($index, $header)) throw new RuntimeException('Invalid encrypted backup chunk sequence.'); $this->readExact($in, $length + self::TAG_BYTES); $chunks++; $bytes += $length; $expectedIndex++; }
            if ($chunks !== (int) $header['chunk_count'] || $bytes !== (int) $header['plaintext_size']) throw new RuntimeException('Invalid encrypted backup finalization.');
        } finally { fclose($in); }
        return ['header' => $header, 'chunks' => $chunks];
    }

    public function decrypt(string $source, string $destination): void
    {
        $key = $this->key(); $in = fopen($source, 'rb'); $out = null;
        try { if (! is_resource($in)) throw new RuntimeException('Unable to open encrypted backup.'); $headerJson = $this->readHeader($in); $header = $this->validateHeader($headerJson); $prefix = hex2bin((string) $header['nonce_prefix']);
            $out = fopen($destination, 'wb'); if (! is_resource($out)) throw new RuntimeException('Unable to open backup decryption output.'); $expected = 0; $total = 0;
            while (($record = $this->readAtMost($in, 8)) !== '') { if (strlen($record) !== 8) throw new RuntimeException('Truncated encrypted backup container.'); $index = $this->readUIntFrom($record, 0); $length = $this->readUIntFrom($record, 4); $this->validateRecordLength($length, (int) $header['chunk_size']); if ($index !== $expected || $length !== $this->expectedRecordLength($index, $header)) throw new RuntimeException('Invalid encrypted backup chunk sequence.'); $cipher = $this->readExact($in, $length); $tag = $this->readExact($in, self::TAG_BYTES); $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $this->nonce($prefix, $index), $tag, $headerJson.pack('N2', $index, $length)); if ($plain === false) throw new RuntimeException('Encrypted backup authentication failed.'); $this->writeAll($out, $plain); $total += strlen($plain); $expected++; }
            if ($expected !== (int) $header['chunk_count'] || $total !== (int) $header['plaintext_size']) throw new RuntimeException('Invalid encrypted backup finalization.');
        } catch (\Throwable $e) { if (is_resource($in)) fclose($in); if (is_resource($out)) fclose($out); @unlink($destination); throw $e; }
        if (is_resource($in)) fclose($in); if (is_resource($out)) fclose($out);
    }

    private function key(): string
    {
        $configured = config('backup.encryption.key');
        $key = is_string($configured) ? base64_decode($configured, true) : false;
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be a base64-encoded 256-bit runtime secret.');
        return $key;
    }

    private function nonce(string $prefix, int $index): string { return $prefix.pack('N', $index); }
    private function validatedChunkSize(int $size): int { if ($size < 1 || $size > self::MAX_CHUNK_SIZE) throw new RuntimeException('Invalid backup encryption chunk size.'); return $size; }
    private function validateRecordLength(int $length, int $chunkSize): void { if ($length < 1 || $length > self::MAX_CHUNK_SIZE || $length > $chunkSize) throw new RuntimeException('Invalid encrypted backup chunk length.'); }
    private function readHeader($stream): string { if ($this->readAtMost($stream, 8) !== self::MAGIC) throw new RuntimeException('Invalid encrypted backup container.'); $headerLength = $this->readUInt($stream); if ($headerLength < 2 || $headerLength > self::MAX_HEADER_SIZE) throw new RuntimeException('Invalid encrypted backup header.'); return $this->readExact($stream, $headerLength); }
    private function validateHeader(string $json): array { $header = json_decode($json, true, 512, JSON_THROW_ON_ERROR); if (($header['version'] ?? null) !== 1 || ($header['algorithm'] ?? null) !== 'AES-256-GCM' || !is_int($header['chunk_size'] ?? null) || !is_int($header['plaintext_size'] ?? null) || !is_int($header['chunk_count'] ?? null) || $header['plaintext_size'] <= 0 || $header['chunk_count'] <= 0 || $header['chunk_count'] > 0xffffffff || $header['plaintext_size'] > PHP_INT_MAX) throw new RuntimeException('Unsupported encrypted backup container.'); $this->validatedChunkSize($header['chunk_size']); $expectedCount = intdiv($header['plaintext_size'], $header['chunk_size']) + (($header['plaintext_size'] % $header['chunk_size']) === 0 ? 0 : 1); if ($header['chunk_count'] !== $expectedCount) throw new RuntimeException('Invalid encrypted backup header.'); $prefix = hex2bin((string) ($header['nonce_prefix'] ?? '')); if ($prefix === false || strlen($prefix) !== self::NONCE_PREFIX_BYTES) throw new RuntimeException('Invalid encrypted backup nonce.'); return $header; }
    private function expectedRecordLength(int $index, array $header): int { if ($index < 0 || $index >= (int) $header['chunk_count']) throw new RuntimeException('Invalid encrypted backup chunk sequence.'); $remaining = (int) $header['plaintext_size'] - ($index * (int) $header['chunk_size']); return min((int) $header['chunk_size'], $remaining); }
    private function readUInt($stream): int { return $this->readUIntFrom($this->readExact($stream, 4), 0); }
    private function readExact($stream, int $length): string { if ($length < 0) throw new RuntimeException('Invalid encrypted backup length.'); $value = ''; while (strlen($value) < $length) { $part = fread($stream, $length - strlen($value)); if ($part === false || $part === '') throw new RuntimeException('Truncated encrypted backup container.'); $value .= $part; } return $value; }
    private function readAtMost($stream, int $length): string { $value = fread($stream, $length); if ($value === false) throw new RuntimeException('Unable to read encrypted backup stream.'); return $value; }
    private function writeAll($stream, string $value): void { $offset = 0; while ($offset < strlen($value)) { $written = fwrite($stream, substr($value, $offset)); if ($written === false || $written === 0) throw new RuntimeException('Unable to write encrypted backup stream.'); $offset += $written; } }
    private function readUIntFrom(string $bytes, int $offset): int { if (strlen($bytes) < $offset + 4) throw new RuntimeException('Truncated encrypted backup container.'); return (int) unpack('N', substr($bytes, $offset, 4))[1]; }
}
