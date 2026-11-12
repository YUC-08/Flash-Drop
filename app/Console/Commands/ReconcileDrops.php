<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Drop;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ReconcileDrops extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'drops:reconcile {--dry-run : Sadece raporla, veritabanına ve Redis\'e yazma} {--force : Mevcut kilidi zorla serbest bırakıp çalıştır}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Süresi dolmuş pending rezervasyonları Expired durumuna getirir ve drop stoklarını iade eder.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lock = Cache::lock('drops:reconcile', 120);

        if ($this->option('force')) {
            $lock->forceRelease();
        }

        if (! $lock->get()) {
            $this->error('Başka bir reconcile çalışıyor');

            return self::FAILURE;
        }

        try {
            $now = now();

            // Süresi geçmiş pending rezervasyonları bul
            $expiredQuery = Reservation::query()
                ->where('status', ReservationStatus::Pending)
                ->where('expires_at', '<=', $now);

            $expiredReservations = (clone $expiredQuery)
                ->with('drop')
                ->get();

            if ($expiredReservations->isEmpty()) {
                $this->info('Süresi dolmuş rezervasyon yok');

                return self::SUCCESS;
            }

            // Drop bazında grupla
            $grouped = $expiredReservations->groupBy('drop_uuid');

            if ($this->option('dry-run')) {
                $rows = [];
                $totalCount = 0;
                $totalStock = 0;

                foreach ($grouped as $dropUuid => $reservations) {
                    $drop = $reservations->first()->drop ?? Drop::find($dropUuid);
                    $count = $reservations->count();
                    $refundStock = $reservations->sum('quantity');

                    $rows[] = [
                        $drop?->sku ?? 'N/A',
                        $dropUuid,
                        $count,
                        $refundStock,
                    ];

                    $totalCount += $count;
                    $totalStock += $refundStock;
                }

                $rows[] = ['Toplam', '-', $totalCount, $totalStock];

                $this->table(['Drop SKU', 'UUID', 'Expired Rezervasyon', 'İade Edilecek Stok'], $rows);

                return self::SUCCESS;
            }

            $totalExpired = 0;

            foreach ($grouped as $dropUuid => $reservations) {
                // Drop kilidi al (ReservationService ile aynı lock anahtarı)
                $dropLock = Cache::lock("drop:{$dropUuid}:lock", 10);

                try {
                    $dropLock->block(5);
                } catch (LockTimeoutException) {
                    $this->warn("Drop kilidi alınamadı, atlanıyor: {$dropUuid}");
                    Log::warning("Reconcile drop lock timeout for drop: {$dropUuid}");

                    continue;
                }

                try {
                    $lockedDrop = null;
                    $refundedQty = 0;
                    $count = 0;

                    DB::transaction(function () use ($dropUuid, &$lockedDrop, &$refundedQty, &$count) {
                        $lockedDrop = Drop::lockForUpdate()->find($dropUuid);

                        if (! $lockedDrop) {
                            return;
                        }

                        // Transaction içinde tekrar filtrele (confirm ile yarış olabilir)
                        $pendingExpired = Reservation::query()
                            ->where('drop_uuid', $dropUuid)
                            ->where('status', ReservationStatus::Pending)
                            ->where('expires_at', '<=', now())
                            ->lockForUpdate()
                            ->get();

                        if ($pendingExpired->isEmpty()) {
                            return;
                        }

                        $count = $pendingExpired->count();
                        $refundedQty = $pendingExpired->sum('quantity');

                        Reservation::whereIn('uuid', $pendingExpired->pluck('uuid'))
                            ->update(['status' => ReservationStatus::Expired]);

                        $targetStock = $lockedDrop->available_stock + $refundedQty;

                        if ($targetStock > $lockedDrop->total_stock) {
                            Log::warning("Reconcile stock exceeded total_stock for drop {$dropUuid}. Capped at total_stock.");
                            $targetStock = $lockedDrop->total_stock;
                        }

                        $lockedDrop->available_stock = $targetStock;
                        $lockedDrop->save();
                    });

                    if ($lockedDrop && $count > 0) {
                        // Redis'i DB değeri ile güncelle
                        try {
                            Redis::set("drop:{$lockedDrop->uuid}:available_stock", $lockedDrop->available_stock);
                        } catch (\Throwable $e) {
                            Log::error("Failed to update Redis stock during reconcile for drop {$lockedDrop->uuid}: {$e->getMessage()}");
                            $this->warn("Redis güncellenemedi: {$lockedDrop->uuid}");
                        }

                        $totalExpired += $count;
                        $this->line("Drop {$lockedDrop->sku} ({$dropUuid}): {$count} rezervasyon expire edildi, {$refundedQty} stok iade edildi. Güncel stok: {$lockedDrop->available_stock}");
                    }
                } finally {
                    $dropLock->release();
                }
            }

            $this->info("Reconcile tamamlandı. Toplam expire edilen rezervasyon: {$totalExpired}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
