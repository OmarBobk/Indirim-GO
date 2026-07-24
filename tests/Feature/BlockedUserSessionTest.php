<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('blocked user is logged out on next request', function () {
    $user = User::factory()->create(['blocked_at' => null]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk();

    $user->forceFill(['blocked_at' => now()])->save();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', __('messages.session_ended_blocked'));
    $this->assertGuest();
});

test('inactive user is logged out on next request', function () {
    $user = User::factory()->create(['is_active' => true, 'blocked_at' => null]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', __('messages.session_ended_inactive'));
    $this->assertGuest();
});
