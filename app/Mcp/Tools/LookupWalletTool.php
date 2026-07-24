<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchWalletData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupWalletTool extends Tool
{
    protected string $description = 'Look up customer wallet by username or numeric user ID. Read-only.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'username_or_id' => $schema->string()
                ->description('Customer username or numeric user ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $usernameOrId = (string) $request->get('username_or_id');

        return Response::text(AssistantLookupFormatter::wallet(
            app(FetchWalletData::class)->handle($usernameOrId),
            'User not found: '.$usernameOrId,
        ));
    }
}
