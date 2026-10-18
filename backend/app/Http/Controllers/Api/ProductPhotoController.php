<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProductPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductPhotoController extends Controller
{
    /**
     * Public: the app shows the photo after an authenticated scan. The file name
     * is random, so it cannot be guessed.
     */
    public function show(string $filename): StreamedResponse
    {
        $path = ProductPhoto::DIRECTORY.'/'.$filename;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
