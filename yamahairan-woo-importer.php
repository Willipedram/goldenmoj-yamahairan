<?php
/**
 * Plugin Name: Yamaha Iran Woo Importer
 * Description: Scrape YamahaIran.ir product pages into WooCommerce with live progress, automatic category creation, and localized media handling.
 * Version: 1.1.0
 * Author: سید پدرام نخستین
 */

if (!defined('ABSPATH')) {
    exit;
}

class YamahaIran_Woo_Importer {
    /**
     * Singleton instance.
     *
     * @var YamahaIran_Woo_Importer|null
     */
    private static $instance = null;

    /**
     * Retrieve the singleton instance.
     *
     * @return YamahaIran_Woo_Importer
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * YamahaIran_Woo_Importer constructor.
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_yamahairan_import_product', [$this, 'handle_ajax_import']);
    }

    /**
     * Register the importer page under the WooCommerce menu.
     */
    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            __('Yamaha Iran Importer', 'yamahairan-woo-importer'),
            __('Yamaha Iran Importer', 'yamahairan-woo-importer'),
            'manage_woocommerce',
            'yamahairan-woo-importer',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Render importer admin page.
     */
    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'yamahairan-woo-importer'));
        }

        ?>
        <div class="wrap yamahairan-importer">
            <h1 class="wp-heading-inline"><?php esc_html_e('Yamaha Iran WooCommerce Importer', 'yamahairan-woo-importer'); ?></h1>
            <p class="yamahairan-intro">
                <?php esc_html_e('Paste YamahaIran.ir product links and launch the importer to copy descriptions, media, attributes, and categories directly into WooCommerce.', 'yamahairan-woo-importer'); ?>
            </p>

            <div class="yamahairan-layout">
                <div class="yamahairan-card">
                    <form id="yamahairan-import-form" class="yamahairan-form" method="post">
                        <?php wp_nonce_field('yamahairan_importer_form', 'yamahairan_importer_nonce'); ?>
                        <div class="yamahairan-field">
                            <label class="yamahairan-label" for="yamahairan_product_urls"><?php esc_html_e('Product URLs', 'yamahairan-woo-importer'); ?></label>
                            <textarea name="yamahairan_product_urls" id="yamahairan_product_urls" rows="6" class="yamahairan-textarea" placeholder="https://yamahairan.ir/product/detail/939-YDP-145"></textarea>
                            <p class="description"><?php esc_html_e('Enter one YamahaIran product link per line. The importer strips all hyperlinks automatically.', 'yamahairan-woo-importer'); ?></p>
                        </div>

                        <div class="yamahairan-field">
                            <label class="yamahairan-label" for="yamahairan_product_status"><?php esc_html_e('Product status', 'yamahairan-woo-importer'); ?></label>
                            <select name="yamahairan_product_status" id="yamahairan_product_status" class="yamahairan-select">
                                <option value="draft"><?php esc_html_e('Draft', 'yamahairan-woo-importer'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending Review', 'yamahairan-woo-importer'); ?></option>
                                <option value="publish"><?php esc_html_e('Published', 'yamahairan-woo-importer'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Choose the publication state for imported products.', 'yamahairan-woo-importer'); ?></p>
                        </div>

                        <div class="yamahairan-actions">
                            <?php submit_button(__('Start Import', 'yamahairan-woo-importer'), 'primary yamahairan-submit', 'yamahairan_import_submit', false); ?>
                        </div>
                    </form>
                </div>

                <div class="yamahairan-card yamahairan-live-status" aria-live="polite">
                    <h2 class="yamahairan-card__title"><?php esc_html_e('Live progress', 'yamahairan-woo-importer'); ?></h2>
                    <div class="yamahairan-progress" id="yamahairan-progress" hidden>
                        <div class="yamahairan-progress__bar">
                            <span class="yamahairan-progress__value" id="yamahairan-progress-value" style="width: 0%;"></span>
                        </div>
                        <p class="yamahairan-progress__label" id="yamahairan-progress-label"><?php esc_html_e('Waiting to start…', 'yamahairan-woo-importer'); ?></p>
                    </div>
                    <div class="yamahairan-log" id="yamahairan-log" hidden>
                        <h3 class="yamahairan-log__title"><?php esc_html_e('Technical log', 'yamahairan-woo-importer'); ?></h3>
                        <ul class="yamahairan-log__list" id="yamahairan-log-list"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin assets for the importer interface.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets($hook) {
        if ('woocommerce_page_yamahairan-woo-importer' !== $hook) {
            return;
        }

        $version = '1.1.0';
        wp_enqueue_style(
            'yamahairan-importer-admin',
            plugin_dir_url(__FILE__) . 'assets/css/importer-admin.css',
            [],
            $version
        );

        wp_enqueue_script(
            'yamahairan-importer-admin',
            plugin_dir_url(__FILE__) . 'assets/js/importer-admin.js',
            [],
            $version,
            true
        );

        wp_localize_script(
            'yamahairan-importer-admin',
            'yamahairanImporter',
            [
                'nonce'   => wp_create_nonce('yamahairan_import_product'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'i18n'    => [
                    'processing'     => __('Processing product %1$d of %2$d', 'yamahairan-woo-importer'),
                    'complete'       => __('All products imported successfully.', 'yamahairan-woo-importer'),
                    'error'          => __('An unexpected error occurred.', 'yamahairan-woo-importer'),
                    'ready'          => __('Waiting to start…', 'yamahairan-woo-importer'),
                    'invalidRequest' => __('Please provide at least one product URL.', 'yamahairan-woo-importer'),
                ],
            ]
        );
    }

    /**
     * Process the importer form when submitted.
     */
    public function handle_ajax_import() {
        check_ajax_referer('yamahairan_import_product', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'messages' => [__('You do not have permission to import products.', 'yamahairan-woo-importer')],
            ]);
        }

        $url    = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';

        if (empty($url)) {
            wp_send_json_error([
                'messages' => [__('No product URL provided.', 'yamahairan-woo-importer')],
            ]);
        }

        $log    = [];
        $result = $this->import_product_from_url($url, $status, $log);

        if (is_wp_error($result)) {
            $log[] = $result->get_error_message();
            wp_send_json_error([
                'messages' => $log,
            ]);
        }

        $edit_link = get_edit_post_link($result, '');
        if ($edit_link) {
            $log[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($edit_link),
                sprintf(__('Open product #%d in a new tab.', 'yamahairan-woo-importer'), $result)
            );
        }

        wp_send_json_success([
            'product_id' => $result,
            'messages'   => $log,
        ]);
    }

    /**
     * Import a single product from the provided URL.
     *
     * @param string $url    Product URL from yamahairan.ir.
     * @param string $status Desired post status.
     *
     * @return int|WP_Error  Newly created product ID on success.
     */
    private function import_product_from_url($url, $status = 'draft', array &$log = []) {
        $this->log_step($log, sprintf(__('Starting import for %s', 'yamahairan-woo-importer'), esc_url($url)));

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('The provided URL is invalid.', 'yamahairan-woo-importer'));
        }

        $this->log_step($log, __('Requesting product page…', 'yamahairan-woo-importer'));
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'user-agent' => 'Mozilla/5.0 (compatible; YamahaIranImporter/1.0; +https://yamahairan.ir)',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log_step($log, sprintf(__('Received response code %d', 'yamahairan-woo-importer'), (int) $code));
        if (200 !== $code) {
            return new WP_Error('invalid_response_code', sprintf(__('Unexpected HTTP status: %d', 'yamahairan-woo-importer'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_body', __('The product page returned no content.', 'yamahairan-woo-importer'));
        }

        $this->log_step($log, __('Parsing HTML content…', 'yamahairan-woo-importer'));
        $document = $this->create_dom_document($body);
        if (!$document) {
            return new WP_Error('dom_parse_error', __('Unable to parse product HTML.', 'yamahairan-woo-importer'));
        }

        $xpath     = new DOMXPath($document);
        $metadata  = $this->extract_ld_json($document);
        $title     = $metadata['name'] ?? $this->extract_title($xpath);
        $price     = $metadata['offers']['price'] ?? '';
        $categories = $this->extract_categories($document, $xpath, $metadata);

        if (empty($title)) {
            return new WP_Error('missing_title', __('The product title could not be found.', 'yamahairan-woo-importer'));
        }
        $this->log_step($log, sprintf(__('Detected title: %s', 'yamahairan-woo-importer'), wp_strip_all_tags($title)));

        if (!empty($categories)) {
            $this->log_step($log, sprintf(__('Detected categories: %s', 'yamahairan-woo-importer'), implode(' › ', $categories)));
        }

        $description_html = $metadata['description'] ?? $this->extract_description($xpath, $document);
        $description_html = $this->sanitize_html($description_html);

        $images = !empty($metadata['image']) ? (array) $metadata['image'] : $this->extract_images($xpath, $document, $url);
        $images = array_values(array_unique(array_filter($images)));

        if (empty($images)) {
            return new WP_Error('missing_images', __('No product images were detected.', 'yamahairan-woo-importer'));
        }
        $this->log_step($log, sprintf(__('Found %d image(s)', 'yamahairan-woo-importer'), count($images)));

        $attributes = $this->extract_attributes($xpath, $document);
        if (!empty($attributes)) {
            $this->log_step($log, sprintf(__('Extracted %d technical attribute(s)', 'yamahairan-woo-importer'), count($attributes)));
        }

        $product_data = [
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $description_html,
            'post_status'  => $status,
            'post_type'    => 'product',
        ];

        $product_id = wp_insert_post($product_data, true);

        if (is_wp_error($product_id)) {
            return $product_id;
        }
        $this->log_step($log, sprintf(__('Created product draft #%d', 'yamahairan-woo-importer'), $product_id));

        if (taxonomy_exists('product_type')) {
            wp_set_object_terms($product_id, 'simple', 'product_type');
        }

        if (!empty($description_html)) {
            $short_description = $this->generate_short_description($description_html);
            wp_update_post([
                'ID'           => $product_id,
                'post_excerpt' => $short_description,
            ]);
        }

        $normalized_price = $this->normalize_price($price);
        if ('' !== $normalized_price) {
            update_post_meta($product_id, '_regular_price', $normalized_price);
            update_post_meta($product_id, '_price', $normalized_price);
            $this->log_step($log, sprintf(__('Applied price: %s', 'yamahairan-woo-importer'), $normalized_price));
        }

        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');

        if (function_exists('wc_update_product_stock_status')) {
            wc_update_product_stock_status($product_id, 'instock');
        }

        $attachment_ids = $this->import_images($product_id, $images, $title, $log);
        if (empty($attachment_ids)) {
            wp_trash_post($product_id);
            return new WP_Error('image_import_failed', __('Could not download product images.', 'yamahairan-woo-importer'));
        }

        set_post_thumbnail($product_id, $attachment_ids[0]);
        if (count($attachment_ids) > 1) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
        }
        $this->log_step($log, __('Assigned product gallery images.', 'yamahairan-woo-importer'));

        if (!empty($attributes)) {
            $this->assign_product_attributes($product_id, $attributes);
            $this->log_step($log, __('Saved technical specifications to product attributes.', 'yamahairan-woo-importer'));
        }

        if (!empty($categories)) {
            $this->assign_categories($product_id, $categories, $log);
        }

        do_action('yamahairan_woo_importer_product_imported', $product_id, $url, $metadata);
        $this->log_step($log, __('Import finished successfully.', 'yamahairan-woo-importer'));

        return $product_id;
    }

    /**
     * Create DOMDocument from HTML string.
     *
     * @param string $html Raw HTML.
     *
     * @return DOMDocument|null
     */
    private function create_dom_document($html) {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        if (function_exists('mb_convert_encoding')) {
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        }
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $loaded ? $document : null;
    }

    /**
     * Extract JSON-LD metadata for the product.
     *
     * @param DOMDocument $document DOMDocument instance.
     *
     * @return array<string, mixed>
     */
    private function extract_ld_json(DOMDocument $document) {
        $data = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            if ('application/ld+json' !== $script->getAttribute('type')) {
                continue;
            }

            $contents = trim($script->textContent);
            if (empty($contents)) {
                continue;
            }

            $decoded = json_decode($contents, true);
            if (empty($decoded)) {
                continue;
            }

            if (isset($decoded['@type']) && 'Product' === $decoded['@type']) {
                $data = $decoded;
                break;
            }

            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $item) {
                    if (isset($item['@type']) && 'Product' === $item['@type']) {
                        $data = $item;
                        break 2;
                    }
                }
            }
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Extract product categories from available metadata or markup.
     *
     * @param DOMDocument $document DOMDocument instance.
     * @param DOMXPath    $xpath    DOMXPath instance.
     * @param array       $metadata JSON-LD product metadata.
     *
     * @return array<int, string>
     */
    private function extract_categories(DOMDocument $document, DOMXPath $xpath, array $metadata) {
        $categories = [];

        if (!empty($metadata['category'])) {
            $raw_category = $metadata['category'];
            if (is_array($raw_category)) {
                $categories = array_merge($categories, $raw_category);
            } else {
                $categories = array_merge(
                    $categories,
                    preg_split('/[›>\|]/u', (string) $raw_category) ?: []
                );
            }
        }

        foreach ($document->getElementsByTagName('script') as $script) {
            if ('application/ld+json' !== $script->getAttribute('type')) {
                continue;
            }

            $decoded = json_decode(trim($script->textContent), true);
            if (empty($decoded)) {
                continue;
            }

            $breadcrumb_lists = [];

            if (isset($decoded['@type']) && 'BreadcrumbList' === $decoded['@type']) {
                $breadcrumb_lists[] = $decoded;
            }

            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $item) {
                    if (isset($item['@type']) && 'BreadcrumbList' === $item['@type']) {
                        $breadcrumb_lists[] = $item;
                    }
                }
            }

            foreach ($breadcrumb_lists as $list) {
                if (empty($list['itemListElement']) || !is_array($list['itemListElement'])) {
                    continue;
                }

                foreach ($list['itemListElement'] as $element) {
                    if (isset($element['item']['name'])) {
                        $categories[] = $element['item']['name'];
                    } elseif (isset($element['name'])) {
                        $categories[] = $element['name'];
                    }
                }
            }
        }

        $breadcrumb_queries = [
            "//nav[contains(@class,'breadcrumb')]//a",
            "//ul[contains(@class,'breadcrumb')]//a",
            "//ol[contains(@class,'breadcrumb')]//a",
            "//div[contains(@class,'breadcrumb')]//a",
        ];

        foreach ($breadcrumb_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $categories[] = $node->textContent;
            }
        }

        $product_title = isset($metadata['name']) ? wp_strip_all_tags($metadata['name']) : '';

        $categories = array_map('wp_strip_all_tags', $categories);
        $categories = array_map('trim', $categories);
        $categories = array_filter($categories, function ($value) use ($product_title) {
            if ('' === $value) {
                return false;
            }

            $normalized = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
            if (in_array($normalized, ['home', 'خانه'], true)) {
                return false;
            }

            if ($product_title && $value === $product_title) {
                return false;
            }

            return true;
        });

        if (empty($categories)) {
            return [];
        }

        $categories = array_values(array_unique($categories, SORT_REGULAR));

        return $categories;
    }

    /**
     * Extract the title from DOMXPath when metadata is unavailable.
     *
     * @param DOMXPath $xpath DOMXPath instance.
     *
     * @return string
     */
    private function extract_title(DOMXPath $xpath) {
        $queries = [
            "//h1[contains(@class,'product')]",
            "//h1",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        return '';
    }

    /**
     * Extract description HTML from DOM.
     *
     * @param DOMXPath    $xpath    DOMXPath instance.
     * @param DOMDocument $document DOMDocument instance.
     *
     * @return string
     */
    private function extract_description(DOMXPath $xpath, DOMDocument $document) {
        $queries = [
            "//div[contains(@class,'product-detail__description')]",
            "//div[contains(@class,'product-content')]",
            "//div[contains(@class,'entry-content')]",
            "//div[contains(@class,'woocommerce-Tabs-panel--description')]",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                return $this->dom_inner_html($nodes->item(0));
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);
        return $body ? $this->dom_inner_html($body) : '';
    }

    /**
     * Extract potential image URLs from DOM.
     *
     * @param DOMXPath    $xpath    DOMXPath instance.
     * @param DOMDocument $document DOMDocument instance.
     * @param string      $base_url Fallback base URL for relative paths.
     *
     * @return array<int, string>
     */
    private function extract_images(DOMXPath $xpath, DOMDocument $document, $base_url) {
        $images = [];

        $queries = [
            "//div[contains(@class,'product-gallery')]//img",
            "//div[contains(@class,'woocommerce-product-gallery')]//img",
            "//div[contains(@class,'gallery')]//img",
            "//img[contains(@class,'wp-post-image')]",
            "//img",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || 0 === $nodes->length) {
                continue;
            }

            foreach ($nodes as $node) {
                $src = $node->getAttribute('data-src');
                if (empty($src)) {
                    $src = $node->getAttribute('src');
                }

                if (empty($src)) {
                    continue;
                }

                $images[] = $this->make_absolute_url($src, $base_url);
            }

            if (!empty($images)) {
                break;
            }
        }

        return $images;
    }

    /**
     * Extract attribute data from product tables.
     *
     * @param DOMXPath    $xpath    DOMXPath instance.
     * @param DOMDocument $document DOMDocument instance.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extract_attributes(DOMXPath $xpath, DOMDocument $document) {
        $attributes = [];
        $position   = 0;

        $tables = $xpath->query("//table[contains(@class,'spec') or contains(@class,'attribute') or contains(@class,'feature') or contains(.,'مشخصات')] | //div[contains(@class,'product-attributes')]//table");

        if (!$tables || 0 === $tables->length) {
            $tables = $document->getElementsByTagName('table');
        }

        foreach ($tables as $table) {
            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = $row->getElementsByTagName('th');
                if (0 === $cells->length) {
                    $cells = $row->getElementsByTagName('td');
                }

                if ($cells->length < 2) {
                    continue;
                }

                $name  = trim($cells->item(0)->textContent);
                $value = trim($cells->item(1)->textContent);

                if ('' === $name || '' === $value) {
                    continue;
                }

                $attributes[sanitize_title($name)] = [
                    'name'         => $name,
                    'value'        => $value,
                    'position'     => $position++,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 0,
                ];
            }

            if (!empty($attributes)) {
                break;
            }
        }

        return array_values($attributes);
    }

    /**
     * Assign extracted attributes to the WooCommerce product.
     *
     * @param int   $product_id Product ID.
     * @param array $attributes Attributes data.
     */
    private function assign_product_attributes($product_id, array $attributes) {
        $formatted = [];
        $position  = 0;

        foreach ($attributes as $attribute) {
            $formatted[sanitize_title($attribute['name'])] = [
                'name'         => $attribute['name'],
                'value'        => $attribute['value'],
                'position'     => $position++,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 0,
            ];
        }

        update_post_meta($product_id, '_product_attributes', $formatted);
    }

    /**
     * Assign detected categories to the WooCommerce product, creating them if necessary.
     *
     * @param int              $product_id Product ID.
     * @param array<int,string> $categories Ordered list of categories.
     * @param array<int,string> $log        Reference to live log collection.
     */
    private function assign_categories($product_id, array $categories, array &$log) {
        if (!taxonomy_exists('product_cat')) {
            $this->log_step($log, __('Product categories taxonomy is unavailable.', 'yamahairan-woo-importer'));
            return;
        }

        $parent    = 0;
        $assigned  = [];
        $processed = [];

        foreach ($categories as $category_name) {
            $category_name = trim(wp_strip_all_tags($category_name));
            if ('' === $category_name) {
                continue;
            }

            if (in_array($category_name, $processed, true)) {
                continue;
            }
            $processed[] = $category_name;

            $term = term_exists($category_name, 'product_cat', $parent);

            if (!$term) {
                $inserted = wp_insert_term($category_name, 'product_cat', [
                    'parent' => $parent,
                ]);

                if (is_wp_error($inserted)) {
                    $this->log_step(
                        $log,
                        sprintf(
                            __('Failed to create category %1$s: %2$s', 'yamahairan-woo-importer'),
                            $category_name,
                            $inserted->get_error_message()
                        )
                    );
                    continue;
                }

                $term_id = (int) $inserted['term_id'];
                $this->log_step($log, sprintf(__('Created category: %s', 'yamahairan-woo-importer'), $category_name));
            } else {
                $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
                $this->log_step($log, sprintf(__('Using existing category: %s', 'yamahairan-woo-importer'), $category_name));
            }

            $assigned[] = $term_id;
            $parent     = $term_id;
        }

        if (!empty($assigned)) {
            wp_set_object_terms($product_id, $assigned, 'product_cat');
            $this->log_step($log, __('Categories assigned to product.', 'yamahairan-woo-importer'));
        }
    }

    /**
     * Import images to the media library and return attachment IDs.
     *
     * @param int    $product_id Product ID.
     * @param array  $images     Image URLs.
     * @param string $title      Product title (used for image description).
     *
     * @return array<int, int>
     */
    private function import_images($product_id, array $images, $title, array &$log) {
        $attachment_ids = [];

        $this->prepare_media_environment();

        $total = count($images);
        foreach ($images as $index => $image_url) {
            $this->log_step(
                $log,
                sprintf(
                    __('Downloading image %1$d of %2$d', 'yamahairan-woo-importer'),
                    $index + 1,
                    $total
                )
            );

            $sideloaded = media_sideload_image($image_url, $product_id, $title, 'id');
            if (!is_wp_error($sideloaded)) {
                $attachment_ids[] = $sideloaded;
                $this->log_step($log, sprintf(__('Image saved: %s', 'yamahairan-woo-importer'), esc_url($image_url)));
            } else {
                $this->log_step(
                    $log,
                    sprintf(
                        __('Image failed: %1$s (%2$s)', 'yamahairan-woo-importer'),
                        esc_url($image_url),
                        $sideloaded->get_error_message()
                    )
                );
            }
        }

        return $attachment_ids;
    }

    /**
     * Ensure the WordPress media handling dependencies are loaded.
     */
    private function prepare_media_environment() {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    /**
     * Normalize price strings to WooCommerce-friendly numeric format.
     *
     * @param mixed $price Raw price value.
     *
     * @return string
     */
    private function normalize_price($price) {
        if (is_array($price)) {
            $price = reset($price);
        }

        $price = (string) $price;
        $price = str_replace([',', '٫', '٬'], '', $price);
        $price = preg_replace('/[^0-9\.]/', '', $price);
        $price = trim($price);

        return $price;
    }

    /**
     * Generate a WooCommerce short description from the main content.
     *
     * @param string $html Product description HTML.
     *
     * @return string
     */
    private function generate_short_description($html) {
        $text = wp_strip_all_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return wp_trim_words($text, 45, '…');
    }

    /**
     * Remove anchor tags and sanitize HTML.
     *
     * @param string $html HTML string.
     *
     * @return string
     */
    private function sanitize_html($html) {
        if (empty($html)) {
            return '';
        }

        $document = $this->create_dom_document('<div>' . $html . '</div>');
        if (!$document) {
            return wp_kses_post($html);
        }

        $xpath = new DOMXPath($document);
        $anchors = $xpath->query('//a');
        foreach ($anchors as $anchor) {
            while ($anchor->firstChild) {
                $anchor->parentNode->insertBefore($anchor->firstChild, $anchor);
            }

            if ($anchor->parentNode) {
                $anchor->parentNode->removeChild($anchor);
            }
        }

        $inner_html = '';
        $wrapper    = $document->getElementsByTagName('div')->item(0);
        if ($wrapper) {
            $inner_html = $this->dom_inner_html($wrapper);
        }

        $clean = wp_kses_post($inner_html);

        if ($clean === wp_strip_all_tags($clean)) {
            $clean = wpautop($clean);
        }

        return $clean;
    }

    /**
     * Helper to retrieve inner HTML of a DOMElement.
     *
     * @param DOMNode $element DOMElement instance.
     *
     * @return string
     */
    private function dom_inner_html(DOMNode $element) {
        $innerHTML = '';
        foreach ($element->childNodes as $child) {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return $innerHTML;
    }

    /**
     * Convert relative URLs to absolute.
     *
     * @param string $url      Potentially relative URL.
     * @param string $base_url Base URL of the product page.
     *
     * @return string
     */
    private function make_absolute_url($url, $base_url) {
        if (empty($url)) {
            return '';
        }

        if (0 === strpos($url, 'http://') || 0 === strpos($url, 'https://')) {
            return $url;
        }

        $base_parts = wp_parse_url($base_url);
        if (empty($base_parts['scheme']) || empty($base_parts['host'])) {
            return $url;
        }

        $scheme = $base_parts['scheme'];
        $host   = $base_parts['host'];

        if ('/' === substr($url, 0, 1)) {
            return sprintf('%s://%s%s', $scheme, $host, $url);
        }

        $path = isset($base_parts['path']) ? rtrim(dirname($base_parts['path']), '/') : '';
        return sprintf('%s://%s%s/%s', $scheme, $host, $path, ltrim($url, '/'));
    }

    /**
     * Append a message to the live import log.
     *
     * @param array<int,string> $log     Log container reference.
     * @param string            $message Message to append.
     */
    private function log_step(array &$log, $message) {
        $log[] = wp_kses_post($message);
    }
}

YamahaIran_Woo_Importer::get_instance();
