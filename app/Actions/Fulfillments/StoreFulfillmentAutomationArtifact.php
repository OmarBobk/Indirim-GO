<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreFulfillmentAutomationArtifact
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(
        FulfillmentAutomationRun $run,
        UploadedFile $file,
        string $label = 'screenshot',
    ): string {
        $directory = $this->automationService->artifactStorageDirectory($run->uuid);
        $filename = Str::slug($label).'-'.now()->format('YmdHis').'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, 'local');

        $meta = $run->meta ?? [];
        $artifactPaths = $meta['artifact_paths'] ?? [];
        $artifactPaths[] = $path;
        $meta['artifact_paths'] = $artifactPaths;

        $run->update(['meta' => $meta]);

        app(BroadcastAutomationRunChanged::class)->handle(
            $run->uuid,
            'artifact',
            $run->status->value,
        );

        return $path;
    }

    public function storeBytes(
        FulfillmentAutomationRun $run,
        string $contents,
        string $filename,
    ): string {
        $directory = $this->automationService->artifactStorageDirectory($run->uuid);
        $path = $directory.'/'.$filename;

        Storage::disk('local')->put($path, $contents);

        $meta = $run->meta ?? [];
        $artifactPaths = $meta['artifact_paths'] ?? [];
        $artifactPaths[] = $path;
        $meta['artifact_paths'] = $artifactPaths;

        $run->update(['meta' => $meta]);

        app(BroadcastAutomationRunChanged::class)->handle(
            $run->uuid,
            'artifact',
            $run->status->value,
        );

        return $path;
    }
}
