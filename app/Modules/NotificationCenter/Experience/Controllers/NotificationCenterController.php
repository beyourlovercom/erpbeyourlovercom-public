<?php

namespace App\Modules\NotificationCenter\Experience\Controllers;

use App\Modules\NotificationCenter\Application\NotificationCenterProjector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationCenterController
{
    public function __construct(private readonly NotificationCenterProjector $projector) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', ['notificationPage'=>$this->projector->page($request->user())]);
    }

    public function show(Request $request, string $notification): Response
    {
        $item = $this->owned($request, $notification);
        if ($item->read_at === null) $item->markAsRead();
        return Inertia::render('Notifications/Show', ['notification'=>$this->projector->item($item)]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $this->owned($request, $notification);
        if ($item->read_at === null) $item->markAsRead();
        $url = $item->data['action_url'] ?? null;
        return $url ? redirect()->to($url) : redirect()->route('notifications.show', $item->id);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $this->owned($request, $notification);
        if ($item->read_at === null) $item->markAsRead();
        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->owned($request, $notification)->delete();
        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->get()->filter(fn (DatabaseNotification $n) => $this->projector->allowed($request->user(), $n))->each->markAsRead();
        return back()->with('success', '通知已全部标记为已读');
    }

    private function owned(Request $request, string $id): DatabaseNotification
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        abort_unless($this->projector->allowed($request->user(), $notification), 403);
        return $notification;
    }
}
