<?php
/**
 * Plugin Name: Yamaha Iran Woo Importer
 * Description: انتقال خودکار محصولات YamahaIran.ir به ووکامرس با نوار پیشرفت زنده و ذخیره تصاویر روی هاست شما.
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
            __('واردکننده یاماها ایران', 'yamahairan-woo-importer'),
            __('واردکننده یاماها ایران', 'yamahairan-woo-importer'),
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
            wp_die(__('شما مجوز دسترسی به این صفحه را ندارید.', 'yamahairan-woo-importer'));
        }

        ?>
        <div class="wrap yamahairan-importer">
            <h1 class="wp-heading-inline"><?php esc_html_e('درون‌ریزی یاماها ایران برای ووکامرس', 'yamahairan-woo-importer'); ?></h1>
            <p class="yamahairan-intro">
                <?php esc_html_e('لطفاً لینک محصولاتی را که در سایت YamahaIran.ir قرار دارند وارد کنید تا متن، تصاویر و مشخصات فنی آن‌ها به فروشگاه شما منتقل شود.', 'yamahairan-woo-importer'); ?>
            </p>

            <div class="yamahairan-layout">
                <div class="yamahairan-card">
                    <form id="yamahairan-import-form" class="yamahairan-form" method="post">
                        <?php wp_nonce_field('yamahairan_importer_form', 'yamahairan_importer_nonce'); ?>
                        <div class="yamahairan-field">
                            <label class="yamahairan-label" for="yamahairan_product_urls"><?php esc_html_e('آدرس‌های محصولات', 'yamahairan-woo-importer'); ?></label>
                            <textarea name="yamahairan_product_urls" id="yamahairan_product_urls" rows="6" class="yamahairan-textarea" placeholder="https://yamahairan.ir/product/detail/939-YDP-145"></textarea>
                            <p class="description"><?php esc_html_e('هر خط باید شامل یک آدرس کامل محصول باشد. لینک‌ها از متن نهایی حذف می‌شوند.', 'yamahairan-woo-importer'); ?></p>
                        </div>

                        <div class="yamahairan-field">
                            <label class="yamahairan-label" for="yamahairan_product_status"><?php esc_html_e('وضعیت محصول پس از واردسازی', 'yamahairan-woo-importer'); ?></label>
                            <select name="yamahairan_product_status" id="yamahairan_product_status" class="yamahairan-select">
                                <option value="draft"><?php esc_html_e('پیشنویس', 'yamahairan-woo-importer'); ?></option>
                                <option value="pending"><?php esc_html_e('در انتظار بررسی', 'yamahairan-woo-importer'); ?></option>
                                <option value="publish"><?php esc_html_e('منتشر شود', 'yamahairan-woo-importer'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('انتخاب کنید محصول پس از واردسازی در چه وضعیتی قرار بگیرد.', 'yamahairan-woo-importer'); ?></p>
                        </div>

                        <div class="yamahairan-actions">
                            <?php submit_button(__('شروع واردسازی', 'yamahairan-woo-importer'), 'primary yamahairan-submit', 'yamahairan_import_submit', false); ?>
                        </div>
                    </form>
                </div>

                <div class="yamahairan-card yamahairan-live-status" aria-live="polite">
                    <h2 class="yamahairan-card__title"><?php esc_html_e('پیشرفت لحظه‌ای', 'yamahairan-woo-importer'); ?></h2>
                    <div class="yamahairan-progress" id="yamahairan-progress" hidden>
                        <div class="yamahairan-progress__bar">
                            <span class="yamahairan-progress__value" id="yamahairan-progress-value" style="width: 0%;"></span>
                        </div>
                        <p class="yamahairan-progress__label" id="yamahairan-progress-label"><?php esc_html_e('آماده برای شروع…', 'yamahairan-woo-importer'); ?></p>
                    </div>
                    <div class="yamahairan-log" id="yamahairan-log" hidden>
                        <h3 class="yamahairan-log__title"><?php esc_html_e('گزارش روند کار', 'yamahairan-woo-importer'); ?></h3>
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
                    'processing'     => __('در حال پردازش محصول %1$d از %2$d', 'yamahairan-woo-importer'),
                    'complete'       => __('همه محصولات با موفقیت منتقل شدند.', 'yamahairan-woo-importer'),
                    'error'          => __('خطا در برقراری ارتباط با سرور.', 'yamahairan-woo-importer'),
                    'ready'          => __('آماده برای شروع…', 'yamahairan-woo-importer'),
                    'invalidRequest' => __('لطفاً حداقل یک آدرس محصول وارد کنید.', 'yamahairan-woo-importer'),
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
                'messages' => [__('شما مجوز واردسازی محصول را ندارید.', 'yamahairan-woo-importer')],
            ]);
        }

        $url    = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';

        if (empty($url)) {
            wp_send_json_error([
                'messages' => [__('هیچ آدرس محصولی ارسال نشده است.', 'yamahairan-woo-importer')],
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
                sprintf(__('باز کردن محصول شماره %d در برگه جدید.', 'yamahairan-woo-importer'), $result)
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
        $this->log_step($log, sprintf(__('آغاز دریافت محصول: %s', 'yamahairan-woo-importer'), esc_url($url)));

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('آدرس وارد شده معتبر نیست.', 'yamahairan-woo-importer'));
        }

        $this->log_step($log, __('در حال دریافت صفحه محصول…', 'yamahairan-woo-importer'));
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
        $this->log_step($log, sprintf(__('کد پاسخ سرور: %d', 'yamahairan-woo-importer'), (int) $code));
        if (200 !== $code) {
            return new WP_Error('invalid_response_code', sprintf(__('پاسخ غیرمنتظره از سرور: %d', 'yamahairan-woo-importer'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_body', __('صفحه محصول محتوایی برنگرداند.', 'yamahairan-woo-importer'));
        }

        $this->log_step($log, __('در حال بررسی محتوای صفحه…', 'yamahairan-woo-importer'));
        $document = $this->create_dom_document($body);
        if (!$document) {
            return new WP_Error('dom_parse_error', __('امکان تحلیل محتوای صفحه وجود ندارد.', 'yamahairan-woo-importer'));
        }

        $xpath     = new DOMXPath($document);
        $metadata   = $this->extract_ld_json($document);
        $title      = $metadata['name'] ?? $this->extract_title($xpath);
        $categories = $this->extract_categories($document, $xpath, $metadata);

        if (empty($title)) {
            return new WP_Error('missing_title', __('عنوان محصول در صفحه پیدا نشد.', 'yamahairan-woo-importer'));
        }
        $this->log_step($log, sprintf(__('عنوان محصول پیدا شد: %s', 'yamahairan-woo-importer'), wp_strip_all_tags($title)));

        if (!empty($categories)) {
            $this->log_step($log, sprintf(__('دسته‌های پیشنهادی: %s', 'yamahairan-woo-importer'), implode(' › ', $categories)));
        }

        $description_html = $metadata['description'] ?? $this->extract_description($xpath, $document);
        $description_html = $this->sanitize_html($description_html);

        $images = !empty($metadata['image']) ? (array) $metadata['image'] : $this->extract_images($xpath, $document, $url);
        $images = array_values(array_unique(array_filter($images)));

        if (empty($images)) {
            return new WP_Error('missing_images', __('هیچ تصویری برای محصول پیدا نشد.', 'yamahairan-woo-importer'));
        }
        $this->log_step($log, sprintf(__('تعداد %d تصویر برای محصول پیدا شد.', 'yamahairan-woo-importer'), count($images)));

        $attributes = $this->extract_attributes($xpath, $document);
        if (!empty($attributes)) {
            $this->log_step($log, sprintf(__('تعداد %d ویژگی فنی استخراج شد.', 'yamahairan-woo-importer'), count($attributes)));
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
        $this->log_step($log, sprintf(__('محصول با شناسه %d در ووکامرس ساخته شد.', 'yamahairan-woo-importer'), $product_id));

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

        $price_value      = $metadata['offers']['price'] ?? '';
        $normalized_price = $this->normalize_price($price_value);

        if ('' === $normalized_price) {
            $normalized_price = $this->extract_price_from_dom($xpath);
        }

        if ('' !== $normalized_price) {
            update_post_meta($product_id, '_regular_price', $normalized_price);
            update_post_meta($product_id, '_price', $normalized_price);
            $this->log_step($log, sprintf(__('قیمت ثبت شد: %s', 'yamahairan-woo-importer'), $normalized_price));
        } else {
            $this->log_step($log, __('هیچ قیمتی در صفحه درج نشده بود.', 'yamahairan-woo-importer'));
        }

        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');

        if (function_exists('wc_update_product_stock_status')) {
            wc_update_product_stock_status($product_id, 'instock');
        }

        $attachment_ids = $this->import_images($product_id, $images, $title, $log);
        if (empty($attachment_ids)) {
            wp_trash_post($product_id);
            return new WP_Error('image_import_failed', __('دانلود تصاویر محصول انجام نشد.', 'yamahairan-woo-importer'));
        }

        set_post_thumbnail($product_id, $attachment_ids[0]);
        if (count($attachment_ids) > 1) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
        }
        $this->log_step($log, __('تصاویر شاخص و گالری ذخیره شد.', 'yamahairan-woo-importer'));

        if (!empty($attributes)) {
            $this->assign_product_attributes($product_id, $attributes);
            $this->log_step($log, __('مشخصات فنی در ویژگی‌های محصول ذخیره شد.', 'yamahairan-woo-importer'));
        }

        if (!empty($categories)) {
            $this->assign_categories($product_id, $categories, $log);
        }

        do_action('yamahairan_woo_importer_product_imported', $product_id, $url, $metadata);
        $this->log_step($log, __('واردسازی با موفقیت به پایان رسید.', 'yamahairan-woo-importer'));

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
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//h1",
            "//div[contains(@class,'PDInfo')]//h1",
            "//div[contains(@class,'product-detail')]//h1",
            "//header[contains(@class,'product')]//h1",
            "//h1[contains(@class,'product')]",
            "//h1",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        $meta = $xpath->query("//meta[@property='og:title']");
        if ($meta && $meta->length > 0) {
            $content = $meta->item(0)->getAttribute('content');
            if (!empty($content)) {
                return trim($content);
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
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//div[contains(@class,'PDContent') or contains(@class,'PDDesc') or contains(@class,'PDText') or contains(@class,'description') or contains(@id,'description')]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//section[contains(@class,'description') or contains(@class,'detail')]",
            "//div[contains(@class,'product-detail__description')]",
            "//div[contains(@class,'product-content')]",
            "//div[contains(@class,'entry-content')]",
            "//div[contains(@class,'woocommerce-Tabs-panel--description')]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDContent ')]",
            "//section[contains(@class,'product-description')]",
            "//div[@itemprop='description']",
            "//div[@id='description']",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                return $this->dom_inner_html($nodes->item(0));
            }
        }

        $fallback_containers = [
            "(//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')])[1]",
            "(//div[contains(@class,'product-detail') or contains(@class,'ProductDetail')])[1]",
            "(//article[contains(@class,'product')])[1]",
            "(//main[contains(@class,'product')])[1]",
        ];

        foreach ($fallback_containers as $container_query) {
            $container_nodes = $xpath->query($container_query);
            if (!$container_nodes || 0 === $container_nodes->length) {
                continue;
            }

            $html = $this->collect_content_blocks($container_nodes->item(0));
            if (!empty($html)) {
                return $html;
            }
        }

        $meta_description = $xpath->query("//meta[@name='description']");
        if ($meta_description && $meta_description->length > 0) {
            $content = $meta_description->item(0)->getAttribute('content');
            if (!empty($content)) {
                return wpautop(esc_html($content));
            }
        }

        return '';
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
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDGallery ')]//img",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDGallery ')]//source",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || 0 === $nodes->length) {
                continue;
            }

            foreach ($nodes as $node) {
                $images = array_merge($images, $this->resolve_node_images($node, $base_url));
            }

            if (!empty($images)) {
                break;
            }
        }

        if (empty($images)) {
            $anchor_nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' PDGallery ')]//a");
            if ($anchor_nodes && $anchor_nodes->length > 0) {
                foreach ($anchor_nodes as $anchor) {
                    $images = array_merge($images, $this->resolve_node_images($anchor, $base_url));
                }
            }
        }

        if (empty($images)) {
            $fallback_queries = [
                "//div[contains(@class,'product-gallery')]//img",
                "//div[contains(@class,'woocommerce-product-gallery')]//img",
                "//div[contains(@class,'gallery')]//img",
                "//img[contains(@class,'wp-post-image')]",
                "//img",
            ];

            foreach ($fallback_queries as $query) {
                $nodes = $xpath->query($query);
                if (!$nodes || 0 === $nodes->length) {
                    continue;
                }

                foreach ($nodes as $node) {
                    $images = array_merge($images, $this->resolve_node_images($node, $base_url));
                }

                if (!empty($images)) {
                    break;
                }
            }
        }

        if (empty($images)) {
            $meta_queries = [
                "//meta[@property='og:image']",
                "//meta[@property='og:image:secure_url']",
                "//meta[@name='twitter:image']",
                "//link[@rel='image_src']",
            ];

            foreach ($meta_queries as $meta_query) {
                $nodes = $xpath->query($meta_query);
                if (!$nodes || 0 === $nodes->length) {
                    continue;
                }

                foreach ($nodes as $node) {
                    if ($node->hasAttribute('content')) {
                        $images = array_merge($images, $this->resolve_node_images($node, $base_url));
                    } elseif ($node->hasAttribute('href')) {
                        $images = array_merge($images, $this->resolve_node_images($node, $base_url));
                    }
                }

                if (!empty($images)) {
                    break;
                }
            }
        }

        $images = array_values(array_unique(array_filter($images)));

        return $images;
    }

    /**
     * تلاش برای استخراج قیمت از HTML صفحه محصول.
     *
     * @param DOMXPath $xpath DOMXPath instance.
     *
     * @return string
     */
    private function extract_price_from_dom(DOMXPath $xpath) {
        $candidates = [];

        $attribute_queries = [
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//*[@data-price]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//*[@data-regular-price]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//*[@data-sale-price]",
            "//div[contains(@class,'product-price')]//*[@data-price]",
            "//span[@itemprop='price']",
            "//meta[@itemprop='price']",
        ];

        foreach ($attribute_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || 0 === $nodes->length) {
                continue;
            }

            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    foreach (['data-price', 'data-regular-price', 'data-sale-price', 'content'] as $attribute) {
                        if ($node->hasAttribute($attribute)) {
                            $candidates[] = $node->getAttribute($attribute);
                        }
                    }
                }

                $text = trim($node->textContent);
                if ('' !== $text) {
                    $candidates[] = $text;
                }
            }
        }

        $text_queries = [
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//*[contains(@class,'price') or contains(@class,'Price') or contains(@id,'price')]",
            "//div[contains(@class,'PDPrice') or contains(@class,'ProductPrice') or contains(@class,'product-price')]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]//*[contains(text(),'تومان') or contains(text(),'ریال')]",
        ];

        foreach ($text_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || 0 === $nodes->length) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = trim($node->textContent);
                if ('' !== $text) {
                    $candidates[] = $text;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize_price($candidate);
            if ('' !== $normalized) {
                return $normalized;
            }
        }

        $info_node = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' PDInfo ')]");
        if ($info_node && $info_node->length > 0) {
            $text = trim($info_node->item(0)->textContent);
            if ($text && preg_match('/([0-9][0-9\s٬٫\.,]*)/', $text, $matches)) {
                $normalized = $this->normalize_price($matches[0]);
                if ('' !== $normalized) {
                    return $normalized;
                }
            }
        }

        return '';
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
            $this->log_step($log, __('طبقه‌بندی محصولات در ووکامرس فعال نیست.', 'yamahairan-woo-importer'));
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
                            __('ساخت دسته‌بندی %1$s ممکن نشد: %2$s', 'yamahairan-woo-importer'),
                            $category_name,
                            $inserted->get_error_message()
                        )
                    );
                    continue;
                }

                $term_id = (int) $inserted['term_id'];
                $this->log_step($log, sprintf(__('دسته‌بندی جدید ساخته شد: %s', 'yamahairan-woo-importer'), $category_name));
            } else {
                $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
                $this->log_step($log, sprintf(__('از دسته‌بندی موجود استفاده شد: %s', 'yamahairan-woo-importer'), $category_name));
            }

            $assigned[] = $term_id;
            $parent     = $term_id;
        }

        if (!empty($assigned)) {
            wp_set_object_terms($product_id, $assigned, 'product_cat');
            $this->log_step($log, __('دسته‌بندی‌ها به محصول اضافه شد.', 'yamahairan-woo-importer'));
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
                    __('در حال دانلود تصویر %1$d از %2$d', 'yamahairan-woo-importer'),
                    $index + 1,
                    $total
                )
            );

            $sideloaded = media_sideload_image($image_url, $product_id, $title, 'id');
            if (!is_wp_error($sideloaded)) {
                $attachment_ids[] = $sideloaded;
                $this->log_step($log, sprintf(__('تصویر ذخیره شد: %s', 'yamahairan-woo-importer'), esc_url($image_url)));
            } else {
                $this->log_step(
                    $log,
                    sprintf(
                        __('دانلود تصویر ناموفق بود: %1$s (%2$s)', 'yamahairan-woo-importer'),
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
     * Collect a concise block of HTML content from a container node.
     *
     * @param DOMNode $container Container node.
     *
     * @return string
     */
    private function collect_content_blocks(DOMNode $container) {
        $document = $container->ownerDocument;
        if (!$document) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('.//p | .//ul | .//ol | .//table | .//h2 | .//h3 | .//h4 | .//blockquote | .//dl', $container);

        if (!$nodes || 0 === $nodes->length) {
            return '';
        }

        $html  = '';
        $count = 0;
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ('' === $text) {
                continue;
            }

            $html .= $document->saveHTML($node);
            $count++;

            if ($count >= 12) {
                break;
            }
        }

        return $html;
    }

    /**
     * Resolve potential image URLs from a DOM node.
     *
     * @param DOMNode $node     Node to inspect.
     * @param string  $base_url Base URL for relative references.
     *
     * @return array<int, string>
     */
    private function resolve_node_images(DOMNode $node, $base_url) {
        if (!$node instanceof DOMElement) {
            return [];
        }

        $candidates = [];

        $attributes = ['data-src', 'data-large', 'data-image', 'data-origin', 'data-full', 'data-highres', 'data-fancybox', 'data-thumb', 'data-preview', 'src', 'href', 'content'];
        foreach ($attributes as $attribute) {
            if ($node->hasAttribute($attribute)) {
                $candidates[] = $node->getAttribute($attribute);
            }
        }

        if ($node->hasAttribute('srcset')) {
            $srcset = $node->getAttribute('srcset');
            $parts  = explode(',', $srcset);
            foreach ($parts as $part) {
                $piece = trim(explode(' ', trim($part))[0]);
                if (!empty($piece)) {
                    $candidates[] = $piece;
                }
            }
        }

        if ($node->hasAttribute('data-srcset')) {
            $srcset = $node->getAttribute('data-srcset');
            $parts  = explode(',', $srcset);
            foreach ($parts as $part) {
                $piece = trim(explode(' ', trim($part))[0]);
                if (!empty($piece)) {
                    $candidates[] = $piece;
                }
            }
        }

        $resolved = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (empty($candidate)) {
                continue;
            }

            if (0 === strpos($candidate, 'data:image')) {
                continue;
            }

            $absolute = $this->make_absolute_url($candidate, $base_url);
            if (!$this->is_valid_image_url($absolute)) {
                continue;
            }

            $resolved[] = $absolute;
        }

        return $resolved;
    }

    /**
     * Determine whether the URL looks like a downloadable image.
     *
     * @param string $url URL to validate.
     *
     * @return bool
     */
    private function is_valid_image_url($url) {
        if (empty($url)) {
            return false;
        }

        $parsed = wp_parse_url($url);
        $path   = isset($parsed['path']) ? $parsed['path'] : $url;

        if (empty($path)) {
            return false;
        }

        if (preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(?:$|[\?#])/i', $path)) {
            return true;
        }

        return false;
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

        if (0 === strpos($url, '//')) {
            return sprintf('%s:%s', $scheme, $url);
        }

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
