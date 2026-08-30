<?php
/**
 * Product Creator — creates WooCommerce products from parsed CSV data
 * via the internal REST API (wc/v3/products).
 *
 * Uses rest_do_request() so the standard WooCommerce API handles
 * validation, images, categories, and variation logic natively.
 * No admin includes or user impersonation needed.
 */

class Rsi_Product_Creator {

    /**
     * @var Rsi_Rey_Swatches
     */
    private Rsi_Rey_Swatches $rey_swatches;

    /**
     * Accumulated import report messages.
     *
     * @var string[]
     */
    private array $report = [];

    public function __construct(Rsi_Rey_Swatches $rey_swatches) {
        $this->rey_swatches     = $rey_swatches;
    }

    /**
     * Import all parsed products.
     *
     * @param array[] $products  From Rsi_Csv_Parser::parse().
     * @return array             Report with created/modified counts and messages.
     */
    public function import_all(array $products): array {
        $this->report            = [];
        $created_variable        = 0;
        $updated_variable        = 0;
        $created_variations      = 0;
        $skipped_variations      = 0;
        $created_simple          = 0;
        $skipped                 = 0;

        foreach ($products as $product_data) {
            try {
                if ($product_data['is_variable']) {
                    $result = $this->upsert_variable_product($product_data);
                    if ($result['is_new']) {
                        $created_variable++;
                    } else {
                        $updated_variable++;
                    }
                    $created_variations += $result['var_created'];
                    $skipped_variations += $result['var_skipped'];
                } else {
                    $this->create_simple_product($product_data);
                    $created_simple++;
                }
            } catch (\Exception $e) {
                $name = $product_data['parent']['name'] ?? 'Unknown';
                $this->report[] = sprintf(
                    /* translators: 1: product name, 2: error message */
                    __('Skipped "%1$s": %2$s', 'rey-swatches-import'),
                    $name,
                    $e->getMessage()
                );
                $skipped++;
            }
        }

        return [
            'created_variable'   => $created_variable,
            'updated_variable'   => $updated_variable,
            'created_variations' => $created_variations,
            'skipped_variations' => $skipped_variations,
            'created_simple'     => $created_simple,
            'skipped'            => $skipped,
            'messages'           => $this->report,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Variable Products
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a variable product — create if new, update if exists (matched by SKU).
     * For each variation: skip if it already belongs to this parent, otherwise add.
     *
     * @param array $data  { parent, variations, is_variable }
     * @return array       { is_new, var_created, var_skipped }
     */
    private function upsert_variable_product(array $data): array {
        $parent     = $data['parent'];
        $variations = $data['variations'];

        if (empty($variations)) {
            throw new \Exception(__('No variations provided.', 'rey-swatches-import'));
        }

        // ── 1. Pre-process Rey Swatches JSON ──
        $swatch_json = $parent['rey_swatches_json'] ?? '';
        $tax_map     = [];
        if ($swatch_json !== '') {
            $tax_map = $this->rey_swatches->create_from_json($swatch_json);
        }

        // ── 2. Determine attributes (1 or 2) and their options ──
        $attr_specs = $this->build_attribute_specs($parent, $variations, $tax_map);
        if (empty($attr_specs)) {
            throw new \Exception(__('No attributes could be determined for this variable product.', 'rey-swatches-import'));
        }

        // ── 3. Look up existing parent product (SKU first, then name) ──
        $existing_id = $this->find_existing_parent($parent);
        $is_new      = ($existing_id <= 0);

        // ── 4. Build product data ──
        $body = [
            'name'         => $parent['name'],
            'type'         => 'variable',
            'status'       => 'publish',
            'manage_stock' => false,
            'stock_status' => 'instock',
        ];

        if (!empty($parent['sku'])) {
            $body['sku'] = $parent['sku'];
        }
        if (!empty($parent['description'])) {
            $body['description'] = $parent['description'];
        }
        if (!empty($parent['short_description'])) {
            $body['short_description'] = $parent['short_description'];
        }
        if (!empty($parent['images'])) {
            $body['images'] = array_map(fn($url) => ['src' => $url], $parent['images']);
        }

        $body['attributes'] = array_map(fn($spec) => [
            'id'        => $spec['id'],
            'name'      => $spec['name'],
            'visible'   => $spec['visible'],
            'variation' => true,
            'options'   => $spec['options'],
        ], $attr_specs);

        $cats = $parent['categories'] ?? [];
        if (!empty($cats)) {
            $body['categories'] = array_map(fn($c) => ['name' => $c], $cats);
        }
        if (!empty($parent['tags'])) {
            $tag_names = array_map('trim', explode(',', $parent['tags']));
            $body['tags'] = array_map(fn($t) => ['name' => $t], $tag_names);
        }
        if ($swatch_json !== '') {
            $body['meta_data'][] = [
                'key'   => '_rsi_swatch_data',
                'value' => $swatch_json,
            ];
        }

        // ── 5. Create or update parent product ──
        if ($is_new) {
            $resp       = $this->rest_post('/wc/v3/products', $body);
            $product_id = $resp['id'] ?? 0;
        } else {
            $this->rest_put("/wc/v3/products/{$existing_id}", $body);
            $product_id = $existing_id;
        }

        if ($product_id <= 0) {
            throw new \Exception(__('Failed to create / update parent product via REST API.', 'rey-swatches-import'));
        }

        // ── 6. Create or skip variations ──
        $var_created = 0;
        $var_skipped = 0;

        foreach ($variations as $var) {
            $var_sku       = $var['sku'] ?? '';
            $existing_var  = 0;

            // Check if a variation with this SKU already belongs to this parent.
            if (!empty($var_sku)) {
                $existing_var = (int) wc_get_product_id_by_sku($var_sku);
                if ($existing_var > 0 && (int) wp_get_post_parent_id($existing_var) !== (int) $product_id) {
                    $existing_var = 0; // SKU exists but belongs to a different product — treat as new.
                }
            }

            if ($existing_var > 0) {
                $var_skipped++;
                $this->report[] = sprintf(
                    /* translators: %s: variation SKU */
                    __('Variation "%s" already exists — skipped.', 'rey-swatches-import'),
                    $var_sku
                );
                continue;
            }

            try {
                $var_body = [
                    'sku'           => $var_sku,
                    'regular_price' => (string) ($var['regular_price'] ?? '0'),
                    'attributes'    => $this->build_variation_attributes($var, $attr_specs),
                    'manage_stock'  => false,
                    'stock_status'  => 'instock',
                ];

                $sale_price = $var['sale_price'] ?? 0;
                if ((float) $sale_price > 0) {
                    $var_body['sale_price'] = (string) $sale_price;
                }

                $var_images       = $var['images'] ?? [];
                $parent_first_img = $parent['images'][0] ?? '';
                if (!empty($var_images[0]) && $var_images[0] !== $parent_first_img) {
                    $var_body['image'] = ['src' => $var_images[0]];
                }

                $this->rest_post("/wc/v3/products/{$product_id}/variations", $var_body);
                $var_created++;
            } catch (\Exception $e) {
                $this->report[] = sprintf(
                    /* translators: 1: SKU, 2: error */
                    __('Variation "%1$s" skipped: %2$s', 'rey-swatches-import'),
                    $var['sku'] ?? '?',
                    $e->getMessage()
                );
            }
        }

        $action = $is_new ? __('Created', 'rey-swatches-import') : __('Updated', 'rey-swatches-import');
        $this->report[] = sprintf(
            /* translators: 1: Created/Updated, 2: product name, 3: new variations, 4: skipped variations */
            __('%1$s "%2$s" — %3$d new variation(s), %4$d skipped.', 'rey-swatches-import'),
            $action,
            $parent['name'],
            $var_created,
            $var_skipped
        );

        return [
            'is_new'      => $is_new,
            'var_created' => $var_created,
            'var_skipped' => $var_skipped,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Simple Products
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Create a simple (non-variable) product via the REST API.
     *
     * @param array $data  { parent, variations (empty), is_variable }
     */
    private function create_simple_product(array $data): void {
        $parent = $data['parent'];

        $body = [
            'name'         => $parent['name'],
            'type'         => 'simple',
            'status'       => 'publish',
            'manage_stock' => false,
            'stock_status' => 'instock',
        ];

        if (!empty($parent['sku'])) {
            $body['sku'] = $parent['sku'];
        }
        if (!empty($parent['description'])) {
            $body['description'] = $parent['description'];
        }
        if (!empty($parent['short_description'])) {
            $body['short_description'] = $parent['short_description'];
        }
        if (!empty($parent['categories'])) {
            $body['categories'] = array_map(fn($c) => ['name' => $c], $parent['categories']);
        }
        if (!empty($parent['tags'])) {
            $tag_names = array_map('trim', explode(',', $parent['tags']));
            $body['tags'] = array_map(fn($t) => ['name' => $t], $tag_names);
        }
        if (!empty($parent['images'])) {
            $body['images'] = array_map(fn($url) => ['src' => $url], $parent['images']);
        }

        // Prices.
        $regular = $parent['regular_price'] ?? 0;
        $sale    = $parent['sale_price'] ?? 0;
        $body['regular_price'] = (string) $regular;
        if ((float) $sale > 0) {
            $body['sale_price'] = (string) $sale;
        }

        $resp = $this->rest_post('/wc/v3/products', $body);

        $this->report[] = sprintf(
            /* translators: %s: product name */
            __('Created simple product "%s".', 'rey-swatches-import'),
            $parent['name']
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Find an existing variable product to update instead of creating a new one.
     *
     * Scraper variable parents have no SKU (only variations do), so we fall back
     * to matching a variable product by its exact name. This makes re-importing
     * the same scraped product idempotent rather than creating a duplicate.
     *
     * @param array $parent Normalized parent row.
     * @return int          Existing product ID, or 0 if none found.
     */
    private function find_existing_parent(array $parent): int {
        $sku = $parent['sku'] ?? '';
        if (!empty($sku)) {
            $id = (int) wc_get_product_id_by_sku($sku);
            if ($id > 0) {
                return $id;
            }
        }

        $name = trim((string) ($parent['name'] ?? ''));
        if ($name === '') {
            return 0;
        }

        $products = wc_get_products([
            'status' => 'publish',
            'type'   => 'variable',
            'limit'  => 5,
            'search' => $name,
        ]);

        foreach ($products as $product) {
            if (trim($product->get_name()) === $name) {
                return $product->get_id();
            }
        }

        return 0;
    }

    /**
     * Build the attribute specs for a variable product from its parent row and
     * variations. Supports a second attribute (e.g. "Size" when "Color" is the
     * first attribute) so multi-attribute products import correctly.
     *
     * Each spec records which variation field holds its option so variations
     * carry the right value for every attribute.
     *
     * @param array $parent     Normalized parent row.
     * @param array $variations Normalized variation rows.
     * @param array $tax_map    Map of attribute key => taxonomy name (from Rey swatches).
     * @return array[]          List of specs: { name, visible, options, id, taxonomy, value_field }.
     */
    private function build_attribute_specs(array $parent, array $variations, array $tax_map): array {
        $definitions = [
            [
                'name'        => $parent['attribute_name'] ?? '',
                'visible'     => !empty($parent['attribute_visible']),
                'value_field' => 'attribute_value',
            ],
            [
                'name'        => $parent['attribute2_name'] ?? '',
                'visible'     => !empty($parent['attribute2_visible']),
                'value_field' => 'attribute2_value',
            ],
        ];

        $specs = [];
        foreach ($definitions as $def) {
            $name = trim((string) $def['name']);
            if ($name === '') {
                continue;
            }

            $options = [];
            foreach ($variations as $var) {
                $val = $var[$def['value_field']] ?? '';
                if ($val !== '') {
                    $options[] = $val;
                }
            }
            $options = array_values(array_unique($options));

            $key      = strtolower($name);
            $taxonomy = $tax_map[$key] ?? 'pa_' . $key;
            $attr_id  = (int) wc_attribute_taxonomy_id_by_name(substr($taxonomy, 3));

            $specs[] = [
                'name'        => $name,
                'visible'     => $def['visible'],
                'options'     => $options,
                'id'          => $attr_id,
                'taxonomy'    => $taxonomy,
                'value_field' => $def['value_field'],
            ];
        }

        return $specs;
    }

    /**
     * Build the `attributes` array for a single variation from the parent's
     * attribute specs.
     *
     * @param array   $variation  Normalized variation row.
     * @param array[] $attr_specs From build_attribute_specs().
     * @return array[]            WooCommerce variation attributes.
     */
    private function build_variation_attributes(array $variation, array $attr_specs): array {
        $out = [];
        foreach ($attr_specs as $spec) {
            $out[] = [
                'id'     => $spec['id'],
                'name'   => $spec['name'],
                'option' => $variation[$spec['value_field']] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Dispatch an internal REST API POST request and return the decoded
     * response body.
     *
     * Sets the current user to an administrator briefly so the WooCommerce
     * REST API controllers (which check publish_products etc.) allow the
     * operation.  Resets to user 0 afterward.
     *
     * @param  string $route  e.g. "/wc/v3/products"
     * @param  array  $body   Request body parameters.
     * @return array          Decoded response data.
     * @throws \Exception     On HTTP error.
     */
    private function rest_post(string $route, array $body): array {
        $request = new \WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $request->set_body_params($body);

        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if (!empty($admins)) {
            wp_set_current_user($admins[0]);
        }

        $response = rest_do_request($request);

        wp_set_current_user(0);

        if ($response->is_error()) {
            $data = $response->get_data();
            $msg  = $data['message'] ?? $response->get_status();
            throw new \Exception(sprintf(
                /* translators: 1: route, 2: error message */
                __('REST API %1$s: %2$s', 'rey-swatches-import'),
                $route,
                $msg
            ));
        }

        return $response->get_data();
    }

    /**
     * Dispatch an internal REST API PUT request (for updating existing resources).
     *
     * @param  string $route  e.g. "/wc/v3/products/123"
     * @param  array  $body   Request body parameters.
     * @return array          Decoded response data.
     * @throws \Exception     On HTTP error.
     */
    private function rest_put(string $route, array $body): array {
        $request = new \WP_REST_Request('PUT', $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $request->set_body_params($body);

        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if (!empty($admins)) {
            wp_set_current_user($admins[0]);
        }

        $response = rest_do_request($request);

        wp_set_current_user(0);

        if ($response->is_error()) {
            $data = $response->get_data();
            $msg  = $data['message'] ?? $response->get_status();
            throw new \Exception(sprintf(
                /* translators: 1: route, 2: error message */
                __('REST API %1$s: %2$s', 'rey-swatches-import'),
                $route,
                $msg
            ));
        }

        return $response->get_data();
    }
}
