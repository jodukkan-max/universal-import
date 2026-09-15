<?php
/**
 * Admin page — displays the plugin's auth key for the Chrome extension.
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
     * Render the admin page.
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Universal Import', 'rey-swatches-import'); ?></h1>

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
        </div>
        <?php
    }
}
