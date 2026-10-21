<?php

namespace App\Modules\Runtime\Application;

enum QueueName: string
{
    case Default = 'default';
    case Runtime = 'runtime';
}
