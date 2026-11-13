# Flash-Drop Inventory & Reservation API

Laravel 13 ve PHP 8.5 kullanılarak geliştirilmiş; yüksek eşzamanlılık (concurrency), atomik Redis kilitleri, sıfır SQL sorgulu rota kapısı (`EnsureDropWindowIsOpen`) ve strict UUID v7 mimarisine sahip REST-first Flash-Drop envanter ve rezervasyon API'si.

> **Mimari Kararlar:** Şartnamedeki mimari gerilimlerin çözümleri, önbellek stratejisi, kilit sıralaması ve tasarım kararları için **[DECISIONS.md](./DECISIONS.md)** dosyasına göz atabilirsiniz.

---

## Teknoloji Yığını

- **Framework:** Laravel 13.x
- **Dil:** PHP 8.5 (`|>` Pipe Operator & `#[\NoDiscard]`)
- **Veritabanı:** MySQL 8.4 (InnoDB row-level locking & CHECK constraints)
- **Önbellek & Durum:** Redis (`phpredis` — atomik stok sayaçları, 10 dk liste önbelleği, idempotency ve süreç kilitleri)
- **Kimlik Doğrulama:** Laravel Sanctum (`uuidMorphs` uyumlu, token ability doğrulamalı)
- **API Dokümantasyonu:** `dedoc/scramble` (OpenAPI 3.1.0)

---

## Hızlı Kurulum (Laravel Sail / Docker)

### 1. Ortam Dosyasını Hazırlayın ve Konteynerleri Başlatın
```bash
cp .env.example .env
docker compose up -d