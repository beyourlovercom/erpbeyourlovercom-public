<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\RestoreSource;
use App\Modules\Backup\Application\RestoreFailure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpGithubRestoreSource implements RestoreSource
{
    public function materialize(string $source, string $destination, string $expectedSha256): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            throw new RestoreFailure('encrypted_checksum_invalid');
        }
        if (! preg_match('#^github://([^/]+)/([^/]+)/([^/]+)/([^/]+)$#', $source, $match)) {
            throw new RestoreFailure('github_source_invalid');
        }

        $repository = $match[1].'/'.$match[2];
        $tag = rawurldecode($match[3]);
        $assetName = rawurldecode($match[4]);
        if (str_contains($tag, '/') || str_contains($assetName, '/') || $tag === '' || $assetName === '') {
            throw new RestoreFailure('github_source_invalid');
        }
        if ($repository !== (string) config('backup.github.repository', 'beyourlovercom/erp-backups')) {
            throw new RestoreFailure('github_repository_mismatch');
        }

        $token = config('backup.github.token');
        $base = rtrim((string) config('backup.github.api_base', 'https://api.github.com'), '/');
        if (! is_string($token) || trim($token) === '' || $base === '') {
            throw new RestoreFailure('github_token_unavailable');
        }

        $root = $this->backupRoot();
        $this->assertDestination($destination, $root);
        $tmp = $destination.'.part-'.bin2hex(random_bytes(4));

        try {
            $client = $this->client($token);
            $repo = $client->get($base.'/repos/'.$repository);
            if (! $repo->successful()) {
                throw new RestoreFailure('github_repository_unavailable');
            }
            if ($repo->json('private') !== true) {
                throw new RestoreFailure('github_repository_not_private');
            }

            $release = $client->get($base.'/repos/'.$repository.'/releases/tags/'.rawurlencode($tag));
            if (! $release->successful()) {
                throw new RestoreFailure('github_release_unavailable');
            }
            $releaseData = $release->json();
            if (! is_array($releaseData) || ! is_array($releaseData['assets'] ?? null)) {
                throw new RestoreFailure('github_release_unavailable');
            }

            $asset = collect($releaseData['assets'])->first(
                static fn (mixed $item): bool => is_array($item) && ($item['name'] ?? null) === $assetName
            );
            if (! is_array($asset)) {
                throw new RestoreFailure('github_asset_unavailable');
            }

            $size = $asset['size'] ?? null;
            $url = $asset['url'] ?? null;
            $digest = $asset['digest'] ?? null;
            $max = (int) config('backup.github.max_asset_bytes', 2147483647);
            if (! is_int($size) || $size < 1 || $size > $max || ! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RestoreFailure('github_asset_metadata_invalid');
            }
            $expectedAssetPrefix = $base.'/repos/'.$repository.'/releases/assets/';
            if (! str_starts_with($url, $expectedAssetPrefix) || ! preg_match('/^[0-9]+$/', substr($url, strlen($expectedAssetPrefix)))) {
                throw new RestoreFailure('github_asset_metadata_invalid');
            }
            if ($digest !== null && (! is_string($digest) || ! hash_equals('sha256:'.$expectedSha256, $digest))) {
                throw new RestoreFailure('github_asset_digest_mismatch');
            }

            $download = $this->client($token)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->timeout((int) config('backup.github.upload_timeout', 3600))
                ->sink($tmp)
                ->get($url);
            if (! $download->successful() || ! is_file($tmp) || is_link($tmp)) {
                throw new RestoreFailure('github_download_failed');
            }
            $actualSize = filesize($tmp);
            $actualSha = hash_file('sha256', $tmp);
            if ($actualSize !== $size || $actualSha === false || ! hash_equals($expectedSha256, $actualSha)) {
                throw new RestoreFailure('github_download_verification_failed');
            }
            if (! rename($tmp, $destination)) {
                throw new RestoreFailure('source_unavailable');
            }
        } catch (RestoreFailure $e) {
            @unlink($tmp);
            @unlink($destination);
            throw $e;
        } catch (Throwable) {
            @unlink($tmp);
            @unlink($destination);
            throw new RestoreFailure('github_download_failed');
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => (string) config('backup.github.api_version', '2026-03-10'),
            'User-Agent' => (string) config('backup.github.user_agent', 'BYL-ERP-Backup'),
        ])->timeout((int) config('backup.github.timeout', 30))
            ->connectTimeout((int) config('backup.github.connect_timeout', 10));
    }

    private function backupRoot(): string
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        $real = realpath($root);
        if ($real === false || ! is_dir($real) || is_link($root)) {
            throw new RestoreFailure('workspace_unavailable');
        }
        return $real;
    }

    private function assertDestination(string $destination, string $root): void
    {
        if (! str_ends_with($destination, '.tar.zst.enc') || file_exists($destination) || is_link($destination)) {
            throw new RestoreFailure('restore_destination_invalid');
        }
        $restoreRoot = realpath($root.'/restore');
        $parent = realpath(dirname($destination));
        if ($restoreRoot === false || $parent === false || ! $this->within($parent, $restoreRoot)) {
            throw new RestoreFailure('restore_destination_invalid');
        }
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
