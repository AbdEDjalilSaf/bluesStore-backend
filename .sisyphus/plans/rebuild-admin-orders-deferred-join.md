# Rebuild GET /api/admin/orders — deferred-join pagination

## Context

- Reference API (`https://bluesstore-backend-2.onrender.com/api/admin/orders`) returns the standard Laravel `LengthAwarePaginator` shape: `data[]` (newest first, each with nested `items[]`), `links{first,last,prev,next}`, `meta{current_page,from,last_page,links,path,per_page,to,total}`. No required parameters — `page`, `per_page`, `status` are all optional.
- Local implementation already matches this contract (`OrderController::index`, app/Http/Controllers/OrderController.php:175) using a single `Order::query()->paginate()` (COUNT(*) + wide OFFSET scan + row fetch) wrapped in `Cache::flexible` (SWR, 30s fresh / 300s stale) with a versioned cache key (`orders.list.version`, rotated on every write).
- DB: SQLite here; covering index `orders_status_id_index (status, id)` exists.
- Behavioral contract locked by `tests/Feature/AdminOrderTest.php`: public endpoints, paginator `links`/`meta` structure, `status` filter with 422 on invalid enum, and the SWR test (stale served once, then fresh).

## Goal

Same URL, same response shape, same optional params, zero required params — but replace the pagination algorithm with a **deferred join (late row lookup)** for faster responses, and split the algorithm out of the controller ("code splitting"). All existing tests must pass unchanged.

## Why the new algorithm is faster

Current: 1 `COUNT(*)` + 1 wide `SELECT * … LIMIT/OFFSET` (+1 items query). Skipped offsets read full rows; COUNT(*) runs on every cache miss.

New (per cache miss):
1. **Total** — `Cache::rememberForever("admin.orders.total.{version}.{status}", fn => count)` → COUNT(*) runs at most once per list version (any real write rotates the version via the existing `saved`/`deleted` hooks).
2. **Page IDs** — `SELECT id FROM orders [WHERE status=?] ORDER BY id DESC LIMIT n OFFSET m` — index-only, narrow scan; offset skips cost almost nothing.
3. **Hydrate** — `Order::with('items')->findMany($ids)` (2 queries, PK lookup) then `sortByDesc('id')` to restore page order.

Hot path unchanged: `Cache::flexible` still serves the whole cached paginator with 0 DB queries and preserves the SWR behavior the tests assert.

## Changes

### 1. NEW `app/Services/OrderListPager.php`

Owns the whole algorithm (controller becomes a thin validation + delegation shell):

- Constants: `FRESH_SECONDS = 30`, `STALE_SECONDS = 300`, `ORDER_COLUMNS`, `ORDER_ITEM_COLUMNS` (moved from controller), cache-key prefixes.
- `public function cachedPage(?string $status, int $page, int $perPage): LengthAwarePaginator`
  - `Cache::flexible($this->pageCacheKey(...), [30, 300], fn () => $this->buildPage(...))` — same key format `admin.orders.{version}.{status}.{page}.{per_page}` and TTLs as today.
- `private function buildPage(...): LengthAwarePaginator`
  - `$total = Cache::rememberForever($this->totalCacheKey($status), ...)` (key `admin.orders.total.{version}.{status}`)
  - `$ids = Order::query()->select('id')->when($status, …)->orderByDesc('id')->skip(($page-1)*$perPage)->take($perPage)->pluck('id')`
  - `$items = $ids === [] ? collect() : Order::query()->with('items')->findMany($ids)->sortByDesc('id')->values()`
  - `return (new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]))->withQueryString();`
- Private helpers: `pageIds()`, `totalFor()` (count query), `pageCacheKey()`, `totalCacheKey()`; version read via `Cache::get(Order::LIST_CACHE_VERSION_KEY, 1)` exactly as today.

### 2. EDIT `app/Http/Controllers/OrderController.php`

- Constructor: inject `OrderListPager` (promoted, readonly) alongside `OrderService`.
- `index()`: keep the identical `$request->validate([...])` (status/page/per_page all optional), then
  `$orders = $this->orderListPager->cachedPage($filters['status'] ?? null, $filters['page'] ?? 1, $filters['per_page'] ?? 15);`
  and `return OrderResource::collection($orders);`
- Delete now-moved `ORDER_COLUMNS`, `ORDER_ITEM_COLUMNS`, `listCacheKey()`. Keep OpenAPI attribute, `Cache` import (still used by `track`/`show`), everything else untouched.
- `show`/`updateStatus`/`store`/`track` unchanged.

### 3. EDIT `tests/Feature/AdminOrderTest.php` — add multi-page coverage

Existing tests only exercise ≤3 orders (single page); the new offset math deserves regression coverage. Add one test (create orders directly with `Order::query()->create()` — avoids the 5/min checkout throttle; unique `order_number` per row; `'status' => OrderStatus::Pending`):

- `test_index_paginates_across_multiple_pages_without_required_parameters`:
  - Create 30 orders.
  - `GET /api/admin/orders` (no params) → 200, 15 items, `meta.total=30`, `last_page=2`, `links.next` non-null, first item is the newest id.
  - `?page=2&per_page=15` → 15 items, `meta.from=16`, `meta.to=30`, `links.prev` non-null, `links.next` null.
  - `?page=3&per_page=15` → 200, empty `data`, `meta.total=30` (out-of-range behaves like `paginate()`).

### 4. Rules/skills (per AGENTS.md)

- Load `laravel-best-practices` skill before writing code; `testing-best-practices` before the test.
- No `.ai/rules` directory exists — nothing to read there.
- No dependency or directory-structure changes (`app/Services/` already exists).

## Verification

1. `php artisan test --compact tests/Feature/AdminOrderTest.php` — all existing + new tests pass.
2. `php artisan test --compact` — full suite green (OrderApiTest, OrderTrackingTest regressions).
3. `vendor/bin/pint --dirty --format agent`.
4. Spot-check response keys against the reference (data/links/meta shape identical; no `timeline` key on list).
