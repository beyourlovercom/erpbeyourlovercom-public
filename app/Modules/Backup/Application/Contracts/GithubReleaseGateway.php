<?php

namespace App\Modules\Backup\Application\Contracts;

use App\Modules\Backup\Application\GithubReleasePublishData;
use App\Modules\Backup\Application\GithubReleasePublicationData;

interface GithubReleaseGateway
{
    public function publish(GithubReleasePublishData $publication): GithubReleasePublicationData;
}
