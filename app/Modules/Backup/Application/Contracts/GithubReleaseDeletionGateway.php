<?php

namespace App\Modules\Backup\Application\Contracts;

interface GithubReleaseDeletionGateway
{
    public function delete(string $repository, string $releaseId, string $releaseTag): void;
}
