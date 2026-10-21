<?php

namespace App\Modules\Runtime\Application;

final class QueueJobConvention
{
    public const int Tries = 3;

    public const int TimeoutSeconds = 60;

    public const array BackoffSeconds = [5, 15, 30];
}
