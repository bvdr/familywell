<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Core image storage with size variants. An uploaded image is stored on the given disk; an optional
// client-generated thumbnail is stored alongside it at "{path}.thumb.jpg". Serving then picks the
// variant a use case needs (a small thumb for cards/lists, the original for detail/full views).
//
// Variants are generated on the client (canvas) and uploaded, so this needs no server image extension.
// Modules should route image uploads through here instead of storing them ad-hoc, so every image gets
// a small variant for free. Old images with no thumb fall back to the original when a thumb is asked.
class StoredImage
{
    public static function thumbPath(string $path): string
    {
        return $path.'.thumb.jpg';
    }

    /** Store the main image (and its thumb, if provided) and return the main path. */
    public static function store(UploadedFile $main, ?UploadedFile $thumb, string $dir, string $disk = 'r2'): string
    {
        $path = $main->store($dir, $disk);
        if ($thumb) {
            Storage::disk($disk)->put(self::thumbPath($path), (string) file_get_contents($thumb->getRealPath()));
        }

        return $path;
    }

    /** Delete the main image and its thumb (a no-op on a null path). */
    public static function delete(?string $path, string $disk = 'r2'): void
    {
        if (! $path) {
            return;
        }
        Storage::disk($disk)->delete([$path, self::thumbPath($path)]);
    }

    /** Serve a variant: 'thumb' returns the thumbnail when one exists, otherwise the original. */
    public static function response(string $path, ?string $variant, string $disk = 'r2'): StreamedResponse
    {
        $d = Storage::disk($disk);
        if ($variant === 'thumb' && $d->exists(self::thumbPath($path))) {
            return $d->response(self::thumbPath($path));
        }

        return $d->response($path);
    }
}
