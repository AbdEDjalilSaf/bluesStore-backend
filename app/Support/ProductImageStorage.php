<?php

namespace App\Support;

use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductImageStorage
{
    private const CLOUDINARY_PREFIX = 'cloudinary:';

    public static function store(UploadedFile $file): string
    {
        $driver = (string) config('filesystems.product_images_driver', 'local');

        if ($driver === 'local') {
            $path = $file->store('products', 'public');

            if ($path === false) {
                throw new RuntimeException('Unable to store the product image.');
            }

            return $path;
        }

        if ($driver !== 'cloudinary') {
            throw new RuntimeException('Unsupported product image storage driver.');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException('Unable to read the uploaded product image.');
        }

        $result = self::cloudinary()->uploadApi()->upload($realPath, [
            'folder' => 'bluesstore/products',
            'resource_type' => 'image',
            'overwrite' => false,
        ]);

        $publicId = $result['public_id'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            throw new RuntimeException('Cloudinary did not return a public image ID.');
        }

        return self::CLOUDINARY_PREFIX.$publicId;
    }

    public static function url(string $path, Request $request): string
    {
        if (str_starts_with($path, self::CLOUDINARY_PREFIX)) {
            $publicId = substr($path, strlen(self::CLOUDINARY_PREFIX));

            return (string) self::cloudinary()->image($publicId)->toUrl();
        }

        if (config('filesystems.disks.public.driver') === 'local') {
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

            return $request->getSchemeAndHttpHost().'/storage/'.$encodedPath;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Remove stored files without hiding the original database/upload failure.
     * Cloudinary and local paths can coexist while old product images are migrated.
     *
     * @param  array<int, string>  $paths
     */
    public static function delete(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (str_starts_with($path, self::CLOUDINARY_PREFIX)) {
                    self::cloudinary()->uploadApi()->destroy(
                        substr($path, strlen(self::CLOUDINARY_PREFIX)),
                        ['resource_type' => 'image', 'invalidate' => true],
                    );

                    continue;
                }

                Storage::disk('public')->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Unable to delete a product image asset.', [
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private static function cloudinary(): Cloudinary
    {
        $cloudinaryUrl = (string) config('services.cloudinary.url', '');

        if ($cloudinaryUrl === '') {
            throw new RuntimeException('CLOUDINARY_URL must be set when PRODUCT_IMAGES_DRIVER=cloudinary.');
        }

        return new Cloudinary($cloudinaryUrl);
    }
}