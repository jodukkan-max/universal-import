<?php
/**
 * ERP Sync Endpoint — receives SKU + stock / price payloads from the ERP/POS
 * system and updates WooCommerce product stock quantities and prices.
 *
 * Route:   POST /wp-json/scraper/v1/erp-stock
 * Auth:    X-ERP-Key header (shared secret key generated on plugin activation).
 *
 * Request body:
 *   { "items": [ { "sku": "123", "stock": 10, "price": 25.50 }, ... ] }
 *
 *   "stock" and "price" are both optional — at least one must be present.
 *
 * Response (200):
 *   {
 *     "ok": true,
 *     "updated": [ { "sku": "...", "product_id": 123, "new_stock": 10, "new_price": "25.50" }, ... ],
 *     "failed":  [ { "sku": "...", "reason": "SKU not found" }, ... ]
 *   }
 */

class Rsi_Erp_Endpoint {

    /**
     * Option key and max entries for the request log.
     */
    private const LOG_OPTION = 'rsi_erp_stock_log';
    private const LOG_MAX    = 20;

    /**
     * Register the ERP sync REST route.
     */
    public function register(): void {
        register_rest_route(
            RSI_REST_NAMESPACE,
            '/erp-stock',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_sync'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'items' => [
                        'required' => true,
                        'type'     => 'array',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'sku'   => ['type' => 'string', 'required' => true],
                                'stock' => ['type' => 'integer', 'required' => false],
                                'price' => ['type' => 'number', 'required' => false],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission callback — validates the X-ERP-Key header against the
     * hardcoded shared secret key.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_permission(\WP_REST_Request $request) {
        $provided = $request->get_header('X-ERP-Key');

        if (empty($provided)) {
            return new \WP_Error(
                'rest_forbidden',
                __('Missing ERP key.', 'rey-swatches-import'),
                ['status' => 403]
            );
        }

        if (!hash_equals(self::get_key(), $provided)) {
            return new \WP_Error(
                'rest_forbidden',
                __('Invalid ERP key.', 'rey-swatches-import'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle the sync request — updates stock, price, or both.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_sync(\WP_REST_Request $request) {
        $items = $request->get_param('items');

        if (empty($items) || !is_array($items)) {
            return new \WP_Error(
                'missing_items',
                __('The "items" field is required and must be a non-empty array.', 'rey-swatches-import'),
                ['status' => 400]
            );
        }

        $updated = [];
        $failed  = [];

        foreach ($items as $item) {
            $sku         = trim($item['sku'] ?? '');
            $has_stock   = array_key_exists('stock', $item) && $item['stock'] !== null;
            $stock       = $has_stock ? (int) $item['stock'] : null;
            $has_price   = array_key_exists('price', $item) && $item['price'] !== null;
            $price       = $has_price ? (float) $item['price'] : null;

            if ($sku === '') {
                $failed[] = [
                    'sku'    => '(empty)',
                    'reason' => 'Missing sku field.',
                ];
                self::log_entry('(empty)', $stock ?? 0, 0, $price, 'missing fields');
                continue;
            }

            if (!$has_stock && !$has_price) {
                $failed[] = [
                    'sku'    => $sku,
                    'reason' => 'Neither stock nor price provided.',
                ];
                self::log_entry($sku, 0, 0, null, 'missing fields');
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);

            if ($product_id <= 0) {
                $failed[] = [
                    'sku'    => $sku,
                    'reason' => 'SKU not found.',
                ];
                self::log_entry($sku, $stock ?? 0, 0, $price, 'not found');
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                $failed[] = [
                    'sku'    => $sku,
                    'reason' => 'Product not found.',
                ];
                self::log_entry($sku, $stock ?? 0, 0, $price, 'not found');
                continue;
            }

            $entry = [
                'sku'        => $sku,
                'product_id' => $product_id,
            ];

            // Update stock if provided.
            if ($has_stock) {
                wc_update_product_stock($product_id, $stock, 'set');
                $entry['new_stock'] = $stock;
            }

            // Update price if provided.
            if ($has_price) {
                $product->set_regular_price(wc_format_decimal($price));
                $product->save();
                $entry['new_price'] = wc_format_decimal($price);
            }

            $updated[] = $entry;

            $result_label = ($has_stock && $has_price) ? 'updated both' : ($has_stock ? 'updated stock' : 'updated price');
            self::log_entry($sku, $stock ?? 0, $product_id, $price, $result_label);
        }

        return new \WP_REST_Response([
            'ok'      => true,
            'updated' => $updated,
            'failed'  => $failed,
        ], 200);
    }

    /**
     * Get the ERP auth key.
     *
     * The key is generated per-site and stored in wp_options['rsi_erp_key']
     * (generated on plugin activation, or lazily on first use for existing
     * installs that predate this behavior).
     *
     * @return string
     */
    public static function get_key(): string {
        $key = get_option('rsi_erp_key', '');
        if ($key === '') {
            $key = Rsi_Key_Generator::generate();
            update_option('rsi_erp_key', $key);
        }
        return $key;
    }

    /**
     * Record a sync entry in the request log.
     *
     * @param string      $sku        Product SKU.
     * @param int         $stock      Stock quantity (0 if not provided).
     * @param int         $product_id Resolved product ID (0 if not found).
     * @param float|null  $price      Price value (null if not provided).
     * @param string      $result     Result label.
     */
    public static function log_entry(string $sku, int $stock, int $product_id, ?float $price, string $result): void {
        $log   = self::get_log();
        $entry = [
            'time'       => current_time('mysql'),
            'sku'        => $sku,
            'stock'      => $stock,
            'price'      => $price !== null ? wc_format_decimal($price) : null,
            'product_id' => $product_id,
            'result'     => $result,
        ];
        array_unshift($log, $entry);
        $log = array_slice($log, 0, self::LOG_MAX);
        update_option(self::LOG_OPTION, $log, false);
    }

    /**
     * Get the full request log (last 20 entries).
     *
     * @return array[]
     */
    public static function get_log(): array {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    /**
     * Clear the request log.
     */
    public static function clear_log(): void {
        delete_option(self::LOG_OPTION);
    }
}
