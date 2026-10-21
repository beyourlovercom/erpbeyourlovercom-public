<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\GithubReleaseDeletionGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HttpGithubReleaseDeletionGateway implements GithubReleaseDeletionGateway
{
    public function delete(string $repository, string $releaseId, string $releaseTag): void
    {
        $token = config('backup.github.token');
        $base = trim((string) config('backup.github.api_base', 'https://api.github.com'), '/');
        $configuredRepository = (string) config('backup.github.repository');

        if (! is_string($token) || trim($token) === '' || $repository !== $configuredRepository || $releaseId === '' || ! ctype_digit($releaseId) || $base === '') {
            throw new RuntimeException('GitHub backup deletion is unavailable.');
        }

        if (! str_starts_with($releaseTag, 'byl-erp-backup-')) {
            throw new RuntimeException('GitHub backup release tag is invalid.');
        }

        try {
            $client = $this->client($token);
            $repo = $client->get($this->url($base, '/repos/'.$repository));
            if (! $repo->successful() || $repo->json('private') !== true) {
                throw new RuntimeException('GitHub backup repository is unavailable.');
            }

            $releaseUrl = $this->url($base, '/repos/'.$repository.'/releases/'.rawurlencode($releaseId));
            $releaseDelete = $client->delete($releaseUrl);
            if (! in_array($releaseDelete->status(), [204, 404], true)) {
                throw new RuntimeException('GitHub backup release deletion failed.');
            }

            $tagDelete = $client->delete($this->url($base, '/repos/'.$repository.'/git/refs/tags/'.rawurlencode($releaseTag)));
            if (! in_array($tagDelete->status(), [204, 404], true)) {
                throw new RuntimeException('GitHub backup tag deletion failed.');
            }

            if ($client->get($releaseUrl)->status() !== 404) {
                throw new RuntimeException('GitHub backup release deletion could not be verified.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('GitHub backup deletion failed.');
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

    private function url(string $base, string $path): string
    {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
