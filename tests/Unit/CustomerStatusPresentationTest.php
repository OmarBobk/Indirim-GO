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
