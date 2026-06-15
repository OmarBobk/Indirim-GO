<?php

declare(strict_types=1);

use App\Livewire\Admin\AssistantChat;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('redirects guests away from admin assistant', function (): void {
    $this->get(route('admin.assistant.index'))
        ->assertRedirect('/login');
});

it('returns 404 for authenticated non-admin users', function (): void {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->get(route('admin.assistant.index'))
        ->assertNotFound();
});

it('allows admin to load assistant page', function (): void {
    $this->actingAs(assistantAdminUser())
        ->get(route('admin.assistant.index'))
        ->assertOk()
        ->assertSeeLivewire(AssistantChat::class)
        ->assertSee(__('messages.assistant_page_title'));
});
