<?php

namespace App\Modules\NotificationCenter\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class BusinessNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
