<?php

namespace Tests\Feature;

use App\Models\Drop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTestingRedis();
    }

    protected function tearDown(): void
    {
        $this->cleanTestingRedis();
        parent::tearDown();
    }

    public function test_seeder_is_strictly_idempotent(): void
    {
        // 1. Seed 3 kez çalıştırılır
        Artisan::call('db:seed');
        Artisan::call('db:seed');
        Artisan::call('db:seed');

        $this->assertEquals(1, User::count());
        $this->assertEquals(3, Drop::count());

        $initialDrops = Drop::orderBy('sku')->get(['uuid', 'sku'])->toArray();

        // Tekrar seed çalıştırılır ve UUID'lerin değişmediği doğrulanır
        Artisan::call('db:seed');
        $afterDrops = Drop::orderBy('sku')->get(['uuid', 'sku'])->toArray();
        $this->assertSame($initialDrops, $afterDrops);

        // 2. available_stock 5 yapılıp tekrar seed çalıştırılınca 5 kalmalıdır
        $activeDrop = Drop::where('sku', 'FD-ACTIVE-001')->firstOrFail();
        $activeDrop->update(['available_stock' => 5]);

        Artisan::call('db:seed');
        $activeDrop->refresh();
        $this->assertEquals(5, $activeDrop->available_stock);

        // 3. Redis stok anahtarı mevcutsa SETNX kuralıyla ezilmemelidir
        Redis::set("drop:{$activeDrop->uuid}:available_stock", 7);
        Artisan::call('db:seed');
        $this->assertEquals(7, (int) Redis::get("drop:{$activeDrop->uuid}:available_stock"));
    }
}
