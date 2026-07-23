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
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $secret = get_option('cansoft_order_info_secret', get_option('cansoft_finance_secret', ''));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p class="description"><?php esc_html_e('Provides REST API endpoints for the Cansoft Report System on the main site to fetch sales and order metrics on demand.', 'cansoft-order-info-send'); ?></p>
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
                </table>
                <div style="display: flex; align-items: center; gap: 15px; margin-top: 20px;">
                    <?php submit_button('', 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
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
        // Primary endpoint for Cansoft Order Info
        register_rest_route('cansoft-order-info/v1', 'sales-report', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sales_report_request'],
            'permission_callback' => '__return_true',
        ]);

        // Alias for backward compatibility
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
        $current_start = isset($params['current_start']) ? sanitize_text_field($params['current_start']) : '';
        $current_end = isset($params['current_end']) ? sanitize_text_field($params['current_end']) : '';
        $previous_start = isset($params['previous_start']) ? sanitize_text_field($params['previous_start']) : '';
        $previous_end = isset($params['previous_end']) ? sanitize_text_field($params['previous_end']) : '';

        if (empty($current_start) || empty($current_end) || empty($previous_start) || empty($previous_end)) {
            return new \WP_REST_Response(['message' => __('Missing date parameters.', 'cansoft-order-info-send')], 400);
        }

        $current_data = $this->get_sales_metrics_for_period($current_start, $current_end);
        $previous_data = $this->get_sales_metrics_for_period($previous_start, $previous_end);

        return new \WP_REST_Response([
            'current' => $current_data,
            'previous' => $previous_data,
        ], 200);
    }

    protected function get_sales_metrics_for_period($start_date, $end_date) {
        global $wpdb;

        $start = $start_date . ' 00:00:00';
        $end = $end_date . ' 23:59:59';

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

        $total_sales = isset($summary['total_sales']) ? floatval($summary['total_sales']) : 0;
        $total_orders = isset($summary['total_orders']) ? intval($summary['total_orders']) : 0;
        $total_items_sold = isset($summary['total_items_sold']) ? intval($summary['total_items_sold']) : 0;
        $avg_order_value = $total_orders > 0 ? ($total_sales / $total_orders) : 0;

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
            'total_sales'      => $total_sales,
            'total_orders'     => $total_orders,
            'total_items_sold' => $total_items_sold,
            'avg_order_value'  => $avg_order_value,
            'status_counts'    => $status_counts,
        ];
    }
}
