# UI W0–W3 Manual Test Findings

## Finding M-001 — Switch bahasa tidak terlihat pada halaman login

Status: OPEN — VERIFY AGAINST BLUEPRINT

Tanggal: 2026-08-01
Branch: ui-w0-w3-manual-test
Route: /login
Area: A1 Login / i18n
Reporter: Operator

### Actual

Operator membuka halaman login dan tidak menemukan switch bahasa ID/JP.

### Authority context

- UI_WIREFRAME_NOTES menyatakan semua layar internal memiliki toggle bahasa
  ID/JP; Tamu JP-only tanpa toggle.
- DESIGN.md menempatkan toggle ID/JP di authenticated app-shell top bar.
- A1 Login adalah route public/auth, sehingga perlu dipastikan apakah
  ketentuan semua layar internal mencakup halaman login sebelum autentikasi.
- Bahasa default yang terkunci adalah Indonesia (ID).

### Classification

Temuan ini belum langsung dinyatakan sebagai defect. Tidak ada perubahan kode
pada tahap pencatatan manual. Reviewer berikutnya harus memeriksa:

1. apakah login public memang harus menyediakan pilihan bahasa sebelum login;
2. apakah toggle hanya diwajibkan setelah user masuk ke authenticated shell;
3. apakah elemen sebenarnya tersembunyi karena viewport, asset, atau state
   halaman yang belum termuat.

### Reproduction

1. Pastikan browser logout.
2. Buka /login pada local application.
3. Periksa header/top area dan seluruh control yang dapat difokuskan keyboard.
4. Catat browser, viewport, locale awal, screenshot yang sudah disensor, dan
   actual visible controls.

### Expected untuk verifikasi

- Jika A1 termasuk layar internal: tersedia toggle ID/JP dan perubahan locale
  dapat digunakan sebelum login.
- Jika A1 adalah public auth screen: tidak adanya toggle dapat diterima,
  selama authenticated shell menyediakan toggle ID/JP untuk semua role internal.

### Next action

Reviewer manual masa depan meminta keputusan operator atas expected behavior,
mengisi status menjadi OPEN, ACCEPTED AS DESIGN, atau FAIL — FIX REQUIRED,
lalu melanjutkan A1. Jangan memperbaiki kode atau membuat keputusan produk
baru hanya berdasarkan finding ini.
