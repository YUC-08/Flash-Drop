<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\ReservationException;
use App\Models\Drop;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ReservationService
{
    public const RESERVATION_EXPIRY_MINUTES = 5;

    public const IDEMPOTENCY_TTL_HOURS = 24;

    /**
     * Reserve stock for a drop idempotently.
     *
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     *
     * @throws ReservationException
     */
    #[\NoDiscard('Reservation result must be used to build the HTTP response.')]
    public function reserve(User $user, Drop $drop, int $quantity, string $idempotencyKey): array
    {
        $idempotencyCacheKey = "idempotency:reserve:{$user->uuid}:{$idempotencyKey}";
        $idempotencyLockKey = "idempotency-lock:{$user->uuid}:{$idempotencyKey}";
        $dropLockKey = "drop:{$drop->uuid}:lock";
        $fingerprint = sha1("{$drop->uuid}|{$quantity}");

        // 1. Idempotency Kilidi (Atomik ve Güvenli) ne işe yarar ? -> Eger ayni istegi birden fazla kez gonderirsek hata verip engellemek icin
        $idempotencyLock = Cache::lock($idempotencyLockKey, 10);
        try {
            $idempotencyLock->block(5);
        } catch (LockTimeoutException) {
            throw new ReservationException('busy', 'Sistem şu anda meşgul, lütfen tekrar deneyin.', 503, ['Retry-After' => '1']);
        }

        try {
            // Önceki başarılı sonuç kontrolü
            $cached = Cache::get($idempotencyCacheKey);
            if ($cached !== null) {
                if (($cached['fingerprint'] ?? null) === $fingerprint) {
                    return [
                        'status' => (int) $cached['status'],
                        'body' => $cached['body'],
                        'replayed' => true,
                    ];
                }

                throw new ReservationException('idempotency_key_reused', 'Idempotency anahtarı farklı bir istek parametresiyle kullanılmış.', 422);
            }

            // 2. Drop Kilidi
            $dropLock = Cache::lock($dropLockKey, 10);
            try {
                $dropLock->block(5);
            } catch (LockTimeoutException) {
                throw new ReservationException('busy', 'Sistem şu anda meşgul, lütfen tekrar deneyin.', 503, ['Retry-After' => '1']);
            }

            try {
                // 3. Veritabanı İşlemleri (Pessimistic Locking)
                [$lockedDrop, $reservation] = DB::transaction(function () use ($drop, $user, $quantity) {
                    $lockedDrop = Drop::lockForUpdate()->findOrFail($drop->uuid);

                    $hasActive = Reservation::where('drop_uuid', $lockedDrop->uuid)
                        ->where('user_uuid', $user->uuid)
                        ->where(function ($query) {
                            $query->where('status', ReservationStatus::Confirmed)
                                ->orWhere(function ($q) {
                                    $q->where('status', ReservationStatus::Pending)
                                        ->where('expires_at', '>', now());
                                });
                        })
                        ->lockForUpdate()
                        ->exists();

                    if ($hasActive) {
                        throw new ReservationException('already_reserved', 'Bu drop için zaten aktif bir rezervasyonunuz bulunmaktadır.', 409);
                    }

                    if ($lockedDrop->available_stock < $quantity) {
                        throw new ReservationException('insufficient_stock', 'Yetersiz stok.', 409);
                    }

                    $lockedDrop->available_stock -= $quantity;
                    $lockedDrop->save();

                    $reservation = Reservation::create([
                        'user_uuid' => $user->uuid,
                        'drop_uuid' => $lockedDrop->uuid,
                        'quantity' => $quantity,
                        'status' => ReservationStatus::Pending,
                        'expires_at' => now()->addMinutes(self::RESERVATION_EXPIRY_MINUTES),
                    ]);

                    return [$lockedDrop, $reservation];
                });

                // 4. Redis Güncellemesi (DB tek kaynak)
                try {
                    Redis::set("drop:{$lockedDrop->uuid}:available_stock", $lockedDrop->available_stock);
                } catch (\Throwable $e) {
                    Log::error("Failed to update Redis available_stock for drop {$lockedDrop->uuid}: {$e->getMessage()}");
                }

                $responseBody = [
                    'data' => [
                        'uuid' => $reservation->uuid,
                        'drop_uuid' => $reservation->drop_uuid,
                        'quantity' => $reservation->quantity,
                        'status' => $reservation->status->value,
                        'expires_at' => $reservation->expires_at->toIso8601String(),
                    ],
                ];

                // Yalnızca başarılı sonuç saklanır
                Cache::put($idempotencyCacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => 201,
                    'body' => $responseBody,
                ], now()->addHours(self::IDEMPOTENCY_TTL_HOURS));

                return [
                    'status' => 201,
                    'body' => $responseBody,
                    'replayed' => false,
                ];
            } finally {
                $dropLock->release();
            }
        } finally {
            $idempotencyLock->release();
        }
    }

    /**
     * Confirm a pending reservation.
     *
     * @throws ReservationException
     */
    public function confirm(User $user, string $reservationUuid): Reservation
    {
        return DB::transaction(function () use ($user, $reservationUuid) {
            $reservation = Reservation::lockForUpdate()->where('uuid', $reservationUuid)->first();

            if (! $reservation) {
                throw new ReservationException('reservation_not_found', 'Rezervasyon bulunamadı.', 404);
            }

            if ($reservation->user_uuid !== $user->uuid) {
                throw new ReservationException('forbidden', 'Bu işlem için yetkiniz yok.', 403);
            }

            if ($reservation->status !== ReservationStatus::Pending) {
                throw new ReservationException('reservation_not_pending', 'Rezervasyon bekleme durumunda değil.', 409);
            }

            if ($reservation->expires_at <= now()) {
                throw new ReservationException('reservation_expired', 'Rezervasyon süresi dolmuş.', 422);
            }

            $reservation->status = ReservationStatus::Confirmed;
            $reservation->save();

            return $reservation;
        });
    }
}
