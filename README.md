# 50 Teknik Membuka Kelas Anti Ngantuk - Kang Deden Gurame

Landing page responsif, plugin integrasi iPaymu API v2 untuk WordPress (support produk fisik & digital, database order, manajemen nomor resi), dan template halaman terima kasih.

## 📁 Struktur File Proyek

* `index.html` — Landing page statis mandiri (Ultra Responsif, Tailwind CSS, Alpine.js, Font Awesome).
* `landing-page-template.php` — Page template WordPress untuk tema kustom.
* `ipaymu-integration.php` — Plugin WordPress kustom untuk integrasi iPaymu Payment Gateway API v2 (Multi-product: Fisik & Digital, database pesanan `wp_ipaymu_orders`, dashboard pesanan admin, dan manajemen resi).
* `terima-kasih.html` — Halaman konfirmasi pembayaran & status pengiriman statis.
* `page-terima-kasih.php` — Template halaman terima kasih universal untuk WordPress.
* `project.md` — Dokumentasi copywriting dan struktur materi produk.

## 🚀 Fitur Utama

1. **Desain Landing Page Modern & Ultra Responsif**:
   - Optimal di segala resolusi smartphone (320px–414px) hingga desktop.
   - Segmentasi audiens interaktif (Guru TK, SD, TPQ, Trainer).
   - Sticky floating mobile CTA bar dengan safe-area inset support.

2. **Integrasi iPaymu Gateway API v2**:
   - Mendukung mode **Sandbox** (testing) & **Production** (live).
   - Multi-tipe: **Produk Fisik** (dengan input alamat lengkap & manajemen resi kurir) & **Produk Digital** (akses instan).
   - Shortcodes: `[ipaymu_checkout_box]`, `[landingpage_50teknik]`.
   - Webhook callback handler otomatis (`/wp-json/ipaymu/v1/notify`).

3. **Manajemen Order di WP-Admin**:
   - Menu `iPaymu Orders` di sidebar WordPress.
   - Ringkasan total transaksi, order lunas, dan total omset.
   - Form update nomor resi & status pengiriman serta tombol 1-klik kirim resi ke WhatsApp pembeli.

## 🛠️ Lisensi & Hak Cipta
© 2026 GURAME — Guru Asyik Menyenangkan (Kang Deden Gurame).
