<?php

declare(strict_types=1);

namespace App\Actions\PaymentMethods;

use App\Models\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class UpsertPaymentMethod
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

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
            $this->deleteImageFile($imagePath);
            $imagePath = null;
        }

        if ($imageFile !== null) {
            if ($imagePath !== null) {
                $this->deleteImageFile($imagePath);
            }

            $imagePath = $this->storeImageFile($imageFile);
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

    private function storeImageFile(UploadedFile $imageFile): string
    {
        $directory = public_path('images/payment-methods');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('Payment method image directory is not writable.');
        }

        $extension = strtolower($imageFile->getClientOriginalExtension() ?: $imageFile->guessExtension() ?: 'jpg');
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $filename = Str::uuid().'.'.$extension;
        $relativePath = 'images/payment-methods/'.$filename;
        $destination = public_path($relativePath);

        try {
            $imageFile->move($directory, $filename);
        } catch (\Throwable) {
            $sourcePath = $imageFile->getPathname();

            if (! is_readable($sourcePath)) {
                throw new RuntimeException('Could not save payment method image.');
            }

            $contents = file_get_contents($sourcePath);

            if ($contents === false || File::put($destination, $contents) === false) {
                throw new RuntimeException('Could not save payment method image.');
            }
        }

        if (! is_file($destination)) {
            throw new RuntimeException('Could not save payment method image.');
        }

        return $relativePath;
    }

    private function deleteImageFile(string $relativePath): void
    {
        $absolute = public_path($relativePath);

        if (is_file($absolute)) {
            File::delete($absolute);
        }
    }
}
