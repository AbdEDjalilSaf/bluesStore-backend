<?php

namespace Tests;

use App\Contracts\ProductCatalog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeProductCatalog;

abstract class TestCase extends BaseTestCase
{
    protected FakeProductCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new FakeProductCatalog;
        $this->app->instance(ProductCatalog::class, $this->catalog);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function makeProduct(array $attributes = []): array
    {
        return $this->catalog->createProduct(array_merge([
            'name' => 'Test Shirt',
            'slug' => 'test-shirt-'.(count($this->catalog->products()) + 1),
            'team' => 'Algeria',
            'year' => 2020,
            'description' => 'A test shirt.',
            'price' => 3000,
            'stock' => 5,
        ], $attributes));
    }
}
