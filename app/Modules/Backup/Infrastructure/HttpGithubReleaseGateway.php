<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\GithubReleasePublishData;
use App\Modules\Backup\Application\GithubReleasePublicationData;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Application\Exceptions\GithubReleasePublishException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpGithubReleaseGateway implements GithubReleaseGateway
{
    public function publish(GithubReleasePublishData $publication): GithubReleasePublicationData
    {
        $token = config('backup.github.token');
        $base = trim((string) config('backup.github.api_base', 'https://api.github.com'), '/');
        if (! is_string($token) || trim($token) === '' || $publication->repository === '' || $base === '') throw GithubReleasePublishException::failed();
        try {
            $client = $this->client($base, $token, (int) config('backup.github.timeout', 30), (int) config('backup.github.connect_timeout', 10));
            $repo = $client->get($this->apiUrl($base, '/repos/'.$publication->repository));
            if (! $repo->successful() || $repo->json('private') !== true) throw GithubReleasePublishException::failed();

            $release = $client->get($this->apiUrl($base, '/repos/'.$publication->repository.'/releases/tags/'.rawurlencode($publication->tag)));
            if ($release->status() === 404) {
                $release = $client->post($this->apiUrl($base, '/repos/'.$publication->repository.'/releases'), [
                    'tag_name' => $publication->tag,
                    'name' => $publication->tag,
                    'body' => $this->body($publication),
                    'draft' => false,
                    'prerelease' => false,
                ]);
            }
            if (! $release->successful()) throw GithubReleasePublishException::failed();
            $releaseData = $release->json();
            if (! is_array($releaseData)) throw GithubReleasePublishException::failed();
            $releaseId = $this->nonEmptyString($releaseData['id'] ?? null);
            if ($releaseId === null) throw GithubReleasePublishException::failed();
            $assets = is_array($releaseData['assets'] ?? null) ? $releaseData['assets'] : $client->get($this->apiUrl($base, '/repos/'.$publication->repository.'/releases/'.rawurlencode($releaseId).'/assets'))->json();
            if (! is_array($assets)) throw GithubReleasePublishException::failed();
            $existing = collect(is_array($assets) ? $assets : [])->first(fn ($asset): bool => is_array($asset) && ($asset['name'] ?? null) === $publication->assetName);
            if (is_array($existing)) {
                if ((int) ($existing['size'] ?? -1) !== $publication->assetSize) throw GithubReleasePublishException::failed();
                $this->assertDigestMatches($existing, $publication->checksum);
                $asset = $existing;
            } else {
                $stream = fopen($publication->assetPath, 'rb');
                if ($stream === false) throw GithubReleasePublishException::failed();
                try {
                    $uploadUrl = $this->nonEmptyString($releaseData['upload_url'] ?? null);
                    if ($uploadUrl === null) throw GithubReleasePublishException::failed();
                    $uploadUrl = preg_replace('/\{\?.*$/', '', $uploadUrl);
                    if (! is_string($uploadUrl) || ! filter_var($uploadUrl, FILTER_VALIDATE_URL)) throw GithubReleasePublishException::failed();
                    $upload = $this->client('', $token, (int) config('backup.github.upload_timeout', 3600), (int) config('backup.github.connect_timeout', 10))->withBody(Utils::streamFor($stream), 'application/octet-stream')->withQueryParameters(['name' => $publication->assetName])->post($uploadUrl);
                    if (! $upload->successful()) throw GithubReleasePublishException::failed();
                    $asset = $upload->json();
                    if (! is_array($asset)) throw GithubReleasePublishException::failed();
                } finally { fclose($stream); }
            }
            $assetId = $this->nonEmptyString($asset['id'] ?? null);
            $assetName = $this->nonEmptyString($asset['name'] ?? null);
            $assetUrl = $this->nonEmptyString($asset['browser_download_url'] ?? $asset['url'] ?? null);
            if ($assetId === null || $assetName !== $publication->assetName || (int) ($asset['size'] ?? -1) !== $publication->assetSize || $assetUrl === null) throw GithubReleasePublishException::failed();
            $this->assertDigestMatches($asset, $publication->checksum);
            return new GithubReleasePublicationData($publication->repository, $releaseId, $publication->tag, (string) ($releaseData['html_url'] ?? ''), $assetId, $assetName, $assetUrl, (int) $asset['size'], $publication->checksum, now()->toIso8601String());
        } catch (GithubReleasePublishException $e) { throw $e; } catch (Throwable) { throw GithubReleasePublishException::failed(); }
    }

    private function client(string $base, string $token, int $timeout, int $connectTimeout): PendingRequest
    {
        return Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => (string) config('backup.github.api_version', '2026-03-10'), 'User-Agent' => (string) config('backup.github.user_agent', 'BYL-ERP-Backup')])->timeout($timeout)->connectTimeout($connectTimeout);
    }

    private function apiUrl(string $base, string $path): string
    { return rtrim($base, '/').'/'.ltrim($path, '/'); }

    private function nonEmptyString(mixed $value): ?string
    { return is_string($value) && trim($value) !== '' ? $value : (is_int($value) && $value > 0 ? (string) $value : null); }

    private function assertDigestMatches(array $asset, string $checksum): void
    { if (array_key_exists('digest', $asset) && $asset['digest'] !== 'sha256:'.$checksum) throw GithubReleasePublishException::failed(); }

    /** @return string */
    private function body(GithubReleasePublishData $publication): string
    {
        return "BYL ERP backup\n\nformat_version: {$publication->formatVersion}\nrun_uuid: {$publication->runUuid}\nbackup_type: {$publication->backupType}\nencrypted: true\nsha256: {$publication->checksum}\nasset_name: {$publication->assetName}\nasset_size: {$publication->assetSize}";
    }
}
