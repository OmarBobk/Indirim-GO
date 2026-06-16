<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchFulfillmentData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupFulfillmentTool extends Tool
{
    protected string $description = 'Look up fulfillment by numeric ID with automation run and logs. Read-only.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fulfillment_id' => $schema->integer()
                ->description('Numeric fulfillment primary key')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $fulfillmentId = (int) $request->get('fulfillment_id');

        return Response::text(AssistantLookupFormatter::fulfillment(
            app(FetchFulfillmentData::class)->handle($fulfillmentId),
            'Fulfillment not found: #'.$fulfillmentId,
        ));
    }
}
