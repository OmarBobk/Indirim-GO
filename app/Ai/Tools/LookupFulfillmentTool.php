<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\AiAssistant\FetchFulfillmentData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class LookupFulfillmentTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up fulfillment by numeric ID with automation run and logs. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $fulfillmentId = (int) $request->integer('fulfillment_id');

        return AssistantLookupFormatter::fulfillment(
            app(FetchFulfillmentData::class)->handle($fulfillmentId),
            'Fulfillment not found: #'.$fulfillmentId,
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fulfillment_id' => $schema->integer()
                ->description('Numeric fulfillment primary key')
                ->required(),
        ];
    }
}
