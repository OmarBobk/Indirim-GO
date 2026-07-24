<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Models\Package;

class TogglePackageFulfillment
{
    public function handle(int $packageId): void
    {
        $package = Package::query()->findOrFail($packageId);

        if ($package->isBrowserAutomated()) {
            $package->update([
                'fulfillment_provider' => null,
            ]);

            return;
        }

        $defaultProvider = self::defaultBrowserProvider();

        if ($defaultProvider === null) {
            return;
        }

        $package->update([
            'fulfillment_provider' => $defaultProvider,
        ]);
    }

    public static function defaultBrowserProvider(): ?string
    {
        $supplierKey = array_key_first(config('fulfillment_automation.suppliers', []));

        if (! is_string($supplierKey) || $supplierKey === '') {
            return null;
        }

        return 'browser:'.$supplierKey;
    }
}
