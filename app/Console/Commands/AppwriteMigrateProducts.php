<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\AppwriteTables;
use App\Support\ProductImageStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('appwrite:migrate-products {--dry-run : Show what would be migrated without writing to Appwrite}')]
#[Description('Migrate SQL products and product images into Appwrite TablesDB')]
class AppwriteMigrateProducts extends Command
{
    public function handle(AppwriteTables $tables): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            config(['filesystems.product_images_driver' => 'appwrite']);
        }

        $products = Product::query()->orderBy('id')->get();
        $this->info("Migrating {$products->count()} products to Appwrite TablesDB.");

        foreach ($products as $product) {
            $data = [
                'name' => $product->name,
                'slug' => $product->slug,
                'team' => $product->team,
                'year' => (int) $product->year,
                'description' => $product->description,
                'price' => (int) $product->price,
                'old_price' => $product->old_price === null ? null : (int) $product->old_price,
                'stock' => (int) $product->stock,
                'is_active' => (bool) $product->is_active,
                'category_id' => $product->category_id === null ? null : (int) $product->category_id,
                'size' => $product->size,
            ];

            if ($dryRun) {
                $this->line("Would migrate product {$product->id} ({$product->slug}).");

                continue;
            }

            $rowId = (string) $product->id;

            if ($tables->getRow(AppwriteTables::PRODUCTS, $rowId) === null) {
                $tables->createRow(AppwriteTables::PRODUCTS, $rowId, $data);
            } else {
                $tables->updateRow(AppwriteTables::PRODUCTS, $rowId, $data);
            }

            $this->line("Migrated product {$product->id} ({$product->slug}).");
        }

        $images = ProductImage::query()->orderBy('id')->get();
        $this->info("Migrating {$images->count()} product images to Appwrite Storage and TablesDB.");

        foreach ($images as $image) {
            $rowId = (string) $image->id;
            $existing = $dryRun ? null : $tables->getRow(AppwriteTables::PRODUCT_IMAGES, $rowId);

            if ($existing !== null && str_starts_with((string) $existing['path'], 'appwrite:')) {
                $this->line("Skipped already migrated image {$image->id}.");

                continue;
            }

            $path = $image->path;

            if ($dryRun) {
                $this->line("Would migrate image {$image->id} from {$path}.");

                continue;
            }

            $migratedPath = $this->migratePath($path);

            if ($migratedPath === null) {
                $this->warn("Could not migrate image {$image->id} from {$path}.");

                continue;
            }

            $data = [
                'product_id' => (string) $image->product_id,
                'path' => $migratedPath,
                'sort_order' => (int) $image->sort_order,
                'is_primary' => (bool) $image->is_primary,
            ];

            if ($existing === null) {
                $tables->createRow(AppwriteTables::PRODUCT_IMAGES, $rowId, $data);
            } else {
                $tables->updateRow(AppwriteTables::PRODUCT_IMAGES, $rowId, $data);
            }

            $this->line("Migrated image {$image->id}.");
        }

        return self::SUCCESS;
    }

    private function migratePath(string $path): ?string
    {
        if (str_starts_with($path, 'appwrite:')) {
            return $path;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->migrateRemoteFile($path);
        }

        $absolutePath = Storage::disk('public')->path($path);

        if (! is_file($absolutePath)) {
            return null;
        }

        return $this->upload($absolutePath, basename($path));
    }

    private function migrateRemoteFile(string $url): ?string
    {
        $response = Http::connectTimeout(5)
            ->timeout(30)
            ->withOptions([
                'force_ip_resolve' => 'v4',
                'verify' => config('services.appwrite.verify', true),
            ])
            ->get($url);

        if ($response->failed()) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'appwrite-image-');

        if ($temporaryPath === false) {
            return null;
        }

        try {
            file_put_contents($temporaryPath, $response->body());

            return $this->upload($temporaryPath, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image');
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function upload(string $absolutePath, string $name): ?string
    {
        try {
            return ProductImageStorage::store(new UploadedFile($absolutePath, $name, null, null, true));
        } catch (Throwable $exception) {
            $this->warn("Image upload failed for {$name}: {$exception->getMessage()}");

            return null;
        }
    }
}
