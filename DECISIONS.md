Sanctum’un kendi token tablosunun PK’si dokunulmadı, ödev yalnızca tokenable morph’unu UUID yapmayı istiyor 
Redis’te meta yoksa 404 dönülür, SQL fallback bilinçli olarak yok.
Cache yalnızca statik alanları tutar, stok her istekte Redis’ten birleştirilir.
Redis facade’ı laravel-database- önekini kullanır. 
Çift katmanlı kilit: Cache::lock Redis seviyesinde sıraya sokar, lockForUpdate ise DB’de satırı korur. Ödev ikisini birlikte istiyor.
Redis’e SET, DECRBY değil: DB gerçek kaynak. Commit sonrası DB değerini yazmak, sapma riskini azaltır.
Süresi dolan pending’ler: Stok iadesi bu fazda yok, drops:reconcile ve expire mantığı sonraki faza kalıyor. Süresi dolmuş pending, aktif sayılmadığı için kullanıcı yeniden rezerve edebilir, ama stok iadesi gelene kadar o miktar düşmüş kalır.
Tek bilinçli seçim: Hatalı (409) yanıtlar idempotency’de saklanmıyor, yani stok sonradan açılırsa aynı key ile tekrar denenebilir. 



# DECISIONS.md

## 1. Mimari özet
Flash-Drop, sınırlı stoklu "drop" satışları için Laravel 13 API'sidir. Kimlik doğrulama Sanctum ile yapılır. Kalıcı veri MySQL 8.4'te, gerçek zamanlı stok sayacı ve drop pencere bilgisi Redis'te tutulur. Hedefler: overselling olmaması, mükerrer isteğe karşı idempotency ve kapı kontrolünde sıfır SQL.

## 2. Strict UUID şeması
- `users`, `drops`, `reservations` tablolarında PK `uuid`'dir (UUID v7, `HasUuids`). İlişkilerde foreign/owner key açıkça yazılmıştır.
- `personal_access_tokens` için `uuidMorphs('tokenable')` kullanıldı. Sanctum'un kendi tablosundaki `id` auto-increment bırakıldı, çünkü ödev yalnızca `tokenable` morph'unun UUID olmasını istiyor.
- `jobs`, `failed_jobs` ve `sessions` Laravel varsayılanlarıyla bırakıldı (auto-increment / bigint). Proje queue ve session için bu tabloları kullanmıyor.

## 3. Seeder idempotency
- `FlashDropSeeder`, `updateOrCreate` yerine `firstOrCreate` kullanır. Aksi halde mevcut kaydın `available_stock` ve zaman değerleri ezilirdi. Kullanıcı `email`, drop `sku` üzerinden aranır.
- Redis stok anahtarı `SETNX` ile yazılır: anahtar varsa dokunulmaz, yoksa DB'deki güncel stok yazılır (`total_stock` değil). Pencere bilgisi (`drop:{uuid}:meta`) her seed'de yazılır.
- Zamanlar `now()` bazlıdır, bu yüzden "active" drop seed'den 7 gün sonra kapanır.
- `migrate:fresh` Redis'i temizlemez. Temiz başlangıç için önce Redis'te `FLUSHDB` çalıştırılır.

## 4. Çift katmanlı stok koruması
- Sütun tipi `unsignedInteger` negatif değeri reddeder (MySQL hata 1264).
- `CHECK (available_stock >= 0 AND available_stock <= total_stock)` ikinci katmandır (hata 3819). `reservations.quantity` için `CHECK (quantity BETWEEN 1 AND 2)` vardır.
- Asıl kontrol uygulama kodundadır, bu kısıtlar son savunma hattıdır.

## 5. Redis anahtarları ve önekler
- Stok sayacı ve meta verisi her zaman `Redis::` facade'ı ile okunur/yazılır, anahtarlar `laravel-database-` önekini alır (`redis.options.prefix`).
- `Cache::` çağrıları `CACHE_PREFIX` kullanır. Cache driver Redis'tir.
- Anahtarlar: `drop:{uuid}:available_stock` (string), `drop:{uuid}:meta` (hash: `starts_at`, `ends_at`).

## 6. Kimlik doğrulama
- `POST /api/v1/auth/token`: email, password, device_name doğrulanır. Başarılıysa `drops:read` ve `drops:reserve` yetenekleriyle token üretilir. Hatalı kimlik bilgisi 401 döner.
- `bootstrap/app.php` içinde `abilities` ve `ability` alias'ları tanımlıdır. Korumalı rotalar `auth:sanctum` + `ability:*` kullanır.
- Yetki kısıtlaması elle doğrulandı: yalnızca `drops:read` yetenekli token `reserve` rotasında 403, `GET /drops` rotasında 200 aldı.
- API istekleri her zaman JSON hata döner (`shouldRenderJsonWhen`).

## 7. EnsureDropWindowIsOpen
- Yalnızca `POST /drops/{drop}/reserve` rotasına bağlıdır. Sıra: `auth:sanctum`, `ability:drops:reserve`, `drop.window`.
- Önce `Idempotency-Key` header'ı UUID olarak doğrulanır (422), sonra `{drop}` UUID'si Redis meta'sı ile kontrol edilir. Başlamamışsa 403 `drop_not_started`, bitmişse 403 `drop_ended`.
- **Binding çakışması:** Route model binding SQL çalıştırır ve "sıfır SQL" şartını sessizce bozardı. Çözüm: `prependToPriorityList` ile middleware `SubstituteBindings`'ten önce çalıştırılır, `{drop}` ham string olarak gelir.
- Redis'te meta yoksa 404 `drop_not_found` döner. SQL'e fallback bilinçli olarak yoktur.
- Middleware içinde SQL sorgu sayısının 0 olduğu `DB::listen` ile ölçüldü. (Sanctum'un token sorgusu middleware'den önce çalışır ve sayıma dahil değildir.)

## 8. GET /drops
- Aktif ve gelecek drop'lar döner, süresi geçmişler hariçtir.
- `Cache::remember('drops:listing', 10 dk)` yalnızca statik alanları tutar. `available_stock` cache'e girmez.
- **Cache ile <1 sn stok çelişkisi:** Her istekte stoklar `Redis::pipeline()` ile tek round-trip'te çekilip birleştirilir. Böylece cache 10 dakika yaşasa da stok anlık görünür. Her istekte süresi dolanlar PHP tarafında tekrar filtrelenir.
- **Self-heal:** Redis'te stok anahtarı eksikse tek bir `whereIn` sorgusuyla DB'den okunup `SETNX` ile geri yazılır. Normal akışta SQL çalışmaz.
- **PHP 8.5 pipe operatörü** (`|>`) formatlama zincirinde kullanıldı (`mergeStock` ve `toResponse`). Sail imajı PHP 8.5.12'dir.

## 9. Reserve
- Doğrulama: `quantity` required|integer|min:1|max:2.
- **Kilit sırası:** idempotency kilidi, sonra drop kilidi (`Cache::lock`), sonra `DB::transaction` içinde `lockForUpdate`. Kilitler `finally` ile ters sırada bırakılır. Kilit alınamazsa 503 `busy` ve `Retry-After: 1`.
- Transaction içinde: kullanıcının bu drop için aktif (confirmed, ya da pending ve süresi dolmamış) rezervasyonu varsa 409 `already_reserved`; stok yetersizse 409 `insufficient_stock`. Aksi halde stok düşer ve 5 dakika süreli pending rezervasyon açılır.
- **Idempotency:** Aynı key ve aynı istek ise önceki yanıt `Idempotent-Replayed: true` ile döner. Aynı key farklı parametreyle gelirse 422 `idempotency_key_reused`. Yalnızca başarılı (201) yanıtlar saklanır (TTL 24 saat), hatalar saklanmaz ki tekrar denenebilsin.
- **Redis `SET`, `DECRBY` değil:** DB tek gerçek kaynaktır. Commit sonrası, drop kilidi hâlâ tutulurken Redis sayacı DB değerine eşitlenir. Redis hatası isteği başarısız saymaz, `Log::error` yazılır.
- `drops:listing` cache'i invalidate edilmez, çünkü stok içermez.

## 10. Confirm
- Satır `lockForUpdate` ile okunur. Sahibi değilse 403, pending değilse 409 `reservation_not_pending`, `expires_at <= now()` ise 422 `reservation_expired`. Geçerliyse status `confirmed` olur. Confirm stoğa dokunmaz.

## 11. drops:reconcile
- `php artisan drops:reconcile {--dry-run} {--force}`.
- Kilit: `Cache::lock('drops:reconcile')`. Kilit alınamazsa komut bekleme yapmadan exit code 1 ile çıkar. `--force` mevcut kilidi bırakıp çalışır. Dry-run da kilit alır.
- Hedef: `status = pending` ve `expires_at <= now()`.
- `--dry-run`: DB ve Redis'e yazmaz, drop bazında expire edilecek adet ve iade edilecek stoğu tablo olarak gösterir.
- Normal çalışma: drop başına ayrı transaction. Satırlar kilitlenip tekrar filtrelenir (confirm ile yarış olabilir, confirmed asla expire edilmez). Rezervasyonlar `expired` olur, stok quantity kadar artar ve **`total_stock` tavanı aşılmaz**. Redis, reserve ile aynı drop kilidi altında DB değerine eşitlenir.
- İkinci çalıştırma işlem yapmaz (idempotent).
- Scheduler'a kayıtlı değildir. Her dakika çalışacak şekilde schedule edilebilir.

## 12. OpenAPI (dedoc/scramble)
- `/docs/api` ve `/docs/api.json` çalışır. Bearer token şeması ve `Idempotency-Key` header'ı şemada yer alır. `auth/token` rotası `@unauthenticated` işaretlidir.
- Scramble varsayılan olarak yalnızca local ortamda erişilebilir. Üretimde yetkilendirme gerekir.

## 13. Testler
- Feature testleri: seeder idempotency, middleware (sıfır SQL dahil), overselling, reconcile. Sonuç: `[N test, M assertion, hepsi geçti]`.
- Testler ayrı MySQL veritabanı ve ayrı Redis DB/prefix'i üzerinde koşar, dev verisi etkilenmez. `[test DB adı ve Redis ayarını yaz]`. Testler `FLUSHDB` çağırmaz.
- Laravel test istemcisi istekleri tek süreçte sıralı işler. Overselling testi kodun mantığını doğrular, gerçek paralel yarışı kanıtlamaz.

## 14. Çözülen çelişkiler
- **`updateOrCreate` ve stok:** `updateOrCreate` mevcut `available_stock` değerini ezeceği için `firstOrCreate` + `SETNX` seçildi.
- **Middleware ve route model binding:** Binding SQL çalıştırdığı için middleware binding'den önce çalışacak şekilde sıralandı.
- **10 dk cache ve <1 sn stok:** Cache yalnızca statik alan tutar, stok her istekte Redis'ten birleştirilir.
- **Redis ve DB tutarlılığı:** DB tek kaynaktır, Redis commit sonrası `SET` ile eşitlenir.

## 15. Bilinen sınırlar
- **Paralellik doğrulanmadı.** Elle yapılan eşzamanlılık denemesi tek worker'lı `php artisan serve` üzerinde koştu (istekler fiilen sıralı işlendi). Sonuç doğruydu (5×201, 5×409, stok 0), ama kilitlerin gerçek paralel yarış altındaki davranışı kanıtlanmadı. `PHP_CLI_SERVER_WORKERS` ile çoklu worker denemesi yapılmadı.
- **Redis `SET` başarısız olursa** sayaç DB'den geride kalabilir. Reconcile yalnızca expire ettiği drop'ların Redis değerini eşitler, bu sapmayı genel olarak düzeltmez. `GET /drops` self-heal'i yalnızca anahtar eksikse devreye girer.
- **Idempotency yazımı commit'ten sonradır.** Commit ile `Cache::put` arasında süreç ölürse rezervasyon açılır ama key saklanmaz. Aynı key tekrar denenirse 409 `already_reserved` döner.
- Süresi dolan pending rezervasyonların stok iadesi yalnızca `drops:reconcile` çalıştığında yapılır. Schedule edilmediği sürece stok manuel çalıştırmaya kadar düşük kalır.

## 16. Kullanılan PHP 8.5 özellikleri
- Pipe operatörü (`|>`): `GET /drops` formatlama zincirinde. 



## 17. Güvenlik notları (geliştirme ortamı)
- Proje Laravel Sail ile yerel geliştirme için yapılandırıldı. Risk taraması (non-production profil) kritik/yüksek bulgu üretmedi.
- MySQL ve Redis portları yalnızca localhost'a bağlandı. [Yaptıysan yaz, yapmadıysan: "Sail varsayılanı olarak tüm arayüzlerde açık, üretimde kapatılmalıdır."]
- `MYSQL_ALLOW_EMPTY_PASSWORD` Sail varsayılanıdır, üretimde kullanılmamalıdır.
- Dosya izinleri bulguları Windows bind mount kaynaklıdır, üretimde 640/775 uygulanmalıdır.
- `.env` repoya girmez. Sohbette göründüğü için teslimden önce `php artisan key:generate` ile APP_KEY yenilenmelidir. 


PS C:\Users\ulasc\OneDrive\Masaüstü\Flash-Drop> docker compose exec laravel.test php artisan schedule:work

   INFO  Running scheduled tasks.

  2026-11-13 00:08:07 Running ['artisan' drops:reconcile] ............ 7s DONE
  ⇂ '/usr/bin/php8.5' 'artisan' drops:reconcile > '/dev/null' 2>&1  

  2026-11-13 00:09:07 Running ['artisan' drops:reconcile] ............ 8s DONE
  ⇂ '/usr/bin/php8.5' 'artisan' drops:reconcile > '/dev/null' 2>&1  

  2026-11-13 00:10:07 Running ['artisan' drops:reconcile] ^C
PS C:\Users\ulasc\OneDrive\Masaüstü\Flash-Drop>  En fazla 1 dakika 