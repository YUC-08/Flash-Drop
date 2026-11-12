<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Drop;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * OversellingTest
 *
 * NOT: Laravel test istemcisi tek bir PHP sürecinde sıralı (sequential) çalıştığı için,
 * bu test süreci gerçek zamanlı paralel/eşzamanlı yarışı (concurrency race condition)
 * doğrudan kanıtlamaz; sıralı isteklerde stok tükenmesini ve limit aşımının engellenmesini doğrular.
 */
class OversellingTest extends TestCase
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

    public function test_overselling_prevention_with_sequential_users(): void
    {
        $drop = Drop::create([
            'title' => 'Limited Drop',
            'sku' => 'LIM-001',
            'price_cents' => 500,
            'total_stock' => 5,
            'available_stock' => 5,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
        ]);
        Redis::set("drop:{$drop->uuid}:available_stock", 5);
        Redis::hmset("drop:{$drop->uuid}:meta", [
            'starts_at' => $drop->starts_at->getTimestamp(),
            'ends_at' => $drop->ends_at->getTimestamp(),
        ]);

        $users = User::factory()->count(10)->create();

        $successCount = 0;
        $insufficientCount = 0;

        foreach ($users as $user) {
            Sanctum::actingAs($user, ['drops:reserve']);

            $response = $this->postJson(
                "/api/v1/drops/{$drop->uuid}/reserve",
                ['quantity' => 1],
                ['Idempotency-Key' => (string) Str::uuid()]
            );

            if ($response->status() === 201) {
                $successCount++;
            } elseif ($response->status() === 409 && $response->json('code') === 'insufficient_stock') {
                $insufficientCount++;
            }
        }

        $this->assertEquals(5, $successCount);
        $this->assertEquals(5, $insufficientCount);

        $drop->refresh();
        $this->assertEquals(0, $drop->available_stock);
        $this->assertEquals(0, (int) Redis::get("drop:{$drop->uuid}:available_stock"));
        $this->assertEquals(5, Reservation::where('drop_uuid', $drop->uuid)->sum('quantity'));
    }

    public function test_additional_reservation_scenarios(): void
    {
        $drop = Drop::create([
            'title' => 'Scenario Drop',
            'sku' => 'SCN-001',
            'price_cents' => 500,
            'total_stock' => 10,
            'available_stock' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
        ]);
        Redis::set("drop:{$drop->uuid}:available_stock", 10);
        Redis::hmset("drop:{$drop->uuid}:meta", [
            'starts_at' => $drop->starts_at->getTimestamp(),
            'ends_at' => $drop->ends_at->getTimestamp(),
        ]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Sanctum::actingAs($user1, ['drops:reserve']);

        $key1 = (string) Str::uuid();

        // 1. Başarılı rezervasyon
        $res1 = $this->postJson("/api/v1/drops/{$drop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => $key1])
            ->assertStatus(201);

        $reservationUuid = $res1->json('data.uuid');

        // 2. Aynı Idempotency-Key replay -> tek rezervasyon, Idempotent-Replayed: true
        $this->postJson("/api/v1/drops/{$drop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => $key1])
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJson(['data' => ['uuid' => $reservationUuid]]);

        $this->assertEquals(1, Reservation::where('user_uuid', $user1->uuid)->count());

        // 3. Aynı kullanıcı yeni key -> 409 already_reserved
        $this->postJson("/api/v1/drops/{$drop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJson(['code' => 'already_reserved']);

        // 4. Stok 1 iken qty 2 -> 409 insufficient_stock
        $lowDrop = Drop::create([
            'title' => 'Low Drop',
            'sku' => 'LOW-001',
            'price_cents' => 100,
            'total_stock' => 1,
            'available_stock' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
        ]);
        Redis::set("drop:{$lowDrop->uuid}:available_stock", 1);
        Redis::hmset("drop:{$lowDrop->uuid}:meta", [
            'starts_at' => $lowDrop->starts_at->getTimestamp(),
            'ends_at' => $lowDrop->ends_at->getTimestamp(),
        ]);

        Sanctum::actingAs($user2, ['drops:reserve']);
        $this->postJson("/api/v1/drops/{$lowDrop->uuid}/reserve", ['quantity' => 2], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJson(['code' => 'insufficient_stock']);

        // 5. Confirm: Başkası -> 403
        $this->postJson("/api/v1/reservations/{$reservationUuid}/confirm")
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);

        // 6. Confirm: Sahibi -> 200
        Sanctum::actingAs($user1, ['drops:reserve']);
        $this->postJson("/api/v1/reservations/{$reservationUuid}/confirm")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'confirmed']]);

        // 7. Tekrar confirm -> 409 reservation_not_pending
        $this->postJson("/api/v1/reservations/{$reservationUuid}/confirm")
            ->assertStatus(409)
            ->assertJson(['code' => 'reservation_not_pending']);

        // 8. Süresi geçmiş rezervasyon confirm -> 422 reservation_expired
        $expiredReservation = Reservation::create([
            'user_uuid' => $user1->uuid,
            'drop_uuid' => $lowDrop->uuid,
            'quantity' => 1,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson("/api/v1/reservations/{$expiredReservation->uuid}/confirm")
            ->assertStatus(422)
            ->assertJson(['code' => 'reservation_expired']);
    }
}
