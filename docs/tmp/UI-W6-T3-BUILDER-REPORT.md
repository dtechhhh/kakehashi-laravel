# UI-W6-T3 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — Rate limit tiga lapis (Redis)**

## Ringkasan

Lapis rate-limit sudah menyatu di `GuestAccessService` (T2); T3 = bukti test
dedikasi untuk ketiga lapis, termasuk terhadap Redis nyata:

1. **Invalid** — 10 percobaan/menit/IP (token tak dikenal, format salah,
kadaluarsa). Hit ke-11 → 429 generik.
2. **Valid** — 60 request/menit **per token** (bukan per IP): kantor ber-NAT
dengan token kedua tetap bisa masuk setelah token pertama kena batas.
3. **Kode tambahan** — 5 gagal → lockout 15 menit per token+IP; percobaan
ke-6 (bahkan dengan kode benar) ditolak; `availableIn` ≈ 900 dtk.

## File diubah

- `tests/Feature/Guest/GuestRateLimitTest.php` (baru; 4 test, cache default test)
- `tests/Feature/Guest/GuestRateLimitRedisTest.php` (baru; 3 test, driver cache
di-set ke `redis`, REDIS_CACHE_DB=15 di-flush per test)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestRateLimitTest.php tests/Feature/Guest/GuestRateLimitRedisTest.php`
→ 7 passed / 158 assertions
- `vendor/bin/pint --test tests/Feature/Guest` → passed

## Risiko / catatan

- RateLimiter Laravel dipakai apa adanya; produksi memakai `CACHE_STORE=redis`
(terverifikasi di `RedisEnvironmentTest`). Test Redis memaksa driver redis dan
membuktikan threshold identik.
- Key limiter: `guest:invalid:{ip}`, `guest:valid:{token_hash}`,
`guest:code:{token_hash}:{ip}` — tanpa token mentah di key Redis.

## Siap review task? YA
