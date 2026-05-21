<?php

declare(strict_types=1);

namespace App\Actions\PaymentMethods;

use App\Models\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UpsertPaymentMethod
{
    /**
     * @param  array{name: string, account_text: string, is_active: bool, sort_order: int}  $data
     */
    public function handle(?int $paymentMethodId, array $data, ?UploadedFile $imageFile, bool $removeImage = false): PaymentMethod
    {
        $paymentMethod = $paymentMethodId !== null
            ? PaymentMethod::query()->findOrFail($paymentMethodId)
            : new PaymentMethod;

        $imagePath = $paymentMethod->image;

        if ($removeImage && $imagePath !== null) {
            $absolute = public_path($imagePath);
            if (is_file($absolute)) {
                File::delete($absolute);
            }
            $imagePath = null;
        }

        if ($imageFile !== null) {
            $directory = public_path('images/payment-methods');
            File::ensureDirectoryExists($directory);

            $extension = $imageFile->getClientOriginalExtension();
            if ($extension === '') {
                $extension = $imageFile->guessExtension() ?? 'jpg';
            }

            $filename = Str::uuid().'.'.$extension;
            $imagePath = 'images/payment-methods/'.$filename;
            File::copy($imageFile->getRealPath(), public_path($imagePath));
        }

        $paymentMethod->fill([
            'name' => trim($data['name']),
            'account_text' => trim($data['account_text']),
            'is_active' => $data['is_active'],
            'sort_order' => max(0, (int) $data['sort_order']),
            'image' => $imagePath,
        ]);

        $paymentMethod->save();

        return $paymentMethod;
    }
}
