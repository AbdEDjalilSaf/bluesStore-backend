<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductImageStorage
{
    private const APPWRITE_PREFIX = 'appwrite:';

    public static function store(UploadedFile $file): string
    {
        return match ((string) config('filesystems.product_images_driver', 'local')) {
            'local' => self::storeLocal($file),
            'appwrite' => self::storeAppwrite($file),
            default => throw new RuntimeException('Unsupported product image storage driver.'),
        };
    }

    public static function url(string $path, Request $request): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^(?:https?:)?//#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, self::APPWRITE_PREFIX)) {
            return self::appwriteUrl(substr($path, strlen(self::APPWRITE_PREFIX)));
        }

        // Do not expose stale Render/local-disk URLs after switching to Appwrite.
        // Legacy rows stay URL-less until they are migrated, allowing the frontend
        // to show its deterministic product fallback instead of a 404 image.
        if ((string) config('filesystems.product_images_driver', 'appwrite') === 'appwrite') {
            return '';
        }

        if (config('filesystems.disks.public.driver') === 'local') {
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

            return $request->getSchemeAndHttpHost().'/storage/'.$encodedPath;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Remove stored files without hiding the original database/upload failure.
     * Appwrite and legacy local paths can coexist while old images are migrated.
     *
     * @param  array<int, string>  $paths
     */
    public static function delete(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (str_starts_with($path, self::APPWRITE_PREFIX)) {
                    self::deleteAppwrite(substr($path, strlen(self::APPWRITE_PREFIX)));

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

    private static function storeLocal(UploadedFile $file): string
    {
        $path = $file->store('products', 'public');

        if ($path === false) {
            throw new RuntimeException('Unable to store the product image.');
        }

        return $path;
    }

    private static function storeAppwrite(UploadedFile $file): string
    {
        $config = self::appwriteConfig();
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException('Unable to read the uploaded product image.');
        }

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read the uploaded product image.');
        }

        $fileId = (string) Str::uuid();

        try {
            $response = Http::withHeaders(self::appwriteHeaders($config))
                ->withOptions(self::appwriteHttpOptions())
                ->attach('file', $stream, $file->getClientOriginalName())
                ->post(self::appwriteFilesUrl($config), [
                    'fileId' => $fileId,
                    'permissions' => ['read("any")'],
                ]);
        } finally {
            fclose($stream);
        }

        if ($response->failed()) {
            Log::error('Appwrite rejected a product image upload.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Unable to store the product image in Appwrite.');
        }

        $storedFileId = $response->json('$id');

        if (! is_string($storedFileId) || $storedFileId === '') {
            throw new RuntimeException('Appwrite did not return a file ID.');
        }

        return self::APPWRITE_PREFIX.$storedFileId;
    }

    private static function deleteAppwrite(string $fileId): void
    {
        $config = self::appwriteConfig();

        $response = Http::withHeaders(self::appwriteHeaders($config))
            ->withOptions(self::appwriteHttpOptions())
            ->delete(self::appwriteFilesUrl($config).'/'.rawurlencode($fileId));

        if ($response->failed() && $response->status() !== 404) {
            Log::warning('Appwrite rejected a product image deletion.', [
                'file_id' => $fileId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    private static function appwriteUrl(string $fileId): string
    {
        $config = self::appwriteConfig();

        return self::appwriteFilesUrl($config)
            .'/'.rawurlencode($fileId)
            .'/view?project='.rawurlencode($config['project']);
    }

    /**
     * @return array{force_ip_resolve: string, verify: bool|string}
     */
    private static function appwriteHttpOptions(): array
    {
        return [
            'force_ip_resolve' => 'v4',
            'verify' => config('services.appwrite.verify', true),
        ];
    }

    /**
     * @param  array{endpoint: string, project: string, key: string, bucket: string}  $config
     * @return array<string, string>
     */
    private static function appwriteHeaders(array $config): array
    {
        return [
            'X-Appwrite-Project' => $config['project'],
            'X-Appwrite-Key' => $config['key'],
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  array{endpoint: string, project: string, key: string, bucket: string}  $config
     */
    private static function appwriteFilesUrl(array $config): string
    {
        return $config['endpoint'].'/storage/buckets/'.rawurlencode($config['bucket']).'/files';
    }

    /**
     * @return array{endpoint: string, project: string, key: string, bucket: string}
     */
    private static function appwriteConfig(): array
    {
        $config = [
            'endpoint' => rtrim((string) config('services.appwrite.endpoint', ''), '/'),
            'project' => (string) config('services.appwrite.project', ''),
            'key' => (string) config('services.appwrite.key', ''),
            'bucket' => (string) config('services.appwrite.bucket', ''),
        ];

        if (in_array('', $config, true)) {
            throw new RuntimeException('APPWRITE_ENDPOINT, APPWRITE_PROJECT_ID, APPWRITE_API_KEY, and APPWRITE_PRODUCT_IMAGES_BUCKET_ID must be set when PRODUCT_IMAGES_DRIVER=appwrite.');
        }

        return $config;
    }
}
