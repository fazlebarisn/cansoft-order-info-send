<?php
/**
 * Settings page and REST API endpoints for Cansoft Order Info Send.
 *
 * @package Cansoft_Order_Info_Send
 */

defined('ABSPATH') || exit;

class CANSOFT_Order_Info_Admin {

    protected static $instance = null;

    const OPTION_GROUP = 'cansoft_order_info_settings';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('wp_ajax_cansoft_order_info_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_cansoft_order_info_test_store', [$this, 'ajax_test_store_connection']);

        // REST API hooks for sales reporting
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_filter('rest_authentication_errors', [$this, 'bypass_rest_auth_for_order_info'], 99);
    }

    public function add_menu() {
        add_options_page(
            __('Cansoft Order Info', 'cansoft-order-info-send'),
            __('Cansoft Order Info', 'cansoft-order-info-send'),
            'manage_options',
            'cansoft-order-info-send',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, 'cansoft_order_info_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(self::OPTION_GROUP, 'cansoft_order_info_store_type', [
            'type'              => 'string',
            'default'           => 'auto',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(self::OPTION_GROUP, 'cansoft_order_info_ecwid_store_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(self::OPTION_GROUP, 'cansoft_order_info_ecwid_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $secret     = get_option('cansoft_order_info_secret', get_option('cansoft_finance_secret', ''));
        $store_type = get_option('cansoft_order_info_store_type', 'auto');
        $ecwid_id   = get_option('cansoft_order_info_ecwid_store_id', get_option('ecwid_store_id', ''));
        $ecwid_tok  = get_option('cansoft_order_info_ecwid_token', get_option('ecwid_oauth_token', get_option('ecwid_api_secret_key', '')));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p class="description"><?php esc_html_e('Provides REST API endpoints for the Cansoft Report System on the main site to fetch sales and order metrics on demand from WooCommerce or Ecwid.', 'cansoft-order-info-send'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cansoft_order_info_secret"><?php esc_html_e('Webhook secret', 'cansoft-order-info-send'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="cansoft_order_info_secret" id="cansoft_order_info_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text" autocomplete="off">
                            <p class="description"><?php esc_html_e('Set a secret key. Enter this same secret in Project Settings -> API tab on your Main Cansoft Site.', 'cansoft-order-info-send'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cansoft_order_info_store_type"><?php esc_html_e('Store Platform', 'cansoft-order-info-send'); ?></label>
                        </th>
                        <td>
                            <select name="cansoft_order_info_store_type" id="cansoft_order_info_store_type">
                                <option value="auto" <?php selected($store_type, 'auto'); ?>><?php esc_html_e('Auto-Detect (WooCommerce / Ecwid)', 'cansoft-order-info-send'); ?></option>
                                <option value="woocommerce" <?php selected($store_type, 'woocommerce'); ?>><?php esc_html_e('WooCommerce', 'cansoft-order-info-send'); ?></option>
                                <option value="ecwid" <?php selected($store_type, 'ecwid'); ?>><?php esc_html_e('Ecwid by Lightspeed', 'cansoft-order-info-send'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="cansoft-ecwid-field">
                        <th scope="row">
                            <label for="cansoft_order_info_ecwid_store_id"><?php esc_html_e('Ecwid Store ID', 'cansoft-order-info-send'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="cansoft_order_info_ecwid_store_id" id="cansoft_order_info_ecwid_store_id" value="<?php echo esc_attr($ecwid_id); ?>" class="regular-text" placeholder="e.g. 12345678">
                            <p class="description"><?php esc_html_e('Leave empty to auto-detect from installed Ecwid plugin.', 'cansoft-order-info-send'); ?></p>
                        </td>
                    </tr>
                    <tr class="cansoft-ecwid-field">
                        <th scope="row">
                            <label for="cansoft_order_info_ecwid_token"><?php esc_html_e('Ecwid API Access Token', 'cansoft-order-info-send'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="cansoft_order_info_ecwid_token" id="cansoft_order_info_ecwid_token" value="<?php echo esc_attr($ecwid_tok); ?>" class="regular-text" autocomplete="off">
                            <p class="description"><?php esc_html_e('Leave empty to auto-detect from installed Ecwid plugin.', 'cansoft-order-info-send'); ?></p>
                        </td>
                    </tr>
                </table>
                <div style="display: flex; align-items: center; gap: 15px; margin-top: 20px; flex-wrap: wrap;">
                    <?php submit_button('', 'primary', 'submit', false); ?>
                    <button type="button" id="cansoft-test-store-btn" class="button button-secondary"><?php esc_html_e('Test Store Connection', 'cansoft-order-info-send'); ?></button>
                    <span id="cansoft-test-store-result" style="font-weight: 600;"></span>
                </div>
            </form>
            <script>
            jQuery(document).ready(function($) {
                $('#cansoft-test-store-btn').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $res = $('#cansoft-test-store-result');
                    $btn.prop('disabled', true).text('Testing Store Connection...');
                    $res.text('').css('color', '#666');

                    $.post(ajaxurl, {
                        action: 'cansoft_order_info_test_store',
                        store_type: $('#cansoft_order_info_store_type').val(),
                        store_id: $('#cansoft_order_info_ecwid_store_id').val(),
                        token: $('#cansoft_order_info_ecwid_token').val(),
                        _wpnonce: '<?php echo wp_create_nonce("cansoft_order_info_test_store_nonce"); ?>'
                    }, function(resp) {
                        $btn.prop('disabled', false).text('Test Store Connection');
                        if (resp.success) {
                            $res.text(resp.data.message).css('color', '#135e96');
                        } else {
                            $res.text(resp.data.message || 'Connection failed.').css('color', '#d63638');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_test_store_connection() {
        check_ajax_referer('cansoft_order_info_test_store_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cansoft-order-info-send')]);
        }

        $store_type = isset($_POST['store_type']) ? sanitize_text_field(wp_unslash($_POST['store_type'])) : 'auto';
        $store_id   = isset($_POST['store_id']) ? sanitize_text_field(wp_unslash($_POST['store_id'])) : '';
        $token      = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (empty($store_id)) {
            $store_id = get_option('cansoft_order_info_ecwid_store_id', get_option('ecwid_store_id', ''));
        }
        if (empty($token)) {
            $token = get_option('cansoft_order_info_ecwid_token', get_option('ecwid_oauth_token', get_option('ecwid_api_secret_key', '')));
        }

        $is_ecwid = ($store_type === 'ecwid') || (!empty($store_id) && $store_type !== 'woocommerce');

        if ($is_ecwid) {
            if (empty($store_id) || empty($token)) {
                wp_send_json_error(['message' => __('Ecwid Store ID or Access Token is missing.', 'cansoft-order-info-send')]);
            }

            $url = 'https://app.ecwid.com/api/v3/' . rawurlencode($store_id) . '/orders?token=' . rawurlencode($token) . '&limit=1';
            $response = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => __('Ecwid API Error: ', 'cansoft-order-info-send') . $response->get_error_message()]);
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code === 200) {
                $data = json_decode($body, true);
                $count = isset($data['total']) ? intval($data['total']) : 0;
                wp_send_json_success(['message' => sprintf(__('Ecwid Connection Successful! Connected to Store ID %s (Total Orders: %d)', 'cansoft-order-info-send'), $store_id, $count)]);
            }

            $data = json_decode($body, true);
            $err = isset($data['errorMessage']) ? $data['errorMessage'] : ('HTTP ' . $code);
            wp_send_json_error(['message' => __('Ecwid API Error: ', 'cansoft-order-info-send') . $err]);
        } else {
            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('WooCommerce plugin is not active on this site.', 'cansoft-order-info-send')]);
            }

            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(order_id) FROM {$wpdb->prefix}wc_order_stats");
            wp_send_json_success(['message' => sprintf(__('WooCommerce Connection Successful! Total orders in database: %d', 'cansoft-order-info-send'), intval($count))]);
        }
    }

    public function ajax_test_connection() {
        check_ajax_referer('cansoft_order_info_test_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cansoft-order-info-send')]);
        }

        $secret = isset($_POST['secret']) ? sanitize_text_field(wp_unslash($_POST['secret'])) : '';
        $expected = get_option('cansoft_order_info_secret', get_option('cansoft_finance_secret', ''));

        if (empty($secret) || empty($expected) || !hash_equals($expected, $secret)) {
            wp_send_json_error(['message' => __('Invalid or missing secret key.', 'cansoft-order-info-send')]);
        }

        wp_send_json_success(['message' => __('Connection successful!', 'cansoft-order-info-send')]);
    }

    public function bypass_rest_auth_for_order_info($result) {
        if (is_wp_error($result)) {
            $route = '';
            if (isset($GLOBALS['wp']->query_vars['rest_route'])) {
                $route = $GLOBALS['wp']->query_vars['rest_route'];
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $route = $_SERVER['REQUEST_URI'];
            }

            if (strpos($route, 'cansoft-order-info/v1') !== false || strpos($route, 'cansoft-finance-management/v1') !== false) {
                return null;
            }
        }
        return $result;
    }

    public function register_rest_routes() {
        register_rest_route('cansoft-order-info/v1', 'sales-report', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sales_report_request'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('cansoft-finance-management/v1', 'sales-report', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sales_report_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_sales_report_request($request) {
        $secret = $request->get_header('X-Webhook-Secret');
        $expected = get_option('cansoft_order_info_secret', get_option('cansoft_finance_secret', ''));

        if (empty($expected)) {
            return new \WP_REST_Response(['message' => __('Webhook secret not configured on client site.', 'cansoft-order-info-send')], 503);
        }

        if (!hash_equals($expected, $secret)) {
            return new \WP_REST_Response(['message' => __('Invalid webhook secret.', 'cansoft-order-info-send')], 401);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $current_start  = isset($params['current_start']) ? sanitize_text_field($params['current_start']) : '';
        $current_end    = isset($params['current_end']) ? sanitize_text_field($params['current_end']) : '';
        $previous_start = isset($params['previous_start']) ? sanitize_text_field($params['previous_start']) : '';
        $previous_end   = isset($params['previous_end']) ? sanitize_text_field($params['previous_end']) : '';

        if (empty($current_start) || empty($current_end) || empty($previous_start) || empty($previous_end)) {
            return new \WP_REST_Response(['message' => __('Missing date parameters.', 'cansoft-order-info-send')], 400);
        }

        $current_data  = $this->get_sales_metrics_for_period($current_start, $current_end, $params);
        $previous_data = $this->get_sales_metrics_for_period($previous_start, $previous_end, $params);

        return new \WP_REST_Response([
            'current'  => $current_data,
            'previous' => $previous_data,
        ], 200);
    }

    protected function get_sales_metrics_for_period($start_date, $end_date, $request_params = []) {
        $store_type = get_option('cansoft_order_info_store_type', 'auto');
        if (!empty($request_params['store_type'])) {
            $store_type = sanitize_text_field($request_params['store_type']);
        }

        $ecwid_id = get_option('cansoft_order_info_ecwid_store_id', get_option('ecwid_store_id', ''));
        $is_ecwid = ($store_type === 'ecwid') || (!empty($ecwid_id) && $store_type !== 'woocommerce');

        if ($is_ecwid) {
            return $this->get_ecwid_sales_metrics_for_period($start_date, $end_date, $request_params);
        }

        return $this->get_woocommerce_sales_metrics_for_period($start_date, $end_date);
    }

    protected function get_woocommerce_sales_metrics_for_period($start_date, $end_date) {
        global $wpdb;

        $start = $start_date . ' 00:00:00';
        $end   = $end_date . ' 23:59:59';

        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(total_sales) as total_sales,
                COUNT(order_id) as total_orders,
                SUM(num_items_sold) as total_items_sold
            FROM {$wpdb->prefix}wc_order_stats
            WHERE date_created >= %s 
              AND date_created <= %s
              AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')",
            $start,
            $end
        ), ARRAY_A);

        $total_sales      = isset($summary['total_sales']) ? floatval($summary['total_sales']) : 0;
        $total_orders     = isset($summary['total_orders']) ? intval($summary['total_orders']) : 0;
        $total_items_sold = isset($summary['total_items_sold']) ? intval($summary['total_items_sold']) : 0;
        $avg_order_value  = $total_orders > 0 ? ($total_sales / $total_orders) : 0;

        $status_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(order_id) as count
            FROM {$wpdb->prefix}wc_order_stats
            WHERE date_created >= %s 
              AND date_created <= %s
            GROUP BY status",
            $start,
            $end
        ), ARRAY_A);

        $status_counts = [
            'completed'  => 0,
            'failed'     => 0,
            'cancelled'  => 0,
            'refunded'   => 0,
            'on_hold'    => 0,
            'processing' => 0,
        ];

        foreach ($status_rows as $row) {
            $raw_status = str_replace('wc-', '', $row['status']);
            $status_key = str_replace('-', '_', $raw_status);

            if (array_key_exists($status_key, $status_counts)) {
                $status_counts[$status_key] = intval($row['count']);
            }
        }

        return [
            'total_sales'      => round($total_sales, 2),
            'total_orders'     => $total_orders,
            'total_items_sold' => $total_items_sold,
            'avg_order_value'  => round($avg_order_value, 2),
            'status_counts'    => $status_counts,
        ];
    }

    protected function get_ecwid_sales_metrics_for_period($start_date, $end_date, $request_params = []) {
        $store_id = !empty($request_params['ecwid_store_id']) ? sanitize_text_field($request_params['ecwid_store_id']) : get_option('cansoft_order_info_ecwid_store_id', get_option('ecwid_store_id', ''));
        
        $tokens_to_try = array_values(array_unique(array_filter([
            !empty($request_params['ecwid_token']) ? sanitize_text_field($request_params['ecwid_token']) : '',
            get_option('cansoft_order_info_ecwid_token', ''),
            get_option('ecwid_oauth_token', ''),
            get_option('ecwid_api_secret_key', ''),
            get_option('ecwid_api_certificate', ''),
            get_option('ecwid_public_token', ''),
        ])));

        if (empty($store_id) || empty($tokens_to_try)) {
            CANSOFT_Order_Info_Sender::log('Ecwid fetch aborted: Store ID or Token is empty', [
                'store_id'  => $store_id,
                'has_tokens' => !empty($tokens_to_try)
            ]);
            return [
                'total_sales'      => 0,
                'total_orders'     => 0,
                'total_items_sold' => 0,
                'avg_order_value'  => 0,
                'status_counts'    => [
                    'completed' => 0, 'failed' => 0, 'cancelled' => 0, 'refunded' => 0, 'on_hold' => 0, 'processing' => 0
                ],
                'debug_error'      => 'Missing Store ID or Token',
            ];
        }

        $all_orders = [];
        $debug_log = [];

        $ts_start = strtotime($start_date . ' 00:00:00');
        $ts_end   = strtotime($end_date . ' 23:59:59');

        $date_formats = [
            ['from' => (string)$ts_start,               'to' => (string)$ts_end],
            ['from' => (string)($ts_start * 1000),      'to' => (string)($ts_end * 1000)],
            ['from' => $start_date . 'T00:00:00Z',     'to' => $end_date . 'T23:59:59Z'],
            ['from' => $start_date . ' 00:00:00',      'to' => $end_date . ' 23:59:59'],
            ['from' => $start_date,                     'to' => $end_date],
        ];

        foreach ($tokens_to_try as $token) {
            foreach ($date_formats as $df) {
                $offset = 0;
                $limit  = 100;
                $batch_orders = [];

                do {
                    // Method 1: Query string token
                    $url = sprintf(
                        'https://app.ecwid.com/api/v3/%s/orders?token=%s&createdFrom=%s&createdTo=%s&offset=%d&limit=%d',
                        rawurlencode($store_id),
                        rawurlencode($token),
                        rawurlencode($df['from']),
                        rawurlencode($df['to']),
                        $offset,
                        $limit
                    );

                    $response = wp_remote_get($url, [
                        'timeout' => 20,
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]);

                    // Method 2: If 403, try secret_token query parameter
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 403) {
                        $url_secret = sprintf(
                            'https://app.ecwid.com/api/v3/%s/orders?secret_token=%s&createdFrom=%s&createdTo=%s&offset=%d&limit=%d',
                            rawurlencode($store_id),
                            rawurlencode($token),
                            rawurlencode($df['from']),
                            rawurlencode($df['to']),
                            $offset,
                            $limit
                        );
                        $response_alt = wp_remote_get($url_secret, [
                            'timeout' => 20,
                            'headers' => ['Accept' => 'application/json'],
                        ]);
                        if (!is_wp_error($response_alt) && wp_remote_retrieve_response_code($response_alt) === 200) {
                            $response = $response_alt;
                        }
                    }

                    if (is_wp_error($response)) {
                        $debug_log[] = 'WP_Error: ' . $response->get_error_message();
                        break;
                    }

                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);

                    if ($code !== 200) {
                        $debug_log[] = "HTTP {$code}: " . substr(strip_tags($body), 0, 150);
                        break;
                    }

                    $data = json_decode($body, true);
                    if (!is_array($data)) {
                        $debug_log[] = 'Invalid JSON response';
                        break;
                    }

                    $items_count = (!empty($data['items']) && is_array($data['items'])) ? count($data['items']) : 0;
                    $debug_log[] = "Fetched {$items_count} orders";

                    if ($items_count === 0) {
                        break;
                    }

                    $items = $data['items'];
                    $batch_orders = array_merge($batch_orders, $items);
                    $total_count = isset($data['total']) ? intval($data['total']) : count($batch_orders);

                    $offset += count($items);
                } while ($offset < $total_count && $items_count >= $limit);

                if (!empty($batch_orders)) {
                    $all_orders = $batch_orders;
                    break 2; // Break both date_formats and tokens_to_try
                }
            }
        }

        // Final Fallback: Fetch recent 100 orders without createdFrom/createdTo and filter in PHP
        if (empty($all_orders)) {
            foreach ($tokens_to_try as $token) {
                $url = sprintf(
                    'https://app.ecwid.com/api/v3/%s/orders?token=%s&limit=100',
                    rawurlencode($store_id),
                    rawurlencode($token)
                );

                $response = wp_remote_get($url, [
                    'timeout' => 20,
                    'headers' => ['Accept' => 'application/json'],
                ]);

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (!empty($data['items']) && is_array($data['items'])) {
                        $start_ts = strtotime($start_date . ' 00:00:00');
                        $end_ts   = strtotime($end_date . ' 23:59:59');

                        foreach ($data['items'] as $order) {
                            $order_time = 0;
                            if (!empty($order['createTimestamp'])) {
                                $order_time = floatval($order['createTimestamp']);
                                if ($order_time > 20000000000) {
                                    $order_time = intval($order_time / 1000);
                                }
                            } elseif (!empty($order['created'])) {
                                $order_time = strtotime($order['created']);
                            } elseif (!empty($order['createDate'])) {
                                $order_time = strtotime($order['createDate']);
                            }

                            if ($order_time >= $start_ts && $order_time <= $end_ts) {
                                $all_orders[] = $order;
                            }
                        }
                        $debug_log[] = 'PHP filtered orders count: ' . count($all_orders) . ' out of ' . count($data['items']);
                        if (!empty($all_orders)) {
                            break;
                        }
                    }
                }
            }
        }

        $total_sales      = 0;
        $total_orders     = count($all_orders);
        $total_items_sold = 0;
        $status_counts    = [
            'completed'  => 0,
            'failed'     => 0,
            'cancelled'  => 0,
            'refunded'   => 0,
            'on_hold'    => 0,
            'processing' => 0,
        ];

        foreach ($all_orders as $order) {
            $payment_status     = isset($order['paymentStatus']) ? strtoupper((string) $order['paymentStatus']) : '';
            $fulfillment_status = isset($order['fulfillmentStatus']) ? strtoupper((string) $order['fulfillmentStatus']) : '';
            $total              = isset($order['total']) ? floatval($order['total']) : 0;

            if ($payment_status === 'PAID') {
                $total_sales += $total;
            }

            if (!empty($order['items']) && is_array($order['items'])) {
                foreach ($order['items'] as $item) {
                    $qty = isset($item['quantity']) ? intval($item['quantity']) : 1;
                    $total_items_sold += $qty;
                }
            }

            if ($payment_status === 'PAID') {
                if (in_array($fulfillment_status, ['AWAITING_PROCESSING', 'PROCESSING'])) {
                    $status_counts['processing']++;
                } else {
                    $status_counts['completed']++;
                }
            } elseif ($payment_status === 'AWAITING_PAYMENT' || $payment_status === 'INCOMPLETE') {
                $status_counts['on_hold']++;
            } elseif ($payment_status === 'CANCELLED') {
                $status_counts['cancelled']++;
            } elseif ($payment_status === 'REFUNDED' || $payment_status === 'PARTIALLY_REFUNDED') {
                $status_counts['refunded']++;
            } elseif ($payment_status === 'DECLINED') {
                $status_counts['failed']++;
            } else {
                $status_counts['completed']++;
            }
        }

        $avg_order_value = $total_orders > 0 ? ($total_sales / $total_orders) : 0;

        return [
            'total_sales'      => round($total_sales, 2),
            'total_orders'     => $total_orders,
            'total_items_sold' => $total_items_sold,
            'avg_order_value'  => round($avg_order_value, 2),
            'status_counts'    => $status_counts,
            'debug_log'        => $debug_log,
        ];
    }
}
