<?php

namespace Tests\Feature;

use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilayaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_wilayas_ordered_by_id(): void
    {
        Wilaya::factory()->create(['name' => 'Algiers', 'phone_number' => '021', 'shipping_fee' => 400]);
        Wilaya::factory()->create(['name' => 'Oran', 'phone_number' => '041', 'shipping_fee' => 500]);
        Wilaya::factory()->create(['name' => 'Timimoun', 'phone_number' => '049', 'shipping_fee' => 700, 'is_active' => false]);

        $this->getJson('/api/wilayas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Algiers')
            ->assertJsonPath('data.0.shipping_fee', 400)
            ->assertJsonPath('data.1.name', 'Oran')
            ->assertJsonStructure(['data' => [['id', 'name', 'phone_number', 'shipping_fee', 'is_active']]]);
    }
}
