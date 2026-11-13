# DECISIONS.md

- **Test kullanıcısı:** Seeder ile oluşan kullanıcı `test@example.com`, şifre `password`. Token almak için bu bilgiler `POST /api/v1/auth/token` isteğinde kullanılır (`device_name` herhangi bir metin olabilir).
- **API dokümantasyonu:** Uygulama çalışırken tarayıcıdan `http://localhost/docs/api` adresinde interaktif OpenAPI arayüzü, `http://localhost/docs/api.json` adresinde ham şema görülebilir.


## 1. Mimari özet
Flash-Drop, sınırlı stoklu "drop" satışları için Laravel 13 / PHP 8.5 REST API'sidir. Kimlik doğrulama Sanctum ile yapılır. Kalıcı veri MySQL 8.4'te, gerçek zamanlı stok sayacı ve drop pencere bilgisi Redis'te tutulur. Hedefler: overselling olmaması, mükerrer isteğe karşı idempotency ve kapı kontrolünde (middleware) sıfır SQL.

## 2. Strict UUID şeması
- `users`, `drops`, `reservations` tablolarında PK `uuid`'dir (UUID v7, `HasUuids`). Modellerde `$primaryKey = 'uuid'` ve ilişkilerde foreign/owner key açıkça yazılmıştır (`belongsTo(User::class, 'user_uuid', 'uuid')` gibi).
- `personal_access_tokens` için `uuidMorphs('tokenable')` kullanıldı, böylece Sanctum token'ları UUID'li `User` modeliyle çalışır. Bu tablonun kendi `id` sütunu Sanctum varsayılanı olarak auto-increment bırakıldı: şartname yalnızca `tokenable` morph'unun UUID olmasını ve domain tablolarında (`users`, `drops`, `reservations`) auto-increment olmamasını istiyor.
- `jobs`, `failed_jobs`, `sessions` Laravel varsayılanlarıyla bırakıldı. API stateless ve token tabanlıdır, bu tablolar iş akışında kullanılmaz.

## 3. Seeder idempotency
- `FlashDropSeeder`, `updateOrCreate` yerine `firstOrCreate` kullanır. `updateOrCreate` mevcut kaydın `available_stock` ve zaman değerlerini ezerdi. Kullanıcı `email`, drop `sku` üzerinden aranır; kayıt varsa hiçbir alanı (UUID dahil) değişmez.
- Redis stok anahtarı `SETNX` ile yazılır: anahtar varsa dokunulmaz, yoksa DB'deki güncel stok yazılır (`total_stock` değil). Böylece Redis temizlenirse bir sonraki seed gerçek stoğu geri yükler. Pencere bilgisi (`drop:{uuid}:meta`) her seed'de yazılır.
- Zamanlar `now()` bazlıdır, bu yüzden "active" drop seed'den 7 gün sonra kapanır.
- `migrate:fresh` Redis'i temizlemez, eski UUID'lerin anahtarları öksüz kalır. Laravel varsayılan olarak `Redis::` için DB 0, `Cache::` için DB 1 kullandığından, temiz başlangıç için önce Redis'te `FLUSHALL` (her iki DB'yi de temizlemek adına), sonra `migrate:fresh --seed` çalıştırılır. 

## 4. Çift katmanlı stok koruması
- `unsignedInteger` sütun tipi negatif değeri reddeder (MySQL hata 1264).
- `CHECK (available_stock >= 0 AND available_stock <= total_stock)` ikinci katmandır (hata 3819). `reservations.quantity` için `CHECK (quantity BETWEEN 1 AND 2)` vardır.
- Asıl kontrol uygulama kodundadır, bu kısıtlar son savunma hattıdır.

## 5. Redis anahtarları ve önekler
- Stok sayacı ve meta verisi her zaman `Redis::` facade'ı ile okunur/yazılır. Bu anahtarlar `laravel-database-` önekini alır (`redis.options.prefix`). `Cache::` çağrıları ise `CACHE_PREFIX` kullanır. Cache driver Redis'tir.
- Anahtarlar: `drop:{uuid}:available_stock` (string), `drop:{uuid}:meta` (hash: `starts_at`, `ends_at`).

## 6. Kimlik doğrulama
- `POST /api/v1/auth/token`: `email`, `password`, `device_name` doğrulanır. Başarılıysa `drops:read` ve `drops:reserve` yetenekleriyle token üretilir. Şartname hatalı kimlik bilgisi için kod belirtmiyor, 401 seçildi.
- `bootstrap/app.php` içinde `abilities` ve `ability` alias'ları tanımlıdır. Tüm rotalar (token endpoint'i hariç) `auth:sanctum` ve yetenek middleware'i ile korunur.
- Yetki kısıtlaması elle doğrulandı: yalnızca `drops:read` yetenekli token `reserve` rotasında 403, `GET /drops` rotasında 200 aldı.
- API istekleri her zaman JSON hata döner (`shouldRenderJsonWhen`).

## 7. EnsureDropWindowIsOpen
- Yalnızca `POST /drops/{drop}/reserve` rotasına bağlıdır. Sıra: `auth:sanctum`, `ability:drops:reserve`, `drop.window`.
- Önce `Idempotency-Key` header'ı UUID olarak doğrulanır (eksik/geçersiz: 422). Sonra `{drop}` UUID'si Redis meta'sı ile kontrol edilir: başlamamışsa 403 `drop_not_started`, bitmişse 403 `drop_ended`.
- **Binding çakışması:** Route model binding SQL çalıştırır ve "sıfır SQL" şartını sessizce bozardı. Çözüm: `bootstrap/app.php` içinde `prependToPriorityList` ile middleware `SubstituteBindings`'ten önce çalıştırılır, `{drop}` ham string olarak gelir. Bu bir testle de doğrulanır.
- Redis'te meta yoksa 404 `drop_not_found` döner. SQL'e fallback bilinçli olarak yoktur, aksi halde şartname ihlal edilirdi.
- Middleware içindeki SQL sorgu sayısının 0 olduğu `DB::listen` ile ölçüldü ve testlere eklendi. Sanctum'un token sorgusu middleware'den önce çalışır, sayıma dahil değildir.

## 8. GET /drops
- Aktif ve gelecek drop'lar döner, süresi geçmişler hariçtir.
- `Cache::remember('drops:listing', 10 dk)` yalnızca statik alanları tutar. `available_stock` cache'e girmez.
- **10 dk cache ve 1 sn stok çelişkisi:** Her istekte stoklar `Redis::pipeline()` ile tek round-trip'te çekilip listeyle birleştirilir. Cache 10 dakika yaşasa da stok anlık görünür ve Redis okuma yolundan çıkarılmaz. Her istekte süresi dolanlar PHP tarafında tekrar filtrelenir.
- **Self-heal:** Bir drop'un stok anahtarı Redis'te yoksa, yalnızca o drop'lar için tek bir `whereIn` sorgusuyla DB'den okunup `SETNX` ile geri yazılır. Anahtarlar mevcutken normal akışta SQL çalışmaz.
- Formatlama zincirinde PHP 8.5 pipe operatörü kullanıldı (bkz. bölüm 15).

## 9. Reserve
- Doğrulama: `quantity` required|integer|min:1|max:2.
- **Kilit sırası:** idempotency kilidi, sonra drop kilidi (`Cache::lock`), sonra `DB::transaction` içinde `lockForUpdate`. Kilitler `finally` ile ters sırada bırakılır. Kilit alınamazsa 503 `busy` ve `Retry-After: 1` döner. `Cache::lock` Redis seviyesinde istekleri sıraya sokar, `lockForUpdate` DB'de satırı korur. Şartname ikisinin birlikte kullanılmasını bekliyor.
- Transaction içinde: kullanıcının bu drop için aktif (confirmed, ya da pending ve süresi dolmamış) rezervasyonu varsa 409 `already_reserved`; stok yetersizse 409 `insufficient_stock`. Aksi halde stok düşer ve `expires_at = now()->addMinutes(5)` ile pending rezervasyon açılır.
- **Idempotency:** Aynı key ve aynı istek ise önceki yanıt `Idempotent-Replayed: true` ile döner, yeni rezervasyon açılmaz. Aynı key farklı parametreyle gelirse 422 `idempotency_key_reused`. Yalnızca başarılı (201) yanıtlar saklanır (TTL 24 saat). Hatalı yanıtlar (409 vb.) saklanmaz, böylece stok sonradan açılırsa aynı key ile tekrar denenebilir.
- **Redis `SET`, `DECRBY` değil:** DB tek gerçek kaynaktır. Commit sonrası, drop kilidi hâlâ tutulurken Redis sayacı DB değerine eşitlenir. Bu, sapma riskini azaltır. Redis hatası isteği başarısız saymaz, `Log::error` yazılır.
- `drops:listing` cache'i invalidate edilmez, çünkü stok içermez. `GET /drops` yeni stoğu Redis'ten anında okur.

## 10. Confirm
- Satır `lockForUpdate` ile okunur. Sahibi değilse 403 `forbidden`, pending değilse 409 `reservation_not_pending`, `expires_at <= now()` ise 422 `reservation_expired`. Geçerliyse status `confirmed` olur.
- Confirm stoğa dokunmaz. Confirmed rezervasyon satış sayılır: stok kalıcı olarak düşük kalır ve `drops:reconcile` tarafından asla expire edilmez.

## 11. drops:reconcile
- İmza: `drops:reconcile {--dry-run : Report expired reservations without mutating state} {--force : Bypass lock wait}`.
- Hedef: `status = pending` ve `expires_at <= now()`.
- `--dry-run`: DB ve Redis'e yazmaz, drop bazında expire edilecek adet ve iade edilecek stoğu tablo olarak gösterir. Dry-run da kilit alır.
- Normal çalışma: drop başına ayrı transaction. Önce drop satırı, sonra ilgili pending rezervasyonlar `lockForUpdate` ile kilitlenip tekrar filtrelenir (confirm ile yarış olabilir, confirmed asla expire edilmez). Rezervasyonlar `expired` olur, stok quantity kadar artar ve `total_stock` tavanı aşılmaz. Redis, reserve ile aynı drop kilidi altında DB değerine eşitlenir. Kilit sırası reserve ile aynıdır (drop kilidi, sonra transaction).
- Redis hatası komutu düşürmez, loglanır ve çıktıda uyarılır.
- İkinci çalıştırma işlem yapmaz (idempotent).
- **`--force` yorumu:** Şartname "Bypass lock wait" diyor. Bu belirsiz ifade şöyle yorumlandı: komut `Cache::lock('drops:reconcile')` kilidini beklemez. Kilit başka süreçteyse `--force` olmadan "başka bir reconcile çalışıyor" mesajıyla exit code 1 ile çıkar. `--force` ile mevcut kilit bırakılır ve komut çalışır (takılı kalmış kilit için). Aynı anda iki süreç çalışsa bile işlem güvenlidir: drop kilidi, satır kilidi ve status yeniden kontrolü sayesinde bir rezervasyon iki kez iade edilmez.
- **Scheduler:** `routes/console.php` içinde `Schedule::command('drops:reconcile')->everyMinute()->withoutOverlapping()` tanımlıdır. `schedule:work` ile çalıştığı elle görüldü. Çalışması için geliştirmede `php artisan schedule:work`, üretimde `schedule:run` çağıran bir cron gerekir.

## 12. OpenAPI (dedoc/scramble)
- `/docs/api` ve `/docs/api.json` çalışır. Bearer token şeması ve `Idempotency-Key` header'ı şemada yer alır. `auth/token` rotası `@unauthenticated` işaretlidir. Scramble OpenAPI 3.1 üretir.
- Scramble varsayılan olarak yalnızca local ortamda erişilebilir. Üretimde yetkilendirme gerekir.

## 13. Testler
- 12 test, 81 assertion, hepsi geçiyor. Kapsam: seeder idempotency, middleware (sıfır SQL ve ham UUID dahil), overselling, reconcile (dry-run, normal, tavan, kilit/`--force`).
- Testler ayrı MySQL veritabanı ve ayrı Redis ayarıyla koşar (`.env.testing`, `phpunit.xml`), dev verisi etkilenmez. Testler `FLUSHDB` çağırmaz.
- Laravel test istemcisi istekleri tek süreçte sıralı işler. Overselling testi kodun mantığını doğrular, gerçek paralel yarışı kanıtlamaz.

## 14. Çözülen çelişkiler ve belirsizlikler
- **`updateOrCreate` ve stok:** `updateOrCreate` mevcut `available_stock` değerini ezeceği için `firstOrCreate` + `SETNX` seçildi.
- **Middleware ve route model binding:** Binding SQL çalıştırdığı için middleware binding'den önce çalışacak şekilde sıralandı.
- **10 dk cache ve 1 sn stok:** Cache yalnızca statik alan tutar, stok her istekte Redis'ten birleştirilir.
- **Redis ve DB tutarlılığı:** DB tek kaynaktır, Redis commit sonrası `SET` ile eşitlenir.
- **"1 saniye" şartı ve expire:** Reserve sonrası stok anında görünür (commit sonrası Redis güncellenir). Süresi dolan rezervasyonun stoğu ise `drops:reconcile` ile iade edilir ve komut her dakika çalışır. Expire tarafında stok iadesi bu yüzden en fazla yaklaşık 1 dakika gecikebilir. Bu bilinçli bir ödündür. Süresi dolmuş pending rezervasyon "aktif" sayılmadığı için kullanıcı iade beklemeden yeniden rezerve edebilir.
- **`--force`:** Bölüm 11'deki yorum.
- **401 ve 422:** Şartname hatalı kimlik bilgisi kodunu belirtmiyor, 401 kullanıldı.

## 15. Kullanılan PHP 8.5 özellikleri
- **Pipe operatörü (`|>`):** `app/Http/Controllers/Api/DropController.php` içinde `GET /drops` formatlama zincirinde (`mergeStock` ve `toResponse`).
- **`#[\NoDiscard]`:** `app/Services/ReservationService.php` içinde `reserve()` üzerinde. Rezervasyon sonucunun (201 veya replay) çağıran tarafından yok sayılmasını önler.

## 16. Bilinen sınırlar
- **Paralellik doğrulanmadı.** Elle yapılan eşzamanlılık denemesi tek worker'lı `php artisan serve` üzerinde koştu, istekler fiilen sıralı işlendi. Sonuç doğruydu (5×201, 5×409, stok 0), ama kilitlerin gerçek paralel yarış altındaki davranışı kanıtlanmadı. `PHP_CLI_SERVER_WORKERS` ile çoklu worker denemesi yapılmadı.
- **Redis `SET` başarısız olursa** sayaç DB'den geride kalabilir. Reconcile yalnızca expire ettiği drop'ların Redis değerini eşitler, bu sapmayı genel olarak düzeltmez. `GET /drops` self-heal'i yalnızca anahtar eksikse devreye girer.
- **Idempotency yazımı commit'ten sonradır.** Commit ile `Cache::put` arasında süreç ölürse rezervasyon açılır ama key saklanmaz. Aynı key tekrar denenirse 409 `already_reserved` döner.
- **Expire gecikmesi:** Stok iadesi scheduler'a bağlıdır (bölüm 11 ve 14). Scheduler çalışmıyorsa süresi dolan stok `drops:reconcile` elle çalıştırılana kadar düşük kalır.
- **`withoutOverlapping()` mutex'i:** Scheduler süreci görev sırasında zorla sonlandırılırsa mutex (varsayılan olarak 24 saate kadar) kalıp sonraki çalıştırmaları atlatabilir. Bu durumda `php artisan schedule:clear-cache` çalıştırılır. 
docker compose exec laravel.test php artisan schedule:work

## 17. Güvenlik notları (geliştirme ortamı)
- Proje Laravel Sail ile yerel geliştirme için yapılandırıldı. Risk taraması (non-production profil) kritik veya yüksek bulgu üretmedi.
- MySQL ve Redis portları Sail varsayılanı olarak tüm arayüzlerde açıktır, üretimde localhost'a bağlanmalı veya kapatılmalıdır.
- `MYSQL_ALLOW_EMPTY_PASSWORD` Sail varsayılanıdır, üretimde kullanılmamalıdır.
- Dosya izinleri bulguları Windows bind mount kaynaklıdır, üretimde 640/775 uygulanmalıdır.
- `.env` repoya girmez. `APP_KEY` yerel geliştirme için üretilmiştir, her ortamda ayrı üretilmelidir.