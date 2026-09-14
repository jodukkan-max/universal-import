<?php
/**
 * Admin page — displays the plugin's auth key for the Chrome extension
 * and the ERP sync endpoint in separate tabs.
 *
 * The scraper auth key is auto-generated on plugin activation and stored in
 * wp_options['rsi_auth_key'].  The user copies it into the extension
 * to authorize CSV imports without WordPress credentials.
 */

class Rsi_Admin_Page {

    /**
     * Option key for the scraper auth key.
     */
    private const OPTION_KEY  = 'rsi_auth_key';

    /**
     * Register the admin menu page.
     */
    public function register(): void {
        add_menu_page(
            __('Universal Import', 'rey-swatches-import'),
            __('Universal Import', 'rey-swatches-import'),
            'manage_options',
            'rey-swatches-import',
            [$this, 'render'],
            'dashicons-migrate',
            56
        );
    }

    /**
     * Render the admin page with two tabs.
     */
    public function render(): void {
        $key      = get_option(self::OPTION_KEY, '');
        $imgbb_key = get_option('rsi_imgbb_key', '');

        // Handle scraper auth key regenerate.
        if (isset($_POST['rsi_regenerate']) && check_admin_referer('rsi_regenerate_key')) {
            $key = Rsi_Key_Generator::generate();
            update_option(self::OPTION_KEY, $key);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Auth key regenerated. Update it in the Chrome extension.', 'rey-swatches-import')
                . '</p></div>';
        }

        // Handle imgbb key save.
        if (isset($_POST['rsi_imgbb_key']) && check_admin_referer('rsi_imgbb_save')) {
            $imgbb_key = sanitize_text_field(wp_unslash($_POST['rsi_imgbb_key']));
            update_option('rsi_imgbb_key', $imgbb_key);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('ImgBB API key saved.', 'rey-swatches-import')
                . '</p></div>';
        }

        // Handle clear ERP log.
        if (isset($_POST['rsi_erp_clear_log']) && check_admin_referer('rsi_erp_clear_log')) {
            Rsi_Erp_Endpoint::clear_log();
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('ERP sync log cleared.', 'rey-swatches-import')
                . '</p></div>';
        }

        $erp_key     = Rsi_Erp_Endpoint::get_key();
        $erp_url     = home_url('/wp-json/scraper/v1/erp-stock');

        // Determine active tab.
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'scraper';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Universal Import', 'rey-swatches-import'); ?></h1>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=rey-swatches-import&tab=scraper')); ?>"
                   class="nav-tab <?php echo $active_tab === 'scraper' ? 'nav-tab-active' : ''; ?>">
                   <?php esc_html_e('Scraper', 'rey-swatches-import'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=rey-swatches-import&tab=erp')); ?>"
                   class="nav-tab <?php echo $active_tab === 'erp' ? 'nav-tab-active' : ''; ?>">
                   <?php esc_html_e('ERP Sync', 'rey-swatches-import'); ?>
                </a>
            </nav>

            <?php if ($active_tab === 'scraper'): ?>
            <!-- ======================== SCRAPER TAB ======================== -->
            <div class="card" style="max-width:560px; margin-top:0;">
                <h2><?php esc_html_e('Auth Key', 'rey-swatches-import'); ?></h2>
                <p><?php esc_html_e('Copy this key into the Chrome extension to authorize product imports.', 'rey-swatches-import'); ?></p>
                <div style="background:#f0f0f1; border:1px solid #c3c4c7; border-radius:4px; padding:16px; margin:12px 0; text-align:center;">
                    <code style="font-size:14px; font-weight:700; letter-spacing:1px; user-select:all; word-break:break-all;"><?php echo esc_html($key ?: __('Not generated', 'rey-swatches-import')); ?></code>
                </div>
                <form method="post" style="margin-top:8px;">
                    <?php wp_nonce_field('rsi_regenerate_key'); ?>
                    <button type="submit" name="rsi_regenerate" class="button button-secondary"
                            onclick="return confirm('<?php esc_attr_e('Regenerating the key will break any existing extension connections. Continue?', 'rey-swatches-import'); ?>')">
                        <?php esc_html_e('Regenerate Key', 'rey-swatches-import'); ?>
                    </button>
                </form>
            </div>

            <div class="card" style="max-width:560px; margin-top:20px;">
                <h2><?php esc_html_e('ImgBB API Key', 'rey-swatches-import'); ?></h2>
                <p><?php esc_html_e('Enter your imgbb.com API key to upload product images to imgbb, bypassing WordPress image processing. Get a key at api.imgbb.com.', 'rey-swatches-import'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('rsi_imgbb_save'); ?>
                    <input type="text" name="rsi_imgbb_key" value="<?php echo esc_attr($imgbb_key); ?>"
                           placeholder="<?php esc_attr_e('Paste your imgbb API key', 'rey-swatches-import'); ?>"
                           style="width:100%; padding:8px; font-family:monospace; margin-bottom:8px;" />
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save', 'rey-swatches-import'); ?>
                    </button>
                    <?php if (!empty($imgbb_key)): ?>
                        <span style="color:green; margin-left:10px;">&#10003; <?php esc_html_e('Configured', 'rey-swatches-import'); ?></span>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card" style="max-width:560px; margin-top:20px;">
                <h2><?php esc_html_e('Extension Setup', 'rey-swatches-import'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Copy the auth key above.', 'rey-swatches-import'); ?></li>
                    <li><?php esc_html_e('Open the Cosmetics Scraper Chrome extension.', 'rey-swatches-import'); ?></li>
                    <li><?php esc_html_e('Go to the Stores tab.', 'rey-swatches-import'); ?></li>
                    <li><?php printf(
                        /* translators: %s: site URL */
                        esc_html__('Enter %s as the store URL and paste the auth key.', 'rey-swatches-import'),
                        '<code>' . esc_html(home_url()) . '</code>'
                    ); ?></li>
                    <li><?php esc_html_e('Click Test to verify the connection, then scrape and import.', 'rey-swatches-import'); ?></li>
                </ol>
            </div>

            <?php else: ?>
            <!-- ======================== ERP TAB ======================== -->
            <div class="card" style="max-width:800px; margin-top:0;">
                <h2><?php esc_html_e('ERP Sync', 'rey-swatches-import'); ?></h2>
                <p><?php esc_html_e('Send SKU, stock, and price updates from your ERP/POS to this endpoint. Requests must include the ERP Key header.', 'rey-swatches-import'); ?></p>

                <h3><?php esc_html_e('ERP Key', 'rey-swatches-import'); ?></h3>
                <p><?php esc_html_e('Copy this key into your ERP system. It must be sent as the X-ERP-Key header with every request.', 'rey-swatches-import'); ?></p>
                <div style="background:#f0f0f1; border:1px solid #c3c4c7; border-radius:4px; padding:16px; margin:12px 0;">
                    <code style="font-size:18px; font-weight:700; font-family:monospace; user-select:all;"><?php echo esc_html($erp_key); ?></code>
                </div>

                <h3><?php esc_html_e('Endpoint URL', 'rey-swatches-import'); ?></h3>
                <div style="background:#f0f0f1; border:1px solid #c3c4c7; border-radius:4px; padding:12px; margin:8px 0;">
                    <code style="font-family:monospace; word-break:break-all; user-select:all;"><?php echo esc_html($erp_url); ?></code>
                </div>

                <h3><?php esc_html_e('Code Examples', 'rey-swatches-import'); ?></h3>

                <h4>cURL — Update Stock (after sale)</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">curl -X POST <?php echo esc_html($erp_url); ?> \
  -H "Content-Type: application/json" \
  -H "X-ERP-Key: <?php echo esc_html($erp_key); ?>" \
  -d '{"items":[{"sku":"6473829103","stock":12},{"sku":"9485736210","stock":5}]}'</pre>

                <h4>cURL — Update Price (when price changes)</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">curl -X POST <?php echo esc_html($erp_url); ?> \
  -H "Content-Type: application/json" \
  -H "X-ERP-Key: <?php echo esc_html($erp_key); ?>" \
  -d '{"items":[{"sku":"6473829103","price":25.50}]}'</pre>

                <h4>JavaScript / fetch — Update Stock</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">fetch("<?php echo esc_html($erp_url); ?>", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        "X-ERP-Key": "<?php echo esc_html($erp_key); ?>"
    },
    body: JSON.stringify({
        items: [
            { sku: "6473829103", stock: 12 },
            { sku: "9485736210", stock: 5 }
        ]
    })
});</pre>

                <h4>JavaScript / fetch — Update Price</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">fetch("<?php echo esc_html($erp_url); ?>", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        "X-ERP-Key": "<?php echo esc_html($erp_key); ?>"
    },
    body: JSON.stringify({
        items: [
            { sku: "6473829103", price: 25.50 }
        ]
    })
});</pre>

                <h4>PHP / cURL — Update Stock</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">$payload = json_encode([
    "items" => [
        ["sku" => "6473829103", "stock" => 12],
        ["sku" => "9485736210", "stock" => 5],
    ]
]);

$ch = curl_init("<?php echo esc_html($erp_url); ?>");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-ERP-Key: <?php echo esc_html($erp_key); ?>"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);</pre>

                <h4>PHP / cURL — Update Price</h4>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; overflow-x:auto; user-select:all; line-height:1.6;">$payload = json_encode([
    "items" => [
        ["sku" => "6473829103", "price" => 25.50],
    ]
]);

$ch = curl_init("<?php echo esc_html($erp_url); ?>");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-ERP-Key: <?php echo esc_html($erp_key); ?>"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);</pre>

                <?php
                $log = Rsi_Erp_Endpoint::get_log();
                ?>
                <h3><?php esc_html_e('Recent Requests', 'rey-swatches-import'); ?>
                    <span style="font-weight:400; font-size:13px; color:#666;">
                        <?php echo esc_html(sprintf(
                            /* translators: %d: number of entries */
                            _n('(%d entry)', '(%d entries)', count($log), 'rey-swatches-import'),
                            count($log)
                        )); ?>
                    </span>
                </h3>

                <?php if (empty($log)): ?>
                    <p style="color:#888;"><?php esc_html_e('No requests yet. The log will appear here after the ERP sends its first sync request.', 'rey-swatches-import'); ?></p>
                <?php else: ?>
                    <table class="widefat striped" style="margin-bottom:12px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Time', 'rey-swatches-import'); ?></th>
                                <th><?php esc_html_e('SKU', 'rey-swatches-import'); ?></th>
                                <th><?php esc_html_e('Stock', 'rey-swatches-import'); ?></th>
                                <th><?php esc_html_e('Price', 'rey-swatches-import'); ?></th>
                                <th><?php esc_html_e('Product ID', 'rey-swatches-import'); ?></th>
                                <th><?php esc_html_e('Result', 'rey-swatches-import'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                    <td><code><?php echo esc_html($entry['sku'] ?? ''); ?></code></td>
                                    <td><?php echo esc_html($entry['stock'] ?? ''); ?></td>
                                    <td><?php echo ($entry['price'] ?? null) !== null ? esc_html($entry['price']) : '—'; ?></td>
                                    <td><?php echo ($entry['product_id'] ?? 0) > 0 ? (int) $entry['product_id'] : '—'; ?></td>
                                    <td>
                                        <?php
                                        $result = $entry['result'] ?? '';
                                        if (strpos($result, 'updated') === 0) {
                                            echo '<span style="color:#008a20;">' . esc_html($result) . '</span>';
                                        } elseif ($result === 'not found') {
                                            echo '<span style="color:#d63638;">' . esc_html__('not found', 'rey-swatches-import') . '</span>';
                                        } else {
                                            echo '<span style="color:#bd8600;">' . esc_html($result) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post">
                        <?php wp_nonce_field('rsi_erp_clear_log'); ?>
                        <button type="submit" name="rsi_erp_clear_log" class="button button-secondary"
                                onclick="return confirm('<?php esc_attr_e('Clear all ERP sync log entries?', 'rey-swatches-import'); ?>')">
                            <?php esc_html_e('Clear Log', 'rey-swatches-import'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
