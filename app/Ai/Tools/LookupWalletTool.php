<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\AiAssistant\FetchWalletData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class LookupWalletTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up customer wallet by username or numeric user ID. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $usernameOrId = (string) $request->string('username_or_id');

        return AssistantLookupFormatter::wallet(
            app(FetchWalletData::class)->handle($usernameOrId),
            'User not found: '.$usernameOrId,
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'username_or_id' => $schema->string()
                ->description('Customer username or numeric user ID')
                ->required(),
        ];
    }
}
