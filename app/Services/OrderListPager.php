<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds admin order pages with a deferred join (late row lookup) instead of
 * a single wide OFFSET scan:
 *
 * 1. The total comes from a version-keyed forever cache, so COUNT(*) runs at
 *    most once per list version rather than once per cache miss. Every real
 *    write rotates `Order::LIST_CACHE_VERSION_KEY`, which retires the total
 *    and every cached page at once.
 * 2. The page is resolved as a narrow, index-only `SELECT id … ORDER BY id
 *    DESC LIMIT/OFFSET`; skipped offsets never read full rows.
 * 3. Only the page's rows are hydrated by primary key, with items eager
 *    loaded, then restored to page order.
 */
class OrderListPager
{
    /**
     * Seconds a computed page is served from cache without recalculation.
     */
    private const PAGE_CACHE_FRESH_SECONDS = 30;

    /**
     * Extra seconds a cached page may be served stale while a deferred
     * refresh repopulates it in the background.
     */
    private const PAGE_CACHE_STALE_SECONDS = 300;

    /**
     * Only the columns the resource actually reads — avoids fetching and
     * transferring the whole row on every list request.
     *
     * @var list<string>
     */
    private const ORDER_COLUMNS = [
        'id',
        'order_number',
        'customer_name',
        'phone',
        'wilaya',
        'subtotal',
        'shipping_fee',
        'total',
        'status',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    private const ORDER_ITEM_COLUMNS = [
        'id',
        'order_id',
        'product_id',
        'product_name',
        'unit_price',
    ];

    /**
     * Get one page of orders, serving from the stale-while-revalidate cache
     * when possible.
     */
    public function cachedPage(?string $status, int $page, int $perPage): LengthAwarePaginator
    {
        return Cache::flexible(
            $this->pageCacheKey($status, $page, $perPage),
            [self::PAGE_CACHE_FRESH_SECONDS, self::PAGE_CACHE_STALE_SECONDS],
            fn (): LengthAwarePaginator => $this->buildPage($status, $page, $perPage),
        );
    }

    /**
     * Resolve a page through the deferred-join algorithm.
     */
    private function buildPage(?string $status, int $page, int $perPage): LengthAwarePaginator
    {
        $total = Cache::rememberForever(
            $this->totalCacheKey($status),
            fn (): int => $this->countOrders($status),
        );

        $ids = $this->pageIds($status, $page, $perPage);

        return (new LengthAwarePaginator(
            $this->hydrate($ids),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * Total matching orders for a filter. Safe to cache forever per list
     * version because every insert, update, or delete rotates the version.
     */
    private function countOrders(?string $status): int
    {
        return Order::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->count();
    }

    /**
     * Ids on the requested page, from an index-only scan.
     *
     * @return list<int>
     */
    private function pageIds(?string $status, int $page, int $perPage): array
    {
        return Order::query()
            ->select('id')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->pluck('id')
            ->all();
    }

    /**
     * Load the full rows and items for a page of ids, restored to newest
     * first order.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Order>
     */
    private function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Order::query()
            ->select(self::ORDER_COLUMNS)
            ->with(['items' => fn ($items) => $items->select(self::ORDER_ITEM_COLUMNS)])
            ->findMany($ids)
            ->sortByDesc('id')
            ->values();
    }

    /**
     * Versioned cache key so a single `orders.list.version` rotation retires
     * every cached page without having to enumerate their keys.
     */
    private function pageCacheKey(?string $status, int $page, int $perPage): string
    {
        $version = $this->listVersion();

        return sprintf('admin.orders.%s.%s.%d.%d', $version, $status ?? 'all', $page, $perPage);
    }

    /**
     * Versioned cache key for the filtered total.
     */
    private function totalCacheKey(?string $status): string
    {
        $version = $this->listVersion();

        return sprintf('admin.orders.total.%s.%s', $version, $status ?? 'all');
    }

    private function listVersion(): int|string
    {
        return Cache::get(Order::LIST_CACHE_VERSION_KEY, 1);
    }
}
