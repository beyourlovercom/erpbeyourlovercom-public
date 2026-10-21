<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

final class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_shares_only_the_authenticated_users_persistent_notifications(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $other = $this->userWith(['dashboard.view']);
        $publisher = app(NotificationPublisher::class);
        $publisher->publishToUser($user, ['category'=>'order','title'=>'订单需要处理','message'=>'订单 #1001 需要确认。','action_url'=>'/dashboard']);
        $publisher->publishToUser($other, ['category'=>'other','title'=>'不属于当前用户','message'=>'hidden']);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->has('notificationCenter.items', 1)
            ->where('notificationCenter.items.0.title', '订单需要处理'));
    }

    public function test_required_permission_is_enforced_at_publish_and_projection_time(): void
    {
        $allowed = $this->userWith(['inventory.packaging.review']);
        $blocked = $this->userWith(['dashboard.view']);
        $publisher = app(NotificationPublisher::class);

        $this->assertTrue($publisher->publishToUser($allowed, [
            'category'=>'inventory','title'=>'待审核','message'=>'包装记录待审核',
            'required_permission'=>'inventory.packaging.review','action_url'=>'/inventory/packaging',
        ]));
        $this->assertFalse($publisher->publishToUser($blocked, [
            'category'=>'inventory','title'=>'不应发送','message'=>'blocked',
            'required_permission'=>'inventory.packaging.review','action_url'=>'/inventory/packaging',
        ]));
        $this->assertCount(1, $allowed->fresh()->notifications);
        $this->assertCount(0, $blocked->fresh()->notifications);
    }

    public function test_open_marks_notification_read_and_redirects_to_internal_business_page(): void
    {
        $user = $this->userWith(['dashboard.view']);
        app(NotificationPublisher::class)->publishToUser($user, ['category'=>'other','title'=>'进入仪表盘','message'=>'查看业务','action_url'=>'/dashboard']);
        $notification = $user->notifications()->firstOrFail();

        $this->actingAs($user)->post("/notifications/{$notification->id}/open")
            ->assertRedirect('/dashboard');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_read_all_marks_visible_notifications_and_does_not_publish_a_second_persistent_event(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $publisher = app(NotificationPublisher::class);
        $publisher->publishToUser($user, ['title'=>'一','message'=>'A']);
        $publisher->publishToUser($user, ['title'=>'二','message'=>'B']);

        $this->actingAs($user)->post('/notifications/read-all')->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
        $this->assertSame(2, $user->fresh()->notifications()->count());
    }

    public function test_notification_action_url_must_be_internal(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $this->expectException(InvalidArgumentException::class);
        app(NotificationPublisher::class)->publishToUser($user, ['title'=>'Unsafe','message'=>'no','action_url'=>'https://example.com']);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create();
        $role = Role::create(['key'=>'notification-test-'.uniqid(),'label'=>'Notification Test']);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key'=>$key], ['label'=>$key]))->pluck('id'));
        $user->roles()->attach($role);
        return $user;
    }
}
