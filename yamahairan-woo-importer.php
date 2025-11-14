<?php
/**
 * Plugin Name: Yamaha Iran Woo Importer
 * Description: Imports products from yamahairan.ir into WooCommerce by scraping their product pages.
 * Version: 1.0.0
 * Author: OpenAI Assistant
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
        add_action('admin_init', [$this, 'maybe_process_form']);
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

        $messages = get_transient('yamahairan_importer_messages');
        if ($messages) {
            delete_transient('yamahairan_importer_messages');
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Yamaha Iran WooCommerce Importer', 'yamahairan-woo-importer'); ?></h1>
            <p><?php esc_html_e('Paste one YamahaIran product URL per line. The importer will scrape the product content, remove all links, download the media to your library, and create WooCommerce products in draft status.', 'yamahairan-woo-importer'); ?></p>

            <?php if (!empty($messages['errors'])) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Import Errors:', 'yamahairan-woo-importer'); ?></strong></p>
                    <ul>
                        <?php foreach ($messages['errors'] as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($messages['success'])) : ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Import Results:', 'yamahairan-woo-importer'); ?></strong></p>
                    <ul>
                        <?php foreach ($messages['success'] as $success) : ?>
                            <li><?php echo esc_html($success); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('yamahairan_importer', 'yamahairan_importer_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yamahairan_product_urls"><?php esc_html_e('Product URLs', 'yamahairan-woo-importer'); ?></label>
                        </th>
                        <td>
                            <textarea name="yamahairan_product_urls" id="yamahairan_product_urls" rows="6" cols="80" class="large-text" placeholder="https://yamahairan.ir/product/detail/939-YDP-145"></textarea>
                            <p class="description"><?php esc_html_e('Each line should contain a full product URL from yamahairan.ir.', 'yamahairan-woo-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="yamahairan_product_status"><?php esc_html_e('Product status', 'yamahairan-woo-importer'); ?></label>
                        </th>
                        <td>
                            <select name="yamahairan_product_status" id="yamahairan_product_status">
                                <option value="draft"><?php esc_html_e('Draft', 'yamahairan-woo-importer'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending Review', 'yamahairan-woo-importer'); ?></option>
                                <option value="publish"><?php esc_html_e('Published', 'yamahairan-woo-importer'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Select the status for the newly created products.', 'yamahairan-woo-importer'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Import Products', 'yamahairan-woo-importer')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Process the importer form when submitted.
     */
    public function maybe_process_form() {
        if (!is_admin() || empty($_POST['yamahairan_importer_nonce'])) {
            return;
        }

        if (!isset($_POST['yamahairan_product_urls'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['yamahairan_importer_nonce']), 'yamahairan_importer')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $urls_raw = sanitize_textarea_field(wp_unslash($_POST['yamahairan_product_urls']));
        $status   = isset($_POST['yamahairan_product_status']) ? sanitize_key(wp_unslash($_POST['yamahairan_product_status'])) : 'draft';

        $urls = array_filter(array_map('trim', explode("\n", $urls_raw)));

        if (empty($urls)) {
            set_transient('yamahairan_importer_messages', [
                'errors' => [__('No product URLs were provided.', 'yamahairan-woo-importer')],
            ], 30);

            wp_safe_redirect(add_query_arg([], menu_page_url('yamahairan-woo-importer', false)));
            exit;
        }

        $messages = [
            'success' => [],
            'errors'  => [],
        ];

        foreach ($urls as $url) {
            $result = $this->import_product_from_url($url, $status);

            if (is_wp_error($result)) {
                $messages['errors'][] = sprintf(
                    /* translators: %s: error message */
                    __('%1$s → %2$s', 'yamahairan-woo-importer'),
                    $url,
                    $result->get_error_message()
                );
            } else {
                $messages['success'][] = sprintf(
                    __('Imported product #%d successfully.', 'yamahairan-woo-importer'),
                    $result
                );
            }
        }

        set_transient('yamahairan_importer_messages', $messages, 30);
        wp_safe_redirect(add_query_arg([], menu_page_url('yamahairan-woo-importer', false)));
        exit;
    }

    /**
     * Import a single product from the provided URL.
     *
     * @param string $url    Product URL from yamahairan.ir.
     * @param string $status Desired post status.
     *
     * @return int|WP_Error  Newly created product ID on success.
     */
    private function import_product_from_url($url, $status = 'draft') {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('The provided URL is invalid.', 'yamahairan-woo-importer'));
        }

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
        if (200 !== $code) {
            return new WP_Error('invalid_response_code', sprintf(__('Unexpected HTTP status: %d', 'yamahairan-woo-importer'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_body', __('The product page returned no content.', 'yamahairan-woo-importer'));
        }

        $document = $this->create_dom_document($body);
        if (!$document) {
            return new WP_Error('dom_parse_error', __('Unable to parse product HTML.', 'yamahairan-woo-importer'));
        }

        $xpath = new DOMXPath($document);

        $metadata = $this->extract_ld_json($document);
        $title    = $metadata['name'] ?? $this->extract_title($xpath);
        $price    = $metadata['offers']['price'] ?? '';

        if (empty($title)) {
            return new WP_Error('missing_title', __('The product title could not be found.', 'yamahairan-woo-importer'));
        }

        $description_html = $metadata['description'] ?? $this->extract_description($xpath, $document);
        $description_html = $this->sanitize_html($description_html);

        $images = !empty($metadata['image']) ? (array) $metadata['image'] : $this->extract_images($xpath, $document, $url);
        $images = array_values(array_unique(array_filter($images)));

        if (empty($images)) {
            return new WP_Error('missing_images', __('No product images were detected.', 'yamahairan-woo-importer'));
        }

        $attributes = $this->extract_attributes($xpath, $document);

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
        }

        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');

        if (function_exists('wc_update_product_stock_status')) {
            wc_update_product_stock_status($product_id, 'instock');
        }

        $attachment_ids = $this->import_images($product_id, $images, $title);
        if (empty($attachment_ids)) {
            wp_trash_post($product_id);
            return new WP_Error('image_import_failed', __('Could not download product images.', 'yamahairan-woo-importer'));
        }

        set_post_thumbnail($product_id, $attachment_ids[0]);
        if (count($attachment_ids) > 1) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
        }

        if (!empty($attributes)) {
            $this->assign_product_attributes($product_id, $attributes);
        }

        do_action('yamahairan_woo_importer_product_imported', $product_id, $url, $metadata);

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
     * Import images to the media library and return attachment IDs.
     *
     * @param int    $product_id Product ID.
     * @param array  $images     Image URLs.
     * @param string $title      Product title (used for image description).
     *
     * @return array<int, int>
     */
    private function import_images($product_id, array $images, $title) {
        $attachment_ids = [];

        $this->prepare_media_environment();

        foreach ($images as $image_url) {
            $sideloaded = media_sideload_image($image_url, $product_id, $title, 'id');
            if (!is_wp_error($sideloaded)) {
                $attachment_ids[] = $sideloaded;
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
}

YamahaIran_Woo_Importer::get_instance();
