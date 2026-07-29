<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MobileApiException extends RuntimeException
{
    public function __construct(
        private readonly string $translationKey,
        private readonly string $machineCode,
        private readonly int $status,
    ) {
        parent::__construct($translationKey);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __($this->translationKey),
            'code' => $this->machineCode,
        ], $this->status);
    }
}
