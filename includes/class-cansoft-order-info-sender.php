<?php
/**
 * Sends order payload to the remote Cansoft Main site.
 *
 * @package Cansoft_Order_Info_Send
 */

defined('ABSPATH') || exit;

if (!defined('CANSOFT_ORDER_INFO_DEBUG')) {
    define('CANSOFT_ORDER_INFO_DEBUG', true);
}

class CANSOFT_Order_Info_Sender {

    public static function log($message, $context = []) {
        if (!CANSOFT_ORDER_INFO_DEBUG) {
            return;
        }
        $str = '[' . gmdate('Y-m-d H:i:s') . '] [Cansoft Order Info Sender] ' . $message;
        if (!empty($context)) {
            $str .= ' ' . wp_json_encode($context);
        }
        $str .= "\n";
        error_log($str);
        
        // Write to log files in wp-content
        if (defined('WP_CONTENT_DIR')) {
            $log_files = [
                WP_CONTENT_DIR . '/cansoft-order-info-sender.log',
                WP_CONTENT_DIR . '/cansoft-finance-sender.log',
            ];
            foreach ($log_files as $log_file) {
                if (is_writable(dirname($log_file))) {
                    @file_put_contents($log_file, $str, FILE_APPEND | LOCK_EX);
                }
            }
        }
    }

    /**
     * @var CANSOFT_Order_Info_Sender|null
     */
    protected static $instance = null;

    /**
     * @return CANSOFT_Order_Info_Sender
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send order data to remote site.
     *
     * @param WC_Order $order Order object.
     * @return array{ success: bool, message: string, code: int }
     */
    public function send_order($order) {
        $url = get_option('cansoft_order_info_remote_url', get_option('cansoft_finance_remote_url', ''));
        $secret = get_option('cansoft_order_info_secret', get_option('cansoft_finance_secret', ''));

        if (empty($url) || empty($secret)) {
            self::log('send_order aborted: Remote URL or secret is empty.');
            return ['success' => false, 'message' => __('Remote URL or secret not configured.', 'cansoft-order-info-send'), 'code' => 0];
        }

        $payload = $this->build_payload($order);
        $endpoint = rtrim($url, '/') . '/wp-json/cansoft-finance/v1/order-earning';

        self::log('Attempting to send order to remote endpoint', [
            'order_id' => $order->get_id(),
            'endpoint' => $endpoint,
        ]);

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $secret,
            ],
            'body' => wp_json_encode($payload),
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (is_wp_error($response)) {
            self::log('send_order failed: wp_remote_post returned error', [
                'order_id' => $order->get_id(),
                'error'    => $response->get_error_message()
            ]);
            return ['success' => false, 'message' => $response->get_error_message(), 'code' => 0];
        }

        self::log('send_order response received', [
            'order_id'  => $order->get_id(),
            'http_code' => $code,
            'response'  => $body,
        ]);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => __('Sent.', 'cansoft-order-info-send'), 'code' => $code];
        }

        $message = $code ? "HTTP {$code}" : __('Request failed.', 'cansoft-order-info-send');
        if ($body) {
            $decoded = json_decode($body, true);
            if (!empty($decoded['message'])) {
                $message = $decoded['message'];
            }
        }
        return ['success' => false, 'message' => $message, 'code' => $code];
    }

    /**
     * Build payload for main site.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function build_payload($order) {
        $order_id = $order->get_id();
        $total = (float) $order->get_total();
        $currency = $order->get_currency();
        $date_paid = $order->get_date_paid();
        $date_created = $order->get_date_created();
        $date = $date_paid ? $date_paid->format('Y-m-d') : ($date_created ? $date_created->format('Y-m-d') : date('Y-m-d'));

        $host = parse_url(home_url(), PHP_URL_HOST);
        $reference = sprintf('woocommerce|%s|%d', $host ?: 'unknown', $order_id);

        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (empty($customer_name)) {
            $customer_name = __('Customer', 'cansoft-order-info-send');
        }
        $company_name = trim((string) $order->get_billing_company());

        $payload = [
            'source'        => 'woocommerce',
            'order_id'      => $order_id,
            'total'         => $total,
            'currency'      => $currency,
            'date'          => $date,
            'status'        => $order->get_status(),
            'site_url'      => home_url(),
            'reference'     => $reference,
            'customer_name' => $customer_name,
            'company_name'  => $company_name,
            'customer_note' => $order->get_customer_note(),
        ];

        $items = $this->get_order_items_with_currency($order);
        if (!empty($items)) {
            $payload['items'] = $items;
        }

        self::log('build_payload', [
            'order_id'       => $order_id,
            'order_currency' => $currency,
            'total'          => $total,
            'items_count'    => count($items),
            'items'          => $items,
        ]);

        return $payload;
    }

    /**
     * Get order line items with amount, currency, and mapped category.
     *
     * @param WC_Order $order Order object.
     * @return array<int, array{ amount: float, currency: string, category: string }>
     */
    protected function get_order_items_with_currency($order) {
        $order_currency = $order->get_currency();
        $items = [];

        $allowed_categories = [
            'Affiliate Programs',
            'Audits & Consulting',
            'Google Ads',
            'Google Business Profile',
            'Graphics',
            'Hosting',
            'Organic Social',
            'Past Due Payments',
            'Project Kickoff/Setup',
            'Random',
            'SEO',
            'Social Ads',
            'Software Development',
            'Website Related'
        ];

        foreach ($order->get_items() as $order_item) {
            if (!is_object($order_item) || !method_exists($order_item, 'get_total') || !method_exists($order_item, 'get_product')) {
                continue;
            }
            /** @var \WC_Order_Item_Product $order_item */
            $amount = (float) $order_item->get_total();
            $currency = $this->get_line_item_currency($order_item, $order_currency);

            $product_id = (int) $order_item->get_product_id();
            $category = 'Random';

            $terms = wp_get_post_terms($product_id, 'product_cat');
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    foreach ($allowed_categories as $allowed) {
                        if (strcasecmp(html_entity_decode($term->name), $allowed) === 0) {
                            $category = $allowed;
                            break 2;
                        }
                    }
                    if ($term->parent > 0) {
                        $parent_term = get_term($term->parent, 'product_cat');
                        if ($parent_term && !is_wp_error($parent_term)) {
                            foreach ($allowed_categories as $allowed) {
                                if (strcasecmp(html_entity_decode($parent_term->name), $allowed) === 0) {
                                    $category = $allowed;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            $items[] = [
                'amount'   => $amount,
                'currency' => $currency,
                'category' => $category,
            ];
        }

        return $items;
    }

    /**
     * Get line item currency using CPP or order fallback.
     *
     * @param \WC_Order_Item_Product $order_item
     * @param string $order_currency
     * @return string
     */
    protected function get_line_item_currency($order_item, $order_currency) {
        $product = $order_item->get_product();
        if (!$product || !is_object($product)) {
            return $order_currency;
        }

        $product_id = (int) $order_item->get_product_id();
        $variation_id = (int) $order_item->get_variation_id();
        $id_for_currency = $product_id;

        $prd_currency = '';
        $source = 'fallback_order';

        if (function_exists('alg_wc_cpp') && is_callable('alg_wc_cpp')) {
            $alg = alg_wc_cpp();
            $core = is_object($alg) ? $alg->core : null;
            if (is_object($core) && method_exists($core, 'get_product_currency')) {
                $prd_currency = $core->get_product_currency($id_for_currency);
                if ('' !== $prd_currency) {
                    $source = 'cpp_get_product_currency';
                }
            }
        }

        if ('' === $prd_currency && $id_for_currency > 0) {
            $prd_currency = get_post_meta($id_for_currency, '_alg_wc_cpp_currency', true);
            if ('' !== $prd_currency) {
                $source = 'meta_product_' . $id_for_currency;
            }
        }
        if ('' === $prd_currency && $variation_id > 0) {
            $prd_currency = get_post_meta($variation_id, '_alg_wc_cpp_currency', true);
            if ('' !== $prd_currency) {
                $source = 'meta_variation_' . $variation_id;
            }
        }

        $resolved = ('' !== $prd_currency && is_string($prd_currency)) ? $prd_currency : $order_currency;
        return $resolved;
    }

    /**
     * Test connection to remote Cansoft site.
     *
     * @param string $url Remote site URL.
     * @param string $secret Webhook secret.
     * @return array{ success: bool, message: string }
     */
    public function test_connection($url, $secret) {
        self::log('Testing connection to remote site', ['url' => $url]);

        if (empty($url) || empty($secret)) {
            return ['success' => false, 'message' => __('URL and secret are required.', 'cansoft-order-info-send')];
        }

        $endpoint = rtrim($url, '/') . '/wp-json/cansoft-finance/v1/test-connection';

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $secret,
            ],
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (is_wp_error($response)) {
            self::log('Test connection failed: wp_remote_post returned error', ['error' => $response->get_error_message()]);
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $decoded = json_decode($body, true);
        $message = !empty($decoded['message']) ? $decoded['message'] : '';

        if ($code === 200) {
            return ['success' => true, 'message' => !empty($message) ? $message : __('Connection successful!', 'cansoft-order-info-send')];
        }

        if (empty($message)) {
            $message = sprintf(__('HTTP error %d', 'cansoft-order-info-send'), $code);
        }
        return ['success' => false, 'message' => $message];
    }
}
