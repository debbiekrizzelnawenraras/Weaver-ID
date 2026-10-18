<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductPhotoEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores one photo per product on the private local disk. Files get a random
 * name, so a photo address cannot be guessed from a product id, and are served
 * only through ProductPhotoController.
 */
class ProductPhoto
{
    public const DIRECTORY = 'product-photos';

    public const MAX_KILOBYTES = 3072;

    /** Validation rules for the uploaded file. */
    public static function rules(): array
    {
        return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES];
    }

    /** Save the file for the product and remove the previous one. */
    public static function replace(Product $product, UploadedFile $file, ?User $by = null): Product
    {
        $action = $product->photo_path ? 'changed' : 'added';

        $extension = match ($file->guessExtension()) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };

        $name = (string) Str::uuid().'.'.$extension;
        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $name);

        self::forget($product);
        $product->update(['photo_path' => $name]);
        self::record($product, $by, $action);

        return $product->fresh();
    }

    /** Delete the product's photo, if it has one. */
    public static function remove(Product $product, ?User $by = null): Product
    {
        $had = (bool) $product->photo_path;
        self::forget($product);
        $product->update(['photo_path' => null]);
        if ($had) {
            self::record($product, $by, 'removed');
        }

        return $product->fresh();
    }

    private static function record(Product $product, ?User $by, string $action): void
    {
        ProductPhotoEvent::create(['product_id' => $product->id, 'user_id' => $by?->id, 'action' => $action]);
    }

    private static function forget(Product $product): void
    {
        if ($product->photo_path) {
            Storage::disk('local')->delete(self::DIRECTORY.'/'.$product->photo_path);
        }
    }
}
