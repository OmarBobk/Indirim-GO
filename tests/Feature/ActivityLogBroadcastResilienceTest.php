<?php

declare(strict_types=1);

use App\Events\ActivityLogChanged;
use App\Support\ActivityLogBroadcaster;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

function m13InstallFailingActivityLogBroadcaster(string $exceptionMessage): void
{
    $exceptionMessageWithSignedQuery = $exceptionMessage;

    app('Illuminate\Broadcasting\BroadcastManager')->extend('failing', function () use ($exceptionMessageWithSignedQuery) {
        return new class($exceptionMessageWithSignedQuery) extends NullBroadcaster
        {
            public function __construct(private readonly string $failureMessage) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException($this->failureMessage);
            }
        };
    });

    config([
        'broadcasting.default' => 'failing',
        'broadcasting.connections.failing' => [
            'driver' => 'failing',
        ],
    ]);
}

test('activity log broadcast failures are isolated and logged without secret-bearing messages', function () {
    $signedFailure = 'Pusher error contacting http://127.0.0.1:8080/apps/demo/events'
        .'?auth_key=demo-key&auth_signature=signed-demo-signature&auth_timestamp=1710000000'
        .': cURL error 7: Failed to connect to 127.0.0.1 port 8080';

    m13InstallFailingActivityLogBroadcaster($signedFailure);

    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];
        }
    });

    ActivityLogBroadcaster::dispatchCreated(42);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toBe('Activity log broadcast failed')
        ->and($warnings[0]['context'])->toMatchArray([
            'error_id' => 'activity_log_broadcast_failed',
            'activity_id' => 42,
            'exception_class' => BroadcastException::class,
        ])
        ->and($warnings[0]['context'])->not->toHaveKey('message')
        ->and(json_encode($warnings[0], JSON_THROW_ON_ERROR))
        ->not->toContain(
            'auth_key=',
            'auth_signature=',
            'signed-demo-signature',
            'demo-key',
            $signedFailure,
        );
});

test('successful activity log broadcasts still publish ActivityLogChanged', function () {
    Event::fake([ActivityLogChanged::class]);

    ActivityLogBroadcaster::dispatchCreated(77);

    Event::assertDispatched(ActivityLogChanged::class, function (ActivityLogChanged $event): bool {
        return $event->activityId === 77
            && $event->reason === 'created';
    });
});

test('activity creation still dispatches through the isolated broadcaster', function () {
    Event::fake([ActivityLogChanged::class]);

    $activity = activity()
        ->inLog('admin')
        ->event('user.login')
        ->log('User login');

    Event::assertDispatched(ActivityLogChanged::class, function (ActivityLogChanged $event) use ($activity): bool {
        return $event->activityId === $activity->id
            && $event->reason === 'created';
    });
});
