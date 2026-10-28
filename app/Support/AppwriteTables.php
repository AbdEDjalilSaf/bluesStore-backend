<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AppwriteTables
{
    public const PRODUCTS = 'products';

    public const PRODUCT_IMAGES = 'product_images';

    private const PAGE_SIZE = 100;

    /**
     * @return array<string, mixed>|null
     */
    public function getRow(string $tableId, string $rowId): ?array
    {
        $response = $this->request()->retry(3, 1000)->get($this->rowsUrl($tableId).'/'.rawurlencode($rowId));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<int, array<string, mixed>>
     */
    public function listRows(string $tableId, array $queries = []): array
    {
        $response = $this->request()->retry(3, 1000)->post($this->rowsUrl($tableId).'/query', [
            'queries' => array_values($queries),
        ]);

        $response->throw();

        return $response->json('rows', []);
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<int, array<string, mixed>>
     */
    public function listAllRows(string $tableId, array $queries = []): array
    {
        $rows = [];
        $offset = 0;

        do {
            $batch = $this->listRows($tableId, [
                ...$queries,
                self::limit(self::PAGE_SIZE),
                self::offset($offset),
            ]);

            $rows = [...$rows, ...$batch];
            $offset += count($batch);
        } while (count($batch) === self::PAGE_SIZE);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $permissions
     * @return array<string, mixed>
     */
    public function createRow(string $tableId, string $rowId, array $data, array $permissions = []): array
    {
        $response = $this->request()->post($this->rowsUrl($tableId), [
            'rowId' => $rowId,
            'data' => $data,
            'permissions' => array_values($permissions),
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateRow(string $tableId, string $rowId, array $data): array
    {
        $response = $this->request()->retry(3, 1000)->patch($this->rowsUrl($tableId).'/'.rawurlencode($rowId), [
            'data' => $data,
        ]);

        $response->throw();

        return $response->json();
    }

    public function deleteRow(string $tableId, string $rowId): void
    {
        $response = $this->request()->retry(3, 1000)->delete($this->rowsUrl($tableId).'/'.rawurlencode($rowId));

        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    public function nextNumericId(string $tableId): int
    {
        $rows = $this->listAllRows($tableId, [self::select(['$id'])]);
        $highestId = 0;

        foreach ($rows as $row) {
            $rowId = (string) ($row['$id'] ?? '');

            if (ctype_digit($rowId)) {
                $highestId = max($highestId, (int) $rowId);
            }
        }

        return $highestId + 1;
    }

    public static function equal(string $attribute, mixed $value): string
    {
        $values = is_array($value) ? array_values($value) : [$value];

        return self::query([
            'method' => 'equal',
            'attribute' => $attribute,
            'values' => $values,
        ]);
    }

    public static function orderAsc(string $column): string
    {
        return self::query(['method' => 'orderAsc', 'column' => $column]);
    }

    public static function orderDesc(string $column): string
    {
        return self::query(['method' => 'orderDesc', 'column' => $column]);
    }

    public static function limit(int $limit): string
    {
        return self::query(['method' => 'limit', 'values' => [$limit]]);
    }

    public static function offset(int $offset): string
    {
        return self::query(['method' => 'offset', 'values' => [$offset]]);
    }

    /**
     * @param  array<int, string>  $attributes
     */
    public static function select(array $attributes): string
    {
        return self::query(['method' => 'select', 'values' => array_values($attributes)]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function query(array $query): string
    {
        return json_encode($query, JSON_THROW_ON_ERROR);
    }

    private function rowsUrl(string $tableId): string
    {
        $config = $this->config();

        return 'tablesdb/'.rawurlencode($config['database']).'/tables/'.rawurlencode($tableId).'/rows';
    }

    private function request(): PendingRequest
    {
        $config = $this->config();

        return Http::baseUrl($config['endpoint'])
            ->withHeaders([
                'X-Appwrite-Project' => $config['project'],
                'X-Appwrite-Key' => $config['key'],
            ])
            ->withOptions([
                'force_ip_resolve' => 'v4',
                'verify' => config('services.appwrite.verify', true),
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(30)
            ->timeout(60);
    }

    /**
     * @return array{endpoint: string, project: string, key: string, database: string}
     */
    private function config(): array
    {
        $config = [
            'endpoint' => rtrim((string) config('services.appwrite.endpoint', ''), '/'),
            'project' => (string) config('services.appwrite.project', ''),
            'key' => (string) config('services.appwrite.key', ''),
            'database' => (string) config('services.appwrite.database', ''),
        ];

        if (in_array('', $config, true)) {
            throw new RuntimeException('APPWRITE_ENDPOINT, APPWRITE_PROJECT_ID, APPWRITE_API_KEY, and APPWRITE_DATABASE_ID must be set.');
        }

        return $config;
    }
}
