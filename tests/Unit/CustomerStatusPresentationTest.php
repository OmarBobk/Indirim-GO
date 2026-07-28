<?php

declare(strict_types=1);

use App\Support\CustomerStatusPresentation;

it('maps customer statuses to a shared badge palette', function (string $status, string $color): void {
    expect(CustomerStatusPresentation::badgeColor($status))->toBe($color);
})->with([
    ['paid', 'green'],
    ['pending', 'amber'],
    ['failed', 'red'],
    ['unread', 'sky'],
    ['unknown-state', 'zinc'],
]);

it('maps activity status tokens to the shared flux badge palette', function (string $token, string $color): void {
    expect(CustomerStatusPresentation::activityBadgeColor($token))->toBe($color);
})->with([
    ['success', 'green'],
    ['warning', 'amber'],
    ['danger', 'red'],
    ['progress', 'sky'],
    ['neutral', 'zinc'],
]);
