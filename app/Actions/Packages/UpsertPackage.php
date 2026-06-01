<?php

namespace App\Actions\Packages;

use App\Models\Package;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UpsertPackage
{
    /**
     * @param  array{category_id:int,name:string,description:?string,is_active:bool,order:int,icon:?string,fulfillment_provider:?string}  $data
     */
    public function handle(?int $packageId, array $data, ?UploadedFile $imageFile): Package
    {
        $package = $packageId !== null
            ? Package::query()->findOrFail($packageId)
            : new Package;

        $imagePath = null;

        if ($imageFile !== null) {
            $directory = public_path('images/packages');

            File::ensureDirectoryExists($directory);

            $extension = $imageFile->getClientOriginalExtension();

            if ($extension === '') {
                $extension = $imageFile->guessExtension() ?? 'jpg';
            }

            $filename = Str::uuid().'.'.$extension;

            $imagePath = 'images/packages/'.$filename;
            $destination = public_path($imagePath);

            File::copy($imageFile->getRealPath(), $destination);
        }

        $package->fill([
            'category_id' => $data['category_id'],
            'name' => trim($data['name']),
            'description' => $data['description'] !== null && trim($data['description']) !== '' ? trim($data['description']) : null,
            'is_active' => $data['is_active'],
            'fulfillment_provider' => self::normalizeFulfillmentProvider($data['fulfillment_provider'] ?? null),
            'order' => $data['order'],
            'icon' => $data['icon'],
            'image' => $imagePath ?? $package->image,
        ]);

        $package->save();

        return $package;
    }

    public static function normalizeFulfillmentProvider(?string $provider): ?string
    {
        if ($provider === null || $provider === '' || $provider === 'manual') {
            return null;
        }

        return $provider;
    }
}
