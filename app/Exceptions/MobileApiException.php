<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MobileApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        private readonly string $translationKey,
        private readonly string $machineCode,
        private readonly int $status,
        private readonly ?array $details = null,
    ) {
        parent::__construct($translationKey);
    }

    public function codeValue(): string
    {
        return $this->machineCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }

    public function render(Request $request): JsonResponse
    {
        $payload = [
            'message' => __($this->translationKey),
            'code' => $this->machineCode,
        ];

        if ($this->details !== null) {
            $payload['details'] = $this->details;
        }

        return response()->json($payload, $this->status);
    }
}
