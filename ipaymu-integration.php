<?php
/**
 * Plugin Name: iPaymu Gateway & WhatsApp Order - 50 Teknik Anti Ngantuk
 * Description: Integrasi Pemesanan WhatsApp & iPaymu Gateway API v2 untuk WordPress (Mendukung Produk Fisik & Digital, Promo Rp 80.000, Database Order, Manajemen Resi, Toggle WhatsApp / iPaymu).
 * Version: 3.2.0
 * Author: Kang Deden Gurame
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class IPaymu_Custom_Gateway {

    private static $table_name;

    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'ipaymu_orders';

        // Buat atau perbarui tabel database
        register_activation_hook(__FILE__, [$this, 'create_orders_database_table']);

        // Daftarkan Menu di Sidebar WordPress
        add_action('admin_menu', [$this, 'register_admin_menus']);
        add_action('admin_init', [$this, 'create_orders_database_table']);
        add_action('admin_init', [$this, 'register_plugin_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);

        // Tambahkan link "Pengaturan" & "Data Pesanan" di daftar plugin
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);

        // Daftarkan Shortcodes
        add_shortcode('ipaymu_checkout_box', [$this, 'render_checkout_box_shortcode']);
        add_shortcode('landingpage_50teknik', [$this, 'render_full_landingpage_shortcode']);

        // Daftarkan AJAX endpoint order
        add_action('wp_ajax_nopriv_ipaymu_submit_order', [$this, 'handle_order_submission']);
        add_action('wp_ajax_ipaymu_submit_order', [$this, 'handle_order_submission']);

        // Daftarkan Webhook REST API route
        add_action('rest_api_init', function () {
            register_rest_route('ipaymu/v1', '/notify', [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_webhook_notification'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('ipaymu/v1', '/order', [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_rest_order_submission'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Membuat & Menyesuaikan Tabel Database `wp_ipaymu_orders` (Dengan Auto Column Migration)
     */
    public function create_orders_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ipaymu_orders';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            reference_id varchar(100) NOT NULL,
            product_type varchar(50) NOT NULL DEFAULT 'physical',
            product_name varchar(255) NOT NULL,
            customer_name varchar(255) NOT NULL,
            customer_phone varchar(50) NOT NULL,
            customer_email varchar(150) NOT NULL,
            shipping_address text DEFAULT NULL,
            shipping_city varchar(150) DEFAULT NULL,
            shipping_subdistrict varchar(150) DEFAULT NULL,
            shipping_postal_code varchar(20) DEFAULT NULL,
            shipping_notes text DEFAULT NULL,
            tracking_number varchar(100) DEFAULT '',
            shipping_courier varchar(50) DEFAULT '',
            shipping_status varchar(50) NOT NULL DEFAULT 'BELUM_DIKIRIM',
            amount int(11) NOT NULL,
            payment_method varchar(50) NOT NULL DEFAULT 'whatsapp',
            payment_url text DEFAULT NULL,
            session_id varchar(255) DEFAULT NULL,
            trx_id varchar(100) DEFAULT NULL,
            payment_channel varchar(100) DEFAULT 'WhatsApp CS',
            status varchar(50) NOT NULL DEFAULT 'PENDING',
            environment varchar(20) NOT NULL DEFAULT 'whatsapp',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY reference_id (reference_id),
            KEY status (status),
            KEY product_type (product_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verifikasi dan Auto-tambah Kolom jika tabel sudah pernah dibuat sebelumnya
        $existing_columns = $wpdb->get_col("DESC " . $table_name, 0);
        if (!empty($existing_columns)) {
            $required_columns = [
                'reference_id'         => "VARCHAR(100) NOT NULL",
                'product_type'         => "VARCHAR(50) NOT NULL DEFAULT 'physical'",
                'product_name'         => "VARCHAR(255) NOT NULL",
                'customer_name'        => "VARCHAR(255) NOT NULL",
                'customer_phone'       => "VARCHAR(50) NOT NULL",
                'customer_email'       => "VARCHAR(150) NOT NULL",
                'shipping_address'     => "TEXT DEFAULT NULL",
                'shipping_city'        => "VARCHAR(150) DEFAULT NULL",
                'shipping_subdistrict' => "VARCHAR(150) DEFAULT NULL",
                'shipping_postal_code' => "VARCHAR(20) DEFAULT NULL",
                'shipping_notes'       => "TEXT DEFAULT NULL",
                'tracking_number'      => "VARCHAR(100) DEFAULT ''",
                'shipping_courier'     => "VARCHAR(50) DEFAULT ''",
                'shipping_status'      => "VARCHAR(50) NOT NULL DEFAULT 'BELUM_DIKIRIM'",
                'amount'               => "INT(11) NOT NULL",
                'payment_method'       => "VARCHAR(50) NOT NULL DEFAULT 'whatsapp'",
                'payment_url'          => "TEXT DEFAULT NULL",
                'session_id'           => "VARCHAR(255) DEFAULT NULL",
                'trx_id'               => "VARCHAR(100) DEFAULT NULL",
                'payment_channel'      => "VARCHAR(100) DEFAULT 'WhatsApp CS'",
                'status'               => "VARCHAR(50) NOT NULL DEFAULT 'PENDING'",
                'environment'          => "VARCHAR(20) NOT NULL DEFAULT 'whatsapp'",
                'created_at'           => "DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL",
                'updated_at'           => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL"
            ];

            foreach ($required_columns as $col => $definition) {
                if (!in_array($col, $existing_columns)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN {$col} {$definition}");
                }
            }
        }
    }

    /**
     * Handle Update No Resi dari Admin
     */
    public function handle_admin_actions() {
        if (isset($_POST['ipaymu_update_shipping']) && check_admin_referer('ipaymu_update_shipping_action')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'ipaymu_orders';
            $order_id   = intval($_POST['order_id']);
            $resi       = sanitize_text_field($_POST['tracking_number']);
            $courier    = sanitize_text_field($_POST['shipping_courier']);
            $ship_status= sanitize_text_field($_POST['shipping_status']);
            $pay_status = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : '';

            $data_update = [
                'tracking_number'  => $resi,
                'shipping_courier' => $courier,
                'shipping_status'  => $ship_status,
                'updated_at'       => current_time('mysql')
            ];

            if (!empty($pay_status)) {
                $data_update['status'] = $pay_status;
            }

            $wpdb->update(
                $table_name,
                $data_update,
                ['id' => $order_id]
            );

            wp_redirect(admin_url('admin.php?page=ipaymu-orders-list&updated=1'));
            exit;
        }
    }

    /**
     * Registrasi Menu Utama & Submenu di Sidebar WP-Admin
     */
    public function register_admin_menus() {
        add_menu_page(
            'Data Pesanan',
            'Data Pesanan',
            'manage_options',
            'ipaymu-orders-list',
            [$this, 'render_orders_list_page'],
            'dashicons-cart',
            56
        );

        add_submenu_page(
            'ipaymu-orders-list',
            'Data Semua Pesanan',
            '📋 Data Pesanan',
            'manage_options',
            'ipaymu-orders-list',
            [$this, 'render_orders_list_page']
        );

        add_submenu_page(
            'ipaymu-orders-list',
            'Pengaturan Gateway & WhatsApp',
            '⚙️ Pengaturan',
            'manage_options',
            'ipaymu-gateway-settings',
            [$this, 'render_admin_settings_page']
        );
    }

    /**
     * Link cepat di halaman Plugins
     */
    public function add_plugin_action_links($links) {
        $orders_link = '<a href="' . admin_url('admin.php?page=ipaymu-orders-list') . '" style="font-weight:bold; color:#008a20;">📋 Data Pesanan</a>';
        $settings_link = '<a href="' . admin_url('admin.php?page=ipaymu-gateway-settings') . '" style="font-weight:bold; color:#0052cc;">⚙️ Pengaturan</a>';
        array_unshift($links, $orders_link, $settings_link);
        return $links;
    }

    /**
     * Mendaftarkan opsi pengaturan ke WordPress Database
     */
    public function register_plugin_settings() {
        register_setting('ipaymu_settings_group', 'ipaymu_checkout_mode'); // whatsapp | ipaymu
        register_setting('ipaymu_settings_group', 'ipaymu_cs_whatsapp');
        register_setting('ipaymu_settings_group', 'ipaymu_mode');
        register_setting('ipaymu_settings_group', 'ipaymu_product_type'); // physical | digital
        register_setting('ipaymu_settings_group', 'ipaymu_product_name');
        register_setting('ipaymu_settings_group', 'ipaymu_product_price');
        register_setting('ipaymu_settings_group', 'ipaymu_normal_price');
        register_setting('ipaymu_settings_group', 'ipaymu_va');
        register_setting('ipaymu_settings_group', 'ipaymu_api_key');
        register_setting('ipaymu_settings_group', 'ipaymu_return_url');
        register_setting('ipaymu_settings_group', 'ipaymu_cancel_url');
    }

    /**
     * Halaman Dashboard: Data Pesanan di WP-Admin
     */
    public function render_orders_list_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ipaymu_orders';

        $this->create_orders_database_table();

        $orders = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 100");
        $total_orders  = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_paid    = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status IN ('PAID', 'berhasil', 'SUCCESS', 'LUNAS')");
        $total_physical= $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE product_type = 'physical'");
        $total_revenue = $wpdb->get_var("SELECT SUM(amount) FROM $table_name WHERE status IN ('PAID', 'berhasil', 'SUCCESS', 'LUNAS')");
        ?>
        <div class="wrap" style="max-width: 1320px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin: 20px 0 15px;">
                <div>
                    <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #1e293b;">📋 Data Pesanan Masuk (WhatsApp & iPaymu)</h1>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Data pesanan masuk, alamat pengiriman buku fisik, status pembayaran, dan nomor resi.</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=ipaymu-gateway-settings'); ?>" class="button button-secondary" style="font-weight: 600;">⚙️ Buka Pengaturan</a>
            </div>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 15px;"><p>Data pesanan berhasil diperbarui!</p></div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
                <div style="background: #fff; padding: 14px 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Transaksi</span>
                    <div style="font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 2px;"><?php echo number_format($total_orders); ?></div>
                </div>
                <div style="background: #fff; padding: 14px 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 11px; font-weight: 600; color: #16a34a; text-transform: uppercase;">Sudah Lunas</span>
                    <div style="font-size: 22px; font-weight: 800; color: #16a34a; margin-top: 2px;"><?php echo number_format($total_paid); ?></div>
                </div>
                <div style="background: #fff; padding: 14px 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 11px; font-weight: 600; color: #ea580c; text-transform: uppercase;">📦 Produk Fisik</span>
                    <div style="font-size: 22px; font-weight: 800; color: #ea580c; margin-top: 2px;"><?php echo number_format($total_physical); ?></div>
                </div>
                <div style="background: #fff; padding: 14px 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase;">Total Omset (Lunas)</span>
                    <div style="font-size: 22px; font-weight: 800; color: #2563eb; margin-top: 2px;">Rp <?php echo number_format((int)$total_revenue, 0, ',', '.'); ?></div>
                </div>
            </div>

            <!-- Orders Table -->
            <div style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.03); overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped table-view-list" style="border: none; margin: 0;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px 14px; font-weight: 700; width: 45px;">ID</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 160px;">Pembeli & Kontak</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 240px;">Alamat Pengiriman Paket</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 95px;">Nominal</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 110px;">Status Bayar</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 220px;">Kurir & No. Resi</th>
                            <th style="padding: 12px 14px; font-weight: 700; width: 120px;">Waktu Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($orders)) : ?>
                            <?php foreach ($orders as $order) : 
                                $is_paid = in_array(strtoupper($order->status), ['PAID', 'BERHASIL', 'SUCCESS', 'LUNAS']);
                                $status_badge = $is_paid 
                                    ? '<span style="background:#dcfce7; color:#15803d; padding:2px 7px; border-radius:5px; font-weight:700; font-size:10px;">✅ LUNAS</span>' 
                                    : '<span style="background:#fef9c3; color:#a16207; padding:2px 7px; border-radius:5px; font-weight:700; font-size:10px;">⏳ PENDING</span>';

                                $clean_phone = preg_replace('/[^0-9]/', '', $order->customer_phone);
                                if (substr($clean_phone, 0, 1) === '0') {
                                    $clean_phone = '62' . substr($clean_phone, 1);
                                }

                                $wa_msg = rawurlencode("Halo Kak {$order->customer_name},\n\nTerima kasih telah memesan {$order->product_name}.\nPaket Anda telah kami kirim via " . ($order->shipping_courier ?: 'Ekspedisi') . " dengan No. Resi: *" . ($order->tracking_number ?: '-') . "*\n\nTerima kasih!");
                            ?>
                                <tr>
                                    <td style="padding: 12px 14px; font-weight: 600; color: #64748b;">#<?php echo esc_html($order->id); ?></td>
                                    <td style="padding: 12px 14px;">
                                        <div style="font-weight: 700; color: #0f172a;"><?php echo esc_html($order->customer_name); ?></div>
                                        <div style="font-size: 11px; margin-top: 2px;">
                                            <a href="https://wa.me/<?php echo esc_attr($clean_phone); ?>" target="_blank" style="color: #16a34a; text-decoration: none; font-weight: 600;">
                                                💬 <?php echo esc_html($order->customer_phone); ?>
                                            </a>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b;"><?php echo esc_html($order->customer_email); ?></div>
                                    </td>
                                    <td style="padding: 12px 14px; font-size: 12px; line-height: 1.4;">
                                        <div style="font-weight: 600; color: #1e293b;"><?php echo esc_html($order->shipping_address ?: '-'); ?></div>
                                        <div style="color: #475569; margin-top: 2px;">
                                            📍 <?php echo esc_html($order->shipping_subdistrict . ($order->shipping_city ? ', ' . $order->shipping_city : '')); ?>
                                            <?php if($order->shipping_postal_code) echo ' - ' . esc_html($order->shipping_postal_code); ?>
                                        </div>
                                        <?php if(!empty($order->shipping_notes)): ?>
                                            <div style="font-size: 11px; color: #d97706; margin-top: 2px; font-style: italic;">
                                                📝 <?php echo esc_html($order->shipping_notes); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 14px; font-weight: 700; color: #0f172a;">Rp <?php echo number_format($order->amount, 0, ',', '.'); ?></td>
                                    <td style="padding: 12px 14px;"><?php echo $status_badge; ?></td>
                                    
                                    <!-- Edit Resi & Status Form -->
                                    <td style="padding: 12px 14px;">
                                        <form method="post" action="" style="margin: 0;">
                                            <?php wp_nonce_field('ipaymu_update_shipping_action'); ?>
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->id); ?>">
                                            
                                            <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                                                <input type="text" name="shipping_courier" value="<?php echo esc_attr($order->shipping_courier); ?>" placeholder="Kurir" style="width: 70px; font-size: 11px; padding: 2px 4px; border-radius: 4px;">
                                                <input type="text" name="tracking_number" value="<?php echo esc_attr($order->tracking_number); ?>" placeholder="No. Resi" style="width: 110px; font-size: 11px; padding: 2px 4px; border-radius: 4px;">
                                            </div>
                                            
                                            <div style="display: flex; gap: 4px; align-items: center;">
                                                <select name="shipping_status" style="font-size: 10px; padding: 2px 4px; border-radius: 4px; height: 24px;">
                                                    <option value="BELUM_DIKIRIM" <?php selected($order->shipping_status, 'BELUM_DIKIRIM'); ?>>⏳ Belum Kirim</option>
                                                    <option value="SEDANG_DIKIRIM" <?php selected($order->shipping_status, 'SEDANG_DIKIRIM'); ?>>🚚 Dikirim</option>
                                                    <option value="SUDAH_DIKIRIM" <?php selected($order->shipping_status, 'SUDAH_DIKIRIM'); ?>>✅ Sampai</option>
                                                </select>

                                                <select name="payment_status" style="font-size: 10px; padding: 2px 4px; border-radius: 4px; height: 24px;">
                                                    <option value="PENDING" <?php selected($order->status, 'PENDING'); ?>>Pending</option>
                                                    <option value="LUNAS" <?php selected($order->status, 'LUNAS'); ?>>Lunas</option>
                                                </select>

                                                <button type="submit" name="ipaymu_update_shipping" class="button button-small" style="font-size: 10px; height: 24px; padding: 0 6px;">Simpan</button>
                                            </div>
                                        </form>

                                        <?php if (!empty($order->tracking_number)) : ?>
                                            <div style="margin-top: 4px;">
                                                <a href="https://wa.me/<?php echo esc_attr($clean_phone); ?>?text=<?php echo $wa_msg; ?>" target="_blank" style="font-size: 10px; color: #16a34a; font-weight: 700; text-decoration: none;">
                                                    📲 Kirim Resi via WA
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 14px; color: #64748b; font-size: 11px;">
                                        <?php echo esc_html(date('d M Y, H:i', strtotime($order->created_at))); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                                    Belum ada data pesanan yang masuk. Lakukan tes order dari halaman landing page.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
    }

    /**
     * Halaman Pengaturan di WP-Admin
     */
    public function render_admin_settings_page() {
        $checkout_mode= get_option('ipaymu_checkout_mode', 'whatsapp'); // whatsapp | ipaymu
        $cs_whatsapp  = get_option('ipaymu_cs_whatsapp', '085713911142');
        $mode         = get_option('ipaymu_mode', 'sandbox');
        $product_type = get_option('ipaymu_product_type', 'physical'); // physical | digital
        $product_name = get_option('ipaymu_product_name', '50 TEKNIK MEMBUKA KELAS ANTI NGANTUK');
        $va           = get_option('ipaymu_va', '0000002410214040');
        $api_key      = get_option('ipaymu_api_key', 'SANDBOX78B457D7-E682-4217-A630-B14704B14BFA');
        $price        = get_option('ipaymu_product_price', '80000');
        $normal_price = get_option('ipaymu_normal_price', '100000');
        $return_url   = get_option('ipaymu_return_url', home_url('/terima-kasih/'));
        $cancel_url   = get_option('ipaymu_cancel_url', home_url('/'));
        $notify_url   = rest_url('ipaymu/v1/notify');
        ?>
        <div class="wrap" style="max-width: 920px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            
            <div style="background: #fff; padding: 25px 30px; border-radius: 12px; border: 1px solid #ccd0d4; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-top: 20px;">
                
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #f0f0f1; padding-bottom: 15px; margin-bottom: 20px;">
                    <div>
                        <h1 style="margin: 0; color: #1d2327; font-size: 24px; font-weight: 700;">⚙️ Pengaturan Pemesanan & Gateway</h1>
                        <p style="margin: 5px 0 0; color: #646970; font-size: 13px;">Pengaturan jalur pemesanan (WhatsApp Langsung / iPaymu Gateway API v2).</p>
                    </div>
                    <span style="background: #008a20; color: #fff; font-weight: bold; font-size: 12px; padding: 5px 12px; border-radius: 20px;">v3.2.0 Ready</span>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields('ipaymu_settings_group'); ?>
                    <?php do_settings_sections('ipaymu_settings_group'); ?>

                    <table class="form-table" style="margin-top: 0;">
                        
                        <!-- Jalur Pemesanan Utama (WhatsApp vs iPaymu) -->
                        <tr style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                            <th scope="row" style="width: 240px; font-weight: 700; font-size: 14px; color: #166534; padding: 16px;">
                                🚀 Jalur Pemesanan Saat Ini
                            </th>
                            <td style="padding: 16px;">
                                <fieldset style="display: flex; gap: 20px; flex-wrap: wrap;">
                                    <label style="font-weight: 700; cursor: pointer; color: #16a34a;">
                                        <input type="radio" name="ipaymu_checkout_mode" value="whatsapp" <?php checked($checkout_mode, 'whatsapp'); ?>>
                                        <span>📲 WhatsApp Langsung (Aktif Sekarang)</span>
                                    </label>
                                    <label style="font-weight: 700; cursor: pointer; color: #0284c7;">
                                        <input type="radio" name="ipaymu_checkout_mode" value="ipaymu" <?php checked($checkout_mode, 'ipaymu'); ?>>
                                        <span>💳 iPaymu Gateway (Pakai jika verifikasi akun selesai)</span>
                                    </label>
                                </fieldset>
                                <p class="description" style="margin-top: 6px; color: #15803d;">
                                    Saat mode <strong>WhatsApp Langsung</strong> aktif, pembeli yang mengisi form akan diarahkan langsung chat ke WhatsApp CS dengan format pemesanan otomatis & data order tetap tersimpan di database.
                                </p>
                            </td>
                        </tr>

                        <!-- Nomor WhatsApp CS -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Nomor WhatsApp CS</th>
                            <td>
                                <input type="text" name="ipaymu_cs_whatsapp" value="<?php echo esc_attr($cs_whatsapp); ?>" class="regular-text" style="width: 240px; border-radius: 6px; padding: 8px 12px;" required>
                                <p class="description">Contoh: 085713911142 atau 6285713911142.</p>
                            </td>
                        </tr>

                        <!-- Harga Normal & Promo -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Harga Normal (Rp)</th>
                            <td>
                                <input type="number" name="ipaymu_normal_price" value="<?php echo esc_attr($normal_price); ?>" class="regular-text" style="width: 200px; border-radius: 6px; padding: 8px 12px;" required>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Harga Promo Pesan Hari Ini (Rp)</th>
                            <td>
                                <input type="number" name="ipaymu_product_price" value="<?php echo esc_attr($price); ?>" class="regular-text" style="width: 200px; border-radius: 6px; padding: 8px 12px;" required>
                                <p class="description">Nominal promo yang ditagihkan ke pembeli (Contoh: 80000).</p>
                            </td>
                        </tr>

                        <!-- Tipe Produk Utama -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Tipe Produk</th>
                            <td>
                                <label style="margin-right: 25px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="ipaymu_product_type" value="physical" <?php checked($product_type, 'physical'); ?>>
                                    <span>📦 Produk Fisik (Buku Fisik & Butuh Alamat Pengiriman)</span>
                                </label>
                                <label style="font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="ipaymu_product_type" value="digital" <?php checked($product_type, 'digital'); ?>>
                                    <span>⚡ Produk Digital</span>
                                </label>
                            </td>
                        </tr>

                        <!-- Nama Produk -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Nama Produk</th>
                            <td>
                                <input type="text" name="ipaymu_product_name" value="<?php echo esc_attr($product_name); ?>" class="regular-text" style="width: 100%; max-width: 450px; border-radius: 6px; padding: 8px 12px;" required>
                            </td>
                        </tr>

                        <!-- Pengaturan iPaymu Gateway (Opsional jika sudah verifikasi) -->
                        <tr>
                            <th colspan="2" style="padding-top: 25px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0;">
                                <h3 style="margin: 0; color: #0284c7; font-size: 16px;">💳 Pengaturan API iPaymu (Digunakan saat Mode iPaymu Aktif)</h3>
                            </th>
                        </tr>

                        <!-- Mode Lingkungan iPaymu -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Mode iPaymu</th>
                            <td>
                                <label style="margin-right: 25px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="ipaymu_mode" value="sandbox" <?php checked($mode, 'sandbox'); ?>>
                                    <span style="color: #d63638;">🧪 Sandbox (Testing)</span>
                                </label>
                                <label style="font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="ipaymu_mode" value="live" <?php checked($mode, 'live'); ?>>
                                    <span style="color: #008a20;">🚀 Production (Live)</span>
                                </label>
                            </td>
                        </tr>

                        <!-- Virtual Account -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Virtual Account (VA)</th>
                            <td>
                                <input type="text" name="ipaymu_va" value="<?php echo esc_attr($va); ?>" class="regular-text" style="width: 100%; max-width: 420px; border-radius: 6px; padding: 8px 12px;">
                            </td>
                        </tr>

                        <!-- API Key -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">API Key</th>
                            <td>
                                <input type="password" name="ipaymu_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" style="width: 100%; max-width: 420px; border-radius: 6px; padding: 8px 12px;">
                            </td>
                        </tr>

                        <!-- Return URL -->
                        <tr>
                            <th scope="row" style="font-weight: 600; font-size: 14px;">Return URL</th>
                            <td>
                                <input type="url" name="ipaymu_return_url" value="<?php echo esc_attr($return_url); ?>" class="regular-text" style="width: 100%; max-width: 520px; border-radius: 6px; padding: 8px 12px;">
                            </td>
                        </tr>

                    </table>

                    <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #f0f0f1;">
                        <?php submit_button('💾 Simpan Pengaturan', 'primary', 'submit', false, ['style' => 'background: #008a20; border-color: #008a20; padding: 8px 24px; font-weight: bold; border-radius: 6px; font-size: 14px; cursor: pointer;']); ?>
                    </div>
                </form>

            </div>
        </div>
        <?php
    }

    /**
     * Shortcode [ipaymu_checkout_box]
     * Mendukung Jalur Pemesanan WhatsApp & iPaymu Otomatis
     */
    public function render_checkout_box_shortcode($atts) {
        $checkout_mode = get_option('ipaymu_checkout_mode', 'whatsapp'); // whatsapp | ipaymu
        $cs_phone      = get_option('ipaymu_cs_whatsapp', '085713911142');
        $clean_cs      = preg_replace('/[^0-9]/', '', $cs_phone);
        if (substr($clean_cs, 0, 1) === '0') {
            $clean_cs = '62' . substr($clean_cs, 1);
        }

        $default_type  = get_option('ipaymu_product_type', 'physical');
        $args          = shortcode_atts(['type' => $default_type], $atts);
        $type          = in_array(strtolower($args['type']), ['digital', 'online']) ? 'digital' : 'physical';

        $price         = (int) get_option('ipaymu_product_price', 80000);
        $normal_price  = (int) get_option('ipaymu_normal_price', 100000);
        $product_name  = get_option('ipaymu_product_name', '50 TEKNIK MEMBUKA KELAS ANTI NGANTUK');
        $ajax_url      = admin_url('admin-ajax.php');

        ob_start();
        ?>
        <div class="ipaymu-checkout-wrapper" style="max-width: 520px; margin: 0 auto; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; box-sizing: border-box;">
            
            <div style="background: #ffffff; color: #1e293b; border-radius: 24px; padding: 24px 22px; box-shadow: 0 20px 40px -15px rgba(0,0,0,0.12), 0 0 1px 1px rgba(0,0,0,0.05); border: 2px solid #fbbf24;">
                
                <!-- Card Header -->
                <div style="text-align: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 18px;">
                    <span style="display: inline-block; background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; padding: 3px 12px; border-radius: 20px; margin-bottom: 6px;">
                        <?php echo ($type === 'physical') ? '📦 FORMULIR PEMESANAN BUKU FISIK' : '⚡ FORMULIR PEMESANAN INSTAN'; ?>
                    </span>
                    
                    <div style="display: flex; align-items: baseline; justify-content: center; gap: 8px; margin-top: 4px;">
                        <span style="font-size: 13px; color: #94a3b8; text-decoration: line-through;">Rp <?php echo number_format($normal_price, 0, ',', '.'); ?></span>
                        <span style="font-size: 28px; font-weight: 900; color: #0f172a; letter-spacing: -0.5px;">Rp <?php echo number_format($price, 0, ',', '.'); ?></span>
                        <span style="font-size: 11px; font-weight: 600; color: #64748b;"><?php echo ($type === 'physical') ? '/ buku fisik' : '/ akses instan'; ?></span>
                    </div>
                    <p style="font-size: 11px; color: #16a34a; font-weight: 700; margin: 4px 0 0;">🔥 Hemat Rp <?php echo number_format($normal_price - $price, 0, ',', '.'); ?> khusus pemesanan hari ini</p>
                </div>

                <form id="ipaymu-order-form-<?php echo esc_attr($type); ?>" onsubmit="submitUnifiedOrder(event, '<?php echo esc_attr($type); ?>', '<?php echo esc_attr($checkout_mode); ?>', '<?php echo esc_attr($clean_cs); ?>', <?php echo $price; ?>)" style="margin: 0;">
                    
                    <!-- SECTION 1: DATA PEMESAN -->
                    <div style="margin-bottom: 16px;">
                        <div style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <span style="background: #ff7a00; color: #fff; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px;">1</span>
                            <span>Data Pemesan & Kontak</span>
                        </div>

                        <div style="margin-bottom: 10px; text-align: left;">
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Nama Lengkap Penerima <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="cust_name_<?php echo esc_attr($type); ?>" placeholder="Contoh: Budi Santoso, S.Pd." required style="width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; text-align: left;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">No. WhatsApp <span style="color:#ef4444;">*</span></label>
                                <input type="tel" id="cust_phone_<?php echo esc_attr($type); ?>" placeholder="08xxxxxxxxxx" required style="width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Email Aktif</label>
                                <input type="email" id="cust_email_<?php echo esc_attr($type); ?>" placeholder="email@gmail.com" style="width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                        </div>
                    </div>

                    <?php if ($type === 'physical') : ?>
                    <!-- SECTION 2: ALAMAT PENGIRIMAN FISIK -->
                    <div style="margin-bottom: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
                        <div style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <span style="background: #ff7a00; color: #fff; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px;">2</span>
                            <span>Alamat Pengiriman Paket</span>
                        </div>

                        <div style="margin-bottom: 10px; text-align: left;">
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Alamat Rumah Lengkap (Jalan, No, RT/RW, Kelurahan) <span style="color:#ef4444;">*</span></label>
                            <textarea id="ship_address_<?php echo esc_attr($type); ?>" rows="2" placeholder="Contoh: Jl. Merdeka No. 45, RT 02/RW 04, Kel. Sukamaju" required style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none; resize: vertical;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; text-align: left;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Kecamatan <span style="color:#ef4444;">*</span></label>
                                <input type="text" id="ship_subdistrict_<?php echo esc_attr($type); ?>" placeholder="Contoh: Cilodong" required style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Kota / Kabupaten <span style="color:#ef4444;">*</span></label>
                                <input type="text" id="ship_city_<?php echo esc_attr($type); ?>" placeholder="Contoh: Depok, Jabar" required style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; text-align: left;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Kode Pos</label>
                                <input type="text" id="ship_postal_<?php echo esc_attr($type); ?>" placeholder="16413" style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Catatan Kurir (Opsional)</label>
                                <input type="text" id="ship_notes_<?php echo esc_attr($type); ?>" placeholder="Pagar hitam / titip satpam" style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13px; background: #f8fafc; box-sizing: border-box; outline: none;">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Submit Button -->
                    <button type="submit" id="btn_submit_<?php echo esc_attr($type); ?>" style="width: 100%; background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; font-weight: 800; font-size: 14px; padding: 14px; border-radius: 14px; border: none; cursor: pointer; box-shadow: 0 10px 22px -5px rgba(16,185,129,0.4); transition: transform 0.2s, opacity 0.2s;">
                        <?php if ($checkout_mode === 'whatsapp') : ?>
                            📲 PESAN SEKARANG VIA WHATSAPP (RP <?php echo number_format($price, 0, ',', '.'); ?>)
                        <?php else : ?>
                            💳 BAYAR SEKARANG VIA IPAYMU (RP <?php echo number_format($price, 0, ',', '.'); ?>)
                        <?php endif; ?>
                    </button>
                    
                    <div style="display: flex; align-items: center; justify-content: center; gap: 5px; margin-top: 10px; font-size: 10px; color: #94a3b8;">
                        <span>🔒 Pesanan Resmi Kang Deden Gurame</span>
                        <span>•</span>
                        <span>CS Aktif 24 Jam</span>
                    </div>
                </form>

                <!-- CS Support -->
                <div style="text-align: center; margin-top: 14px; padding-top: 12px; border-top: 1px solid #f1f5f9;">
                    <p style="font-size: 11px; color: #64748b; margin: 0 0 4px;">Butuh bantuan pesanan atau tanya jawab?</p>
                    <a href="https://wa.me/<?php echo esc_attr($clean_cs); ?>?text=Halo%20CS%20Kang%20Deden%20Gurame,%20saya%20butuh%20bantuan%20pemesanan%2050%20Teknik%20Membuka%20Kelas%20Anti%20Ngantuk." 
                       target="_blank" 
                       style="display: inline-block; font-size: 11px; font-weight: 700; color: #059669; text-decoration: none; background: #ecfdf5; padding: 5px 12px; border-radius: 8px; border: 1px solid #a7f3d0;">
                        📲 Hubungi CS WhatsApp: <?php echo esc_html($cs_phone); ?>
                    </a>
                </div>

            </div>
        </div>

        <script>
        function submitUnifiedOrder(e, prodType, mode, csPhone, price) {
            e.preventDefault();
            var btn = document.getElementById('btn_submit_' + prodType);
            var name = document.getElementById('cust_name_' + prodType).value.trim();
            var phone = document.getElementById('cust_phone_' + prodType).value.trim();
            var email = document.getElementById('cust_email_' + prodType).value.trim() || '-';

            var address = (prodType === 'physical') ? document.getElementById('ship_address_' + prodType).value.trim() : '';
            var subdistrict = (prodType === 'physical') ? document.getElementById('ship_subdistrict_' + prodType).value.trim() : '';
            var city = (prodType === 'physical') ? document.getElementById('ship_city_' + prodType).value.trim() : '';
            var postal = (prodType === 'physical' && document.getElementById('ship_postal_' + prodType)) ? document.getElementById('ship_postal_' + prodType).value.trim() : '';
            var notes = (prodType === 'physical' && document.getElementById('ship_notes_' + prodType)) ? document.getElementById('ship_notes_' + prodType).value.trim() : '';

            if(!name || !phone) {
                alert('Mohon lengkapi Nama Lengkap dan No. WhatsApp.');
                return;
            }

            if(prodType === 'physical' && (!address || !subdistrict || !city)) {
                alert('Mohon lengkapi Alamat Lengkap, Kecamatan, dan Kota untuk pengiriman paket fisik.');
                return;
            }

            btn.innerHTML = '⏳ Menyimpan Pesanan & Menghubungkan ke WA...';
            btn.disabled = true;

            var formData = new URLSearchParams();
            formData.append('action', 'ipaymu_submit_order');
            formData.append('product_type', prodType);
            formData.append('name', name);
            formData.append('phone', phone);
            formData.append('email', email);
            formData.append('address', address);
            formData.append('subdistrict', subdistrict);
            formData.append('city', city);
            formData.append('postal', postal);
            formData.append('notes', notes);
            formData.append('checkout_mode', mode);

            fetch('<?php echo esc_url($ajax_url); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(function(res){ return res.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.innerHTML = (mode === 'whatsapp') ? '📲 PESAN SEKARANG VIA WHATSAPP (RP ' + price.toLocaleString('id-ID') + ')' : '💳 BAYAR SEKARANG VIA IPAYMU';
                
                if(mode === 'whatsapp') {
                    var refCode = (data && data.data && data.data.reference_id) ? data.data.reference_id : '';
                    var refText = refCode ? ("%0A🔖 *No. Pesanan:* " + refCode) : "";

                    var waText = "Halo Kang Deden Gurame / CS,%0A%0ASaya ingin memesan buku *50 TEKNIK MEMBUKA KELAS ANTI NGANTUK* dengan *Harga Promo Hari Ini Rp " + price.toLocaleString('id-ID') + "* (Hemat Rp 20.000):%0A" +
                                 refText + "%0A" +
                                 "👤 *Nama Penerima:* " + encodeURIComponent(name) + "%0A" +
                                 "📱 *No. WhatsApp:* " + encodeURIComponent(phone) + "%0A" +
                                 "📧 *Email:* " + encodeURIComponent(email) + "%0A" +
                                 (prodType === 'physical' ? ("🏠 *Alamat Lengkap:* " + encodeURIComponent(address) + "%0A📍 *Kecamatan:* " + encodeURIComponent(subdistrict) + "%0A🏙️ *Kota/Kab:* " + encodeURIComponent(city) + "%0A") : "") +
                                 "💰 *Total Tagihan:* Rp " + price.toLocaleString('id-ID') + "%0A%0A" +
                                 "Mohon info nomor rekening / pembayaran dan konfirmasi pengirimannya ya. Terima kasih!";

                    window.open('https://wa.me/' + csPhone + '?text=' + waText, '_blank');
                } else {
                    if(data.success && data.data.payment_url) {
                        window.location.href = data.data.payment_url;
                    } else {
                        alert(data.data.message || 'Terjadi kendala saat memproses iPaymu.');
                    }
                }
            })
            .catch(function(err){
                btn.disabled = false;
                btn.innerHTML = (mode === 'whatsapp') ? '📲 PESAN SEKARANG VIA WHATSAPP (RP ' + price.toLocaleString('id-ID') + ')' : '💳 BAYAR SEKARANG VIA IPAYMU';
                console.error(err);
                
                // Fallback direct open WA even if network glitch
                var waText = "Halo Kang Deden Gurame / CS,%0A%0ASaya ingin memesan buku *50 TEKNIK MEMBUKA KELAS ANTI NGANTUK* dengan *Harga Promo Hari Ini Rp " + price.toLocaleString('id-ID') + "*:%0A%0A" +
                             "👤 *Nama:* " + encodeURIComponent(name) + "%0A📱 *No. WA:* " + encodeURIComponent(phone) + "%0A🏠 *Alamat:* " + encodeURIComponent(address + ', ' + city);
                window.open('https://wa.me/' + csPhone + '?text=' + waText, '_blank');
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode [landingpage_50teknik]
     */
    public function render_full_landingpage_shortcode() {
        $html_file = plugin_dir_path(__FILE__) . 'landing-page-template.php';
        if (file_exists($html_file)) {
            ob_start();
            include $html_file;
            return ob_get_clean();
        }
        return do_shortcode('[ipaymu_checkout_box]');
    }

    /**
     * Endpoint REST API untuk Order Submission
     */
    public function handle_rest_order_submission($request) {
        $_POST = $request->get_params();
        $this->handle_order_submission();
    }

    /**
     * Memproses submit order & MENYIMPAN KE DATABASE
     */
    public function handle_order_submission() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ipaymu_orders';

        $checkout_mode = isset($_POST['checkout_mode']) ? sanitize_text_field($_POST['checkout_mode']) : get_option('ipaymu_checkout_mode', 'whatsapp');
        $product_type  = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : get_option('ipaymu_product_type', 'physical');
        $name          = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $phone         = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $email         = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $address       = isset($_POST['address']) ? sanitize_textarea_field($_POST['address']) : '';
        $subdistrict   = isset($_POST['subdistrict']) ? sanitize_text_field($_POST['subdistrict']) : '';
        $city          = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
        $postal        = isset($_POST['postal']) ? sanitize_text_field($_POST['postal']) : '';
        $notes         = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (empty($name) || empty($phone)) {
            wp_send_json_error(['message' => 'Mohon lengkapi Nama dan WhatsApp Anda.']);
        }

        $price        = (int) get_option('ipaymu_product_price', 80000);
        $product_name = get_option('ipaymu_product_name', '50 TEKNIK MEMBUKA KELAS ANTI NGANTUK');
        $reference_id = 'ORDER-' . time() . rand(100, 999);

        // Pastikan tabel dan kolom siap
        $this->create_orders_database_table();

        // Jika mode WhatsApp: simpan order ke database lalu return success
        if ($checkout_mode === 'whatsapp') {
            $insert_result = $wpdb->insert(
                $table_name,
                [
                    'reference_id'         => $reference_id,
                    'product_type'         => $product_type,
                    'product_name'         => $product_name,
                    'customer_name'        => $name,
                    'customer_phone'       => $phone,
                    'customer_email'       => $email,
                    'shipping_address'     => $address,
                    'shipping_subdistrict' => $subdistrict,
                    'shipping_city'        => $city,
                    'shipping_postal_code' => $postal,
                    'shipping_notes'       => $notes,
                    'shipping_status'      => 'BELUM_DIKIRIM',
                    'amount'               => $price,
                    'payment_method'       => 'whatsapp',
                    'payment_channel'      => 'WhatsApp CS',
                    'status'               => 'PENDING',
                    'environment'          => 'whatsapp',
                    'created_at'           => current_time('mysql'),
                    'updated_at'           => current_time('mysql'),
                ]
            );

            if ($insert_result === false) {
                error_log("iPaymu Order Insert Error: " . $wpdb->last_error);
                wp_send_json_error(['message' => 'Gagal simpan database: ' . $wpdb->last_error]);
            }

            wp_send_json_success([
                'mode'         => 'whatsapp',
                'order_id'     => $wpdb->insert_id,
                'reference_id' => $reference_id
            ]);
            wp_die();
        }

        // Jika mode iPaymu: Proses ke API Gateway iPaymu
        $mode       = get_option('ipaymu_mode', 'sandbox');
        $va         = get_option('ipaymu_va', '0000002410214040');
        $apiKey     = get_option('ipaymu_api_key', 'SANDBOX78B457D7-E682-4217-A630-B14704B14BFA');
        $return_url = get_option('ipaymu_return_url', home_url('/terima-kasih/'));
        $cancel_url = get_option('ipaymu_cancel_url', home_url('/'));
        $notify_url = rest_url('ipaymu/v1/notify');

        $baseUrl  = ($mode === 'live') ? 'https://my.ipaymu.com/api/v2' : 'https://sandbox.ipaymu.com/api/v2';
        $endpoint = $baseUrl . '/payment';

        $body = [
            'name'        => $name,
            'phone'       => $phone,
            'email'       => !empty($email) ? $email : 'pembeli@gmail.com',
            'amount'      => $price,
            'notifyUrl'   => $notify_url,
            'returnUrl'   => $return_url,
            'cancelUrl'   => $cancel_url,
            'referenceId' => $reference_id,
            'product'     => [$product_name],
            'qty'         => [1],
            'price'       => [$price],
            'description' => 'Pemesanan Buku Fisik ' . $product_name
        ];

        $jsonBody  = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', "POST:" . $va . ":" . strtolower(hash('sha256', $jsonBody)) . ":" . $apiKey, $apiKey);
        $timestamp = date('YmdHis');

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'va'           => $va,
                'signature'    => $signature,
                'timestamp'    => $timestamp
            ],
            'body'    => $jsonBody,
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Gagal menghubungi iPaymu: ' . $response->get_error_message()]);
        }

        $resBody = wp_remote_retrieve_body($response);
        $result  = json_decode($resBody, true);

        if (isset($result['Status']) && $result['Status'] == 200 && isset($result['Data']['Url'])) {
            $payment_url = $result['Data']['Url'];
            $session_id  = $result['Data']['SessionID'] ?? '';

            $wpdb->insert(
                $table_name,
                [
                    'reference_id'         => $reference_id,
                    'product_type'         => $product_type,
                    'product_name'         => $product_name,
                    'customer_name'        => $name,
                    'customer_phone'       => $phone,
                    'customer_email'       => $email,
                    'shipping_address'     => $address,
                    'shipping_subdistrict' => $subdistrict,
                    'shipping_city'        => $city,
                    'shipping_postal_code' => $postal,
                    'shipping_notes'       => $notes,
                    'shipping_status'      => 'BELUM_DIKIRIM',
                    'amount'               => $price,
                    'payment_method'       => 'ipaymu',
                    'payment_url'          => $payment_url,
                    'session_id'           => $session_id,
                    'status'               => 'PENDING',
                    'environment'          => $mode,
                    'created_at'           => current_time('mysql'),
                    'updated_at'           => current_time('mysql'),
                ]
            );

            wp_send_json_success([
                'payment_url' => $payment_url,
                'session_id'  => $session_id,
                'order_id'    => $wpdb->insert_id,
                'mode'        => $mode
            ]);
        } else {
            $errorMsg = $result['Message'] ?? 'Terjadi kesalahan pada respon iPaymu.';
            wp_send_json_error(['message' => $errorMsg, 'debug' => $result]);
        }

        wp_die();
    }

    /**
     * Webhook Handler: Update Status Pembayaran iPaymu
     */
    public function handle_webhook_notification($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ipaymu_orders';

        $params = $request->get_params();
        $status = $params['status'] ?? '';
        $trxId  = $params['trx_id'] ?? '';
        $refId  = $params['reference_id'] ?? '';
        $via    = $params['via'] ?? '';
        $channel= $params['channel'] ?? '';

        if (!empty($refId)) {
            $payment_channel = trim($via . ' ' . $channel);
            $new_status = ($status === 'berhasil' || $status === 'PAID') ? 'LUNAS' : $status;

            $wpdb->update(
                $table_name,
                [
                    'status'          => strtoupper($new_status),
                    'trx_id'          => $trxId,
                    'payment_channel' => !empty($payment_channel) ? $payment_channel : 'iPaymu',
                    'updated_at'      => current_time('mysql')
                ],
                ['reference_id' => $refId]
            );

            error_log("iPaymu Webhook Updated: Ref $refId -> Status: $new_status, TRX: $trxId");
            return new WP_REST_Response(['status' => 'OK'], 200);
        }

        return new WP_REST_Response(['status' => 'Received'], 200);
    }
}

// Inisialisasi Gateway
new IPaymu_Custom_Gateway();
