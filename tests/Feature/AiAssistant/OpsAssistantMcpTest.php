<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

it('denies unauthenticated access to mcp ops assistant endpoint', function (): void {
    if (! class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
        $this->markTestSkipped('laravel/mcp is not installed.');
    }

    $this->postJson('/mcp/ops-assistant', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'id' => 1,
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
    ])->assertUnauthorized();
});

it('denies non-admin authenticated access to mcp ops assistant endpoint', function (): void {
    if (! class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
        $this->markTestSkipped('laravel/mcp is not installed.');
    }

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->postJson('/mcp/ops-assistant', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass,
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ])
        ->assertNotFound();
});
