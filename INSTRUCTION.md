# Flash-Drop Inventory API — Kapsamlı Teknik Rehber (INSTRUCTION.md)

Bu doküman; **Flash-Drop Inventory API** projesinin kurulum adımlarını, şartnamedeki (specification) mimari gerilimlerin kod tabanında nasıl çözüldüğünü, kullanılan PHP 8.5 özelliklerini, Redis veri yapılarını, test senaryolarını ve bilinen mimari sınırları (trade-offs) gerçek kod tabanıyla birebir uyumlu olarak açıklar.

---

## 1. Teknoloji Yığını (Tech Stack)

* **Framework:** Laravel 13.x (`php artisan install:api` tabanlı hafifletilmiş API yapısı)
* **Dil:** PHP 8.5 (`|>` Pipe Operator ve `#[\NoDiscard]` yerel özellikleri aktif olarak kullanılmıştır)
* **Veritabanı:** MySQL 8.0 (Yarış durumlarını ve stok aşımını önlemek için `SELECT ... FOR UPDATE` satır kilitlemesi ve transaction desteği)
* **Önbellek ve Durum Yönetimi:** Redis (Okuma önbelleği, atomik stok sayaçları, middleware meta verisi, idempotency kilitleri ve komut mutabakat kilitleri)
* **Kimlik Doğrulama:** Laravel Sanctum (UUID uyumlu `personal_access_tokens` ve yetenek/ability bazlı yetkilendirme)
* **API Dokümantasyonu:** `dedoc/scramble` (OpenAPI 3.1.0 şeması: `/docs/api` ve `/docs/api.json`)

---

## 2. Kurulum ve Çalıştırma Adımları (Laravel Sail / Docker)

### A. Konteynerleri Başlatma
```bash
docker compose up -d