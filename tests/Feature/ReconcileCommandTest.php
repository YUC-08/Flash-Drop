<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Drop;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ReconcileCommandTest extends TestCase
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

    public function test_reconcile_dry_run_does_not_mutate_state_and_shows_table(): void
    {
        $user = User::factory()->create();
        $drop = Drop::create([
            'title' => 'Drop Dry',
            'sku' => 'DRY-001',
            'price_cents' => 1000,
            'total_stock' => 10,
            'available_stock' => 8,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        Redis::set("drop:{$drop->uuid}:available_stock", 8);

        Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('drops:reconcile', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsTable(
                ['Drop SKU', 'UUID', 'Expired Rezervasyon', 'İade Edilecek Stok'],
                [
                    ['DRY-001', $drop->uuid, 1, 2],
                    ['Toplam', '-', 1, 2],
                ]
            );

        $drop->refresh();
        $this->assertEquals(8, $drop->available_stock);
        $this->assertEquals(8, (int) Redis::get("drop:{$drop->uuid}:available_stock"));
        $this->assertEquals(ReservationStatus::Pending, Reservation::first()->status);
    }

    public function test_reconcile_normal_execution_refunds_stock_and_updates_redis(): void
    {
        $user = User::factory()->create();
        $drop = Drop::create([
            'title' => 'Drop Normal',
            'sku' => 'NRM-001',
            'price_cents' => 1000,
            'total_stock' => 10,
            'available_stock' => 6,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        Redis::set("drop:{$drop->uuid}:available_stock", 6);

        $expired1 = Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinutes(10),
        ]);
        $expired2 = Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinutes(5),
        ]);

        $confirmed = Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 1,
            'status' => ReservationStatus::Confirmed,
            'expires_at' => now()->subMinutes(2),
        ]);
        $activePending = Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 1,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->artisan('drops:reconcile')
            ->assertExitCode(0)
            ->expectsOutputToContain('2 rezervasyon expire edildi, 4 stok iade edildi');

        $drop->refresh();
        $this->assertEquals(10, $drop->available_stock);
        $this->assertEquals(10, (int) Redis::get("drop:{$drop->uuid}:available_stock"));

        $this->assertEquals(ReservationStatus::Expired, $expired1->fresh()->status);
        $this->assertEquals(ReservationStatus::Expired, $expired2->fresh()->status);
        $this->assertEquals(ReservationStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertEquals(ReservationStatus::Pending, $activePending->fresh()->status);

        // İkinci çalıştırma idempotenttir (hiçbir şey yapmaz)
        $this->artisan('drops:reconcile')
            ->assertExitCode(0)
            ->expectsOutput('Süresi dolmuş rezervasyon yok');

        $drop->refresh();
        $this->assertEquals(10, $drop->available_stock);
        $this->assertEquals(10, (int) Redis::get("drop:{$drop->uuid}:available_stock"));
    }

    public function test_reconcile_caps_at_total_stock(): void
    {
        $user = User::factory()->create();
        $drop = Drop::create([
            'title' => 'Drop Cap',
            'sku' => 'CAP-001',
            'price_cents' => 1000,
            'total_stock' => 10,
            'available_stock' => 9,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        Redis::set("drop:{$drop->uuid}:available_stock", 9);

        Reservation::create([
            'user_uuid' => $user->uuid,
            'drop_uuid' => $drop->uuid,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('drops:reconcile')->assertExitCode(0);

        $drop->refresh();
        $this->assertEquals(10, $drop->available_stock);
        $this->assertEquals(10, (int) Redis::get("drop:{$drop->uuid}:available_stock"));
    }

    public function test_reconcile_lock_prevents_overlap_unless_forced(): void
    {
        $externalLock = Cache::lock('drops:reconcile', 120);
        $this->assertTrue($externalLock->get(), 'Could not acquire initial lock in test');

        try {
            $this->artisan('drops:reconcile')
                ->assertExitCode(1)
                ->expectsOutput('Başka bir reconcile çalışıyor');

            $this->artisan('drops:reconcile', ['--force' => true])
                ->assertExitCode(0);
        } finally {
            $externalLock->forceRelease();
        }
    }
}
