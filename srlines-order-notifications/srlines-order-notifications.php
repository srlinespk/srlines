<?php
/*
 * Plugin Name: SRLines Order Notifications
 * Plugin URI: https://crm.srlines.net
 * Description: Send order notifications via Meta API and process customer confirmations/cancellations. Full HPOS compatible.
 * Version: 5.1.0
 * Author: SRLINES SOFTWARE HOUSE (SMC-PRIVATE) LIMITED
 * Author URI: https://srlines.net
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * WC requires at least: 9.3
 * WC tested up to: 10.5.1
 * Text Domain: srlines-order-notifications
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SRLIORNO_Plugin {
    
    private static $instance = null;
    private $logger;
    private $crm_api_url = 'https://crm.srlines.net/api/v1/send_templet';
    private $db_version = '5.1.0';
    private $option_name = 'srliorno_settings';
    private $table_name;
    private $context_table;
    private $responses_table;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'srliorno_notifications';
        $this->context_table = $wpdb->prefix . 'srliorno_context';
        $this->responses_table = $wpdb->prefix . 'srliorno_responses';
        
        $this->init_logger();
        $this->register_hooks();
    }

    private function init_logger() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/srlines-order-notifications-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect log directory with an index.php file
            if (!file_exists($log_dir . '/index.php')) {
                file_put_contents($log_dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
        }

        $this->logger = new class($log_dir . '/srliorno.log') {
            private $log_file;

            public function __construct($log_file) {
                $this->log_file = $log_file;
            }

            public function info($message, $context = []) {
                $this->write('INFO', $message, $context);
            }

            public function error($message, $context = []) {
                $this->write('ERROR', $message, $context);
            }

            public function warn($message, $context = []) {
                $this->write('WARN', $message, $context);
            }

            private function write($level, $message, $context) {
                $log_entry = sprintf(
                    "[%s] %s: %s %s\n",
                    gmdate('Y-m-d H:i:s'),
                    $level,
                    $message,
                    !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
                );
                file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
            }
        };
    }

    private function register_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Core hooks
        add_action('plugins_loaded', [$this, 'check_woocommerce']);
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        
        // WooCommerce order hooks
        add_action('woocommerce_order_status_pending_to_processing', [$this, 'handle_order_created'], 10, 2);
        add_action('woocommerce_order_status_on-hold_to_processing', [$this, 'handle_order_created'], 10, 2);
        add_action('woocommerce_order_status_failed_to_processing', [$this, 'handle_order_created'], 10, 2);
        add_action('woocommerce_order_status_completed', [$this, 'handle_fulfillment'], 10, 1);
        
        // Admin menu and pages
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_srliorno_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_srliorno_test_api_key', [$this, 'ajax_test_api_key']);
        add_action('wp_ajax_srliorno_process_response', [$this, 'ajax_process_response']);
        add_action('wp_ajax_srliorno_resend_notification', [$this, 'ajax_resend_notification']);
        
        // REST API for customer responses - EXACT same format as Shopify app
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Cleanup schedule
        add_action('srliorno_cleanup', [$this, 'cleanup_old_records']);
    }

    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
        }
    }

    public function activate() {
        $this->create_tables();
        $this->create_assets_dir();
        
        if (!wp_next_scheduled('srliorno_cleanup')) {
            wp_schedule_event(time(), 'daily', 'srliorno_cleanup');
        }
        
        update_option('srliorno_db_version', $this->db_version);
        
        $this->logger->info('🚀 Plugin activated', [
            'version' => $this->db_version,
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown'
        ]);
    }

    public function deactivate() {
        wp_clear_scheduled_hook('srliorno_cleanup');
        $this->logger->info('📴 Plugin deactivated');
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Notifications table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            shop varchar(255) NOT NULL,
            order_id varchar(100) NOT NULL,
            event_type varchar(50) NOT NULL,
            phone varchar(50) NOT NULL,
            customer_name varchar(255) DEFAULT '',
            tracking_number varchar(255) DEFAULT '',
            tracking_url text DEFAULT '',
            order_amount decimal(10,2) DEFAULT 0.00,
            product_names text DEFAULT '',
            status varchar(20) DEFAULT 'queued',
            crm_response longtext DEFAULT NULL,
            error text DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_shop (shop(100)),
            KEY idx_order_id (order_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        // Context table for msg_id tracking
        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->context_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            msg_id varchar(255) NOT NULL,
            from_number varchar(50) NOT NULL,
            customer_phone varchar(50) NOT NULL,
            order_id varchar(100) NOT NULL,
            shop varchar(255) NOT NULL,
            action varchar(20) DEFAULT NULL,
            processed tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_msg_id (msg_id(191)),
            KEY idx_customer_phone (customer_phone),
            KEY idx_order_id (order_id),
            KEY idx_processed (processed)
        ) $charset_collate;";

        // Responses table for customer confirmations/cancellations
        $sql3 = "CREATE TABLE IF NOT EXISTS {$this->responses_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id varchar(100) NOT NULL,
            shop varchar(255) NOT NULL,
            action varchar(20) NOT NULL,
            msg_id varchar(255) NOT NULL,
            customer_phone varchar(50) NOT NULL,
            processed tinyint(1) DEFAULT 0,
            retry_count int DEFAULT 0,
            error_message text DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_processed (processed),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    private function create_assets_dir() {
        $dirs = [
            plugin_dir_path(__FILE__) . 'assets'
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }
    }

    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>SRLines Order Notifications</strong> requires WooCommerce to be installed and activated.</p></div>';
            });
            return false;
        }
        return true;
    }

    public function add_admin_menu() {
        add_menu_page(
            'Order Notifications',
            'Order Notif',
            'manage_woocommerce',
            'srliorno',
            [$this, 'render_dashboard'],
            'dashicons-format-chat',
            56
        );
        
        add_submenu_page(
            'srliorno',
            'Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'srliorno',
            [$this, 'render_dashboard']
        );
        
        add_submenu_page(
            'srliorno',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'srliorno-settings',
            [$this, 'render_settings']
        );
        
        add_submenu_page(
            'srliorno',
            'Order Responses',
            'Order Responses',
            'manage_woocommerce',
            'srliorno-responses',
            [$this, 'render_responses']
        );
        
        add_submenu_page(
            'srliorno',
            'Notifications',
            'Notifications',
            'manage_woocommerce',
            'srliorno-notifications',
            [$this, 'render_notifications']
        );
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'srliorno') === false) {
            return;
        }
        
        wp_enqueue_style(
            'srliorno-style',
            plugin_dir_url(__FILE__) . 'assets/style.css',
            [],
            $this->db_version
        );
        
        wp_enqueue_script(
            'srliorno-script',
            plugin_dir_url(__FILE__) . 'assets/script.js',
            ['jquery'],
            $this->db_version,
            true
        );
        
        $settings = get_option($this->option_name, []);

        wp_localize_script('srliorno-script', 'srliorno_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'webhook_url' => rest_url('srliorno/v1/customer-response'),
            'nonce' => wp_create_nonce('srliorno_nonce'),
            'site_url' => get_site_url(),
            'webhook_secret' => $settings['notificationSettings']['webhookSecret'] ?? ''
        ]);
    }

    public function show_admin_notices() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'srliorno') === false) {
            return;
        }

        $settings = get_option($this->option_name, []);
        if (empty($settings['notificationSettings']['crmApiKey'])) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>📱 Order Notifications:</strong> Please configure your Meta API key in <a href="' . esc_url( admin_url('admin.php?page=srliorno-settings') ) . '">settings</a> to start sending notifications.</p>';
            echo '</div>';
        }
    }

    /**
     * ============ REST API - EXACT MATCH WITH SHOPIFY APP ============
     * Endpoint: POST /wp-json/srliorno/v1/customer-response
     * Payload: {"message": "0", "from": "+923001234567", "msg_id": "wamid.xxx"}
     */
    public function register_rest_routes() {
        register_rest_route('srliorno/v1', '/customer-response', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_customer_response'],
            'permission_callback' => [$this, 'verify_webhook_secret'],
            'args' => [
                'message' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Customer response (0=Confirm, 1=Cancel)'
                ],
                'from' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Customer phone number'
                ],
                'msg_id' => [
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Message ID from Meta API'
                ]
            ]
        ]);
    }

    /**
     * Verify webhook secret for REST API authentication
     */
    public function verify_webhook_secret($request) {
        $settings = get_option($this->option_name, []);
        $webhook_secret = $settings['notificationSettings']['webhookSecret'] ?? '';
        
        if (empty($webhook_secret)) {
            return new WP_Error(
                'rest_forbidden',
                __('Webhook secret is not configured.', 'srlines-order-notifications'),
                ['status' => 403]
            );
        }
        
        $provided_secret = $request->get_header('X-Webhook-Secret');
        
        if (empty($provided_secret) || !hash_equals($webhook_secret, $provided_secret)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid webhook secret.', 'srlines-order-notifications'),
                ['status' => 403]
            );
        }
        
        return true;
    }

    /**
     * Handle customer response - EXACT same logic as Shopify app
     */
    public function handle_customer_response($request) {
        $params = $request->get_json_params();
        
        $message = $params['message'] ?? '';
        $from = $params['from'] ?? '';
        $msg_id = $params['msg_id'] ?? null;

        $this->logger->info('📥 Customer response received', [
            'message' => $message,
            'from' => $from ? substr($from, 0, 6) . '...' : 'missing',
            'msg_id' => $msg_id ? substr($msg_id, -8) : 'null'
        ]);

        if (empty($message) || empty($from)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Missing required fields'
            ], 400);
        }

        $result = $this->process_customer_response($message, $from, $msg_id);

        return new WP_REST_Response([
            'success' => $result['success'],
            'order_id' => $result['order_id'] ?? null,
            'action' => $result['action'] ?? null,
            'message' => $result['message'] ?? 'Response processed'
        ], $result['success'] ? 200 : 404);
    }

    /**
     * Process customer response and update order status
     */
    private function process_customer_response($message, $from, $msg_id = null) {
        global $wpdb;
        
        // Normalize phone number
        $normalized_phone = $this->normalize_phone_number($from);
        
        // Determine action from response (0 = confirm, 1 = cancel)
        $action = null;
        $response_lower = strtolower(trim($message));
        
        if ($response_lower === '0' || $response_lower === 'confirm' || $response_lower === 'yes' || strpos($response_lower, 'confirm') !== false) {
            $action = 'confirmed';
        } elseif ($response_lower === '1' || $response_lower === 'cancel' || $response_lower === 'no' || strpos($response_lower, 'cancel') !== false) {
            $action = 'cancelled';
        }

        if (!$action) {
            $this->logger->info('ℹ️ Unrecognized response', ['text' => $message]);
            return ['success' => false, 'error' => 'Unrecognized response'];
        }

        // Find order by msg_id first (most accurate)
        $order_id = null;
        $shop = home_url();

        if ($msg_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $context = $wpdb->get_row($wpdb->prepare(
                "SELECT order_id FROM %i WHERE msg_id = %s LIMIT 1",
                $this->context_table,
                $msg_id
            ));
            
            if ($context) {
                $order_id = $context->order_id;
                $this->logger->info('✅ Found order by msg_id', [
                    'msg_id' => substr($msg_id, -8),
                    'order_id' => $order_id
                ]);
            }
        }

        // If no msg_id match, try phone number (last 24 hours)
        if (!$order_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $context = $wpdb->get_row($wpdb->prepare(
                "SELECT order_id FROM %i 
                 WHERE customer_phone = %s AND processed = 0 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY created_at DESC LIMIT 1",
                $this->context_table,
                $normalized_phone
            ));
            
            if ($context) {
                $order_id = $context->order_id;
                $this->logger->info('✅ Found order by phone', [
                    'phone' => substr($normalized_phone, 0, 6) . '...',
                    'order_id' => $order_id
                ]);
            }
        }

        if (!$order_id) {
            $this->logger->warn('⚠️ No matching order found');
            return ['success' => false, 'error' => 'No matching order found'];
        }

        // Check if already processed
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i 
             WHERE order_id = %s AND action = %s AND processed = 1 
             LIMIT 1",
            $this->responses_table,
            $order_id,
            $action
        ));

        if ($existing) {
            return ['success' => true, 'order_id' => $order_id, 'action' => $action, 'message' => 'Already processed'];
        }

        // Store the response
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $this->responses_table,
            [
                'order_id' => $order_id,
                'shop' => $shop,
                'action' => $action,
                'msg_id' => $msg_id ?: '',
                'customer_phone' => $normalized_phone,
                'processed' => 0,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        $response_id = $wpdb->insert_id;

        // Update context as processed
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $this->context_table,
            ['processed' => 1, 'action' => $action, 'updated_at' => current_time('mysql')],
            ['order_id' => $order_id],
            ['%d', '%s', '%s'],
            ['%s']
        );

        // Update WooCommerce order status
        $order_updated = $this->update_order_status($order_id, $action, $msg_id);

        if ($order_updated) {
            // Mark response as processed
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $this->responses_table,
                [
                    'processed' => 1,
                    'processed_at' => current_time('mysql'),
                    'error_message' => null
                ],
                ['id' => $response_id],
                ['%d', '%s', '%s'],
                ['%d']
            );
        }

        return [
            'success' => $order_updated,
            'order_id' => $order_id,
            'action' => $action,
            'message' => $order_updated ? 'Order status updated successfully' : 'Failed to update order status'
        ];
    }

    /**
     * Update WooCommerce order status
     */
    private function update_order_status($order_id, $action, $msg_id = null) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->logger->error('Order not found', ['order_id' => $order_id]);
            return false;
        }

        try {
            $msg_suffix = $msg_id ? ' (Msg: ' . substr($msg_id, -8) . ')' : '';

            if ($action === 'confirmed') {
                $current_status = $order->get_status();
                if (in_array($current_status, ['pending', 'on-hold', 'checkout-draft', 'processing'])) {
                    $order->update_status('processing', '✅ Customer confirmed order via WhatsApp' . $msg_suffix);
                    $order->add_order_note(
                        sprintf('✅ Customer CONFIRMED order via WhatsApp. Message ID: %s', $msg_id ?: 'N/A'),
                        false,
                        true
                    );
                    $this->logger->info('✅ Order confirmed', ['order_id' => $order_id]);
                }
            } elseif ($action === 'cancelled') {
                $order->update_status('cancelled', '❌ Customer cancelled order via WhatsApp' . $msg_suffix);
                
                if (function_exists('wc_maybe_increase_stock_levels')) {
                    wc_maybe_increase_stock_levels($order);
                }
                
                $order->add_order_note(
                    sprintf('❌ Customer CANCELLED order via WhatsApp. Message ID: %s', $msg_id ?: 'N/A'),
                    false,
                    true
                );
                
                $this->logger->info('✅ Order cancelled', ['order_id' => $order_id]);
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('❌ Failed to update order', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * ============ ORDER EVENT HANDLERS ============
     */
    public function handle_order_created($order_id, $order) {
        $this->send_notification($order_id, 'orderCreated', $order);
    }

    public function handle_fulfillment($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $this->send_notification($order_id, 'fulfillmentCreated', $order);
        }
    }

    /**
     * Send WhatsApp notification via Meta API
     */
    private function send_notification($order_id, $event_type, $order) {
        if (!$this->check_woocommerce()) {
            return;
        }

        $settings = get_option($this->option_name, []);
        $notification_settings = $settings['notificationSettings'] ?? [];

        // Check if enabled
        if (empty($notification_settings[$event_type]['enabled'])) {
            $this->logger->info("{$event_type} disabled", ['order_id' => $order_id]);
            return;
        }

        // Get API key
        $crm_api_key = $notification_settings['crmApiKey'] ?? '';
        if (empty($crm_api_key)) {
            $this->logger->error('CRM API key not configured', ['order_id' => $order_id]);
            return;
        }

        // Get customer phone
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            $phone = $notification_settings['defaultPhone'] ?? '+923339776136';
        }

        $phone = $this->normalize_phone_number($phone);
        if (!$phone) {
            $this->logger->error('Invalid phone number', ['order_id' => $order_id]);
            return;
        }

        // Get order details
        $customer_name = $order->get_billing_first_name() ?: 'Customer';
        $order_number = $order->get_order_number();
        $order_amount = $order->get_total();
        $currency = $order->get_currency();
        $product_names = $this->extract_product_names($order->get_items());

        // Build example array for Meta template
        $example_arr = [
            $customer_name,
            '#' . $order_number,
            '', // tracking number
            $product_names,
            $currency . ' ' . $order_amount
        ];

        // Get template name
        $template_name = $notification_settings[$event_type]['templateName'] ?? 
                        ($event_type === 'orderCreated' ? 'confirm_order' : 'confirm_fulfill');

        global $wpdb;
        
        $current_time = current_time('mysql');
        $shop_url = home_url();

        // Check if notification already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i 
             WHERE shop = %s AND order_id = %s AND event_type = %s",
            $this->table_name,
            $shop_url,
            '#' . $order_number,
            $event_type
        ));

        $notification_data = [
            'shop' => $shop_url,
            'order_id' => '#' . $order_number,
            'event_type' => $event_type,
            'phone' => $phone,
            'customer_name' => $customer_name,
            'tracking_number' => '',
            'tracking_url' => '',
            'order_amount' => $order_amount,
            'product_names' => $product_names,
            'status' => 'queued',
            'crm_response' => null,
            'error' => null,
            'sent_at' => null,
            'updated_at' => $current_time
        ];

        if ($event_type === 'fulfillmentCreated') {
            $tracking_items = $this->get_tracking_items($order);
            if (!empty($tracking_items)) {
                $notification_data['tracking_number'] = $tracking_items[0]['tracking_number'] ?? '';
                $notification_data['tracking_url'] = $tracking_items[0]['tracking_url'] ?? '';
                $example_arr[2] = $notification_data['tracking_number'];
            }
        }

        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $this->table_name,
                $notification_data,
                ['id' => $existing],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $notification_id = $existing;
        } else {
            $notification_data['created_at'] = $current_time;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $this->table_name,
                $notification_data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $notification_id = $wpdb->insert_id;
        }

        if ($notification_id) {
            $this->logger->info('📤 Sending notification', [
                'notification_id' => $notification_id,
                'order' => '#' . $order_number,
                'template' => $template_name
            ]);

            $this->call_crm_api($notification_id, $phone, $template_name, $example_arr, $crm_api_key, $order_number, $order);
        }
    }

    /**
     * Call CRM API to send message
     */
    private function call_crm_api($notification_id, $phone, $template_name, $example_arr, $crm_api_key, $order_number, $order) {
        global $wpdb;

        try {
            $response = wp_remote_post($this->crm_api_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $crm_api_key
                ],
                'body' => json_encode([
                    'sendTo' => $phone,
                    'templetName' => $template_name,
                    'exampleArr' => $example_arr,
                    'token' => $crm_api_key,
                    'mediaUri' => ''
                ]),
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode($response['body'], true);
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 200 && isset($body['success']) && $body['success'] === true) {
                // Update notification as sent
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $this->table_name,
                    [
                        'status' => 'sent',
                        'crm_response' => json_encode($body),
                        'sent_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $notification_id],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );

                // Extract msg_id from response
                $msg_id = null;
                if (isset($body['metaResponse']['messages'][0]['id'])) {
                    $msg_id = $body['metaResponse']['messages'][0]['id'];
                } elseif (isset($body['messages'][0]['id'])) {
                    $msg_id = $body['messages'][0]['id'];
                } elseif (isset($body['messageId'])) {
                    $msg_id = $body['messageId'];
                }

                // Store context for response matching
                if ($msg_id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM %i WHERE msg_id = %s",
                        $this->context_table,
                        $msg_id
                    ));

                    if (!$existing) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->insert(
                            $this->context_table,
                            [
                                'msg_id' => $msg_id,
                                'from_number' => 'meta_api',
                                'customer_phone' => $phone,
                                'order_id' => ltrim($order_number, '#'),
                                'shop' => home_url(),
                                'processed' => 0,
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql')
                            ],
                            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
                        );
                        
                        $this->logger->info('✅ Context stored', [
                            'order' => '#' . $order_number,
                            'msg_id' => substr($msg_id, -8)
                        ]);
                    }
                }

                $this->logger->info('✅ Notification sent successfully', [
                    'notification_id' => $notification_id,
                    'order' => '#' . $order_number
                ]);

            } else {
                $error_msg = $body['message'] ?? 'Unknown CRM error';
                throw new Exception('CRM API error: ' . $error_msg);
            }

        } catch (Exception $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $this->table_name,
                [
                    'status' => 'failed',
                    'error' => substr($e->getMessage(), 0, 255),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $notification_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            $this->logger->error('❌ Notification failed', [
                'notification_id' => $notification_id,
                'error' => $e->getMessage(),
                'order' => '#' . $order_number
            ]);
        }
    }

    /**
     * ============ AJAX HANDLERS ============
     */
    public function ajax_save_settings() {
        check_ajax_referer('srliorno_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $crm_api_key = sanitize_text_field( wp_unslash( $_POST['crmApiKey'] ?? '' ) );
        $order_created_enabled = isset($_POST['orderCreatedEnabled']) && $_POST['orderCreatedEnabled'] === 'true';
        $order_created_template = sanitize_text_field( wp_unslash( $_POST['orderCreatedTemplate'] ?? 'confirm_order' ) );
        $fulfillment_created_enabled = isset($_POST['fulfillmentCreatedEnabled']) && $_POST['fulfillmentCreatedEnabled'] === 'true';
        $fulfillment_created_template = sanitize_text_field( wp_unslash( $_POST['fulfillmentCreatedTemplate'] ?? 'confirm_fulfill' ) );
        $default_phone = sanitize_text_field( wp_unslash( $_POST['defaultPhone'] ?? '+923339776136' ) );
        $webhook_secret = sanitize_text_field( wp_unslash( $_POST['webhookSecret'] ?? '' ) );

        if (empty($crm_api_key)) {
            wp_send_json_error(['message' => 'CRM API key is required'], 400);
        }

        // Test API key
        $test_result = $this->test_api_key($crm_api_key);
        if (!$test_result['success']) {
            wp_send_json_error(['message' => $test_result['message']], 400);
        }

        $settings = [
            'notificationSettings' => [
                'crmApiKey' => $crm_api_key,
                'apiType' => 'meta',
                'orderCreated' => [
                    'enabled' => $order_created_enabled,
                    'templateName' => $order_created_template
                ],
                'fulfillmentCreated' => [
                    'enabled' => $fulfillment_created_enabled,
                    'templateName' => $fulfillment_created_template
                ],
                'defaultPhone' => $default_phone,
                'webhookSecret' => $webhook_secret,
            ],
            'updatedAt' => current_time('mysql')
        ];

        update_option($this->option_name, $settings);
        
        $this->logger->info('✅ Settings saved');
        wp_send_json_success(['message' => 'Settings saved successfully']);
    }

    public function ajax_test_api_key() {
        check_ajax_referer('srliorno_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['apiKey'] ?? '' ) );
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key required'], 400);
        }

        $result = $this->test_api_key($api_key);
        
        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']], 400);
        }
    }

    private function test_api_key($api_key) {
        try {
            $response = wp_remote_get("https://crm.srlines.net/api/v1/test?token=" . urlencode($api_key), [
                'headers' => ['Authorization' => 'Bearer ' . $api_key],
                'timeout' => 15,
                'sslverify' => false
            ]);

            if (is_wp_error($response)) {
                return ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
            }

            $body = json_decode($response['body'], true);
            
            if (isset($body['success']) && $body['success'] === true) {
                return ['success' => true, 'message' => '✅ API key is valid'];
            } else {
                return ['success' => false, 'message' => '❌ Invalid API key'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => '❌ Error: ' . $e->getMessage()];
        }
    }

    public function ajax_process_response() {
        check_ajax_referer('srliorno_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $response_id = intval($_POST['response_id'] ?? 0);
        
        if (!$response_id) {
            wp_send_json_error(['message' => 'Response ID required'], 400);
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $response = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->responses_table,
            $response_id
        ));

        if (!$response) {
            wp_send_json_error(['message' => 'Response not found'], 404);
        }

        if ($response->processed) {
            wp_send_json_error(['message' => 'Response already processed'], 400);
        }

        $success = $this->update_order_status($response->order_id, $response->action, $response->msg_id);

        if ($success) {
            // Mark as processed
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $this->responses_table,
                [
                    'processed' => 1,
                    'processed_at' => current_time('mysql'),
                    'error_message' => null
                ],
                ['id' => $response_id],
                ['%d', '%s', '%s'],
                ['%d']
            );
            
            wp_send_json_success(['message' => '✅ Order status updated successfully']);
        } else {
            wp_send_json_error(['message' => '❌ Failed to update order status'], 500);
        }
    }

    public function ajax_resend_notification() {
        check_ajax_referer('srliorno_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if (!$notification_id) {
            wp_send_json_error(['message' => 'Notification ID required'], 400);
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->table_name,
            $notification_id
        ));

        if (!$notification) {
            wp_send_json_error(['message' => 'Notification not found'], 404);
        }

        $order = wc_get_order(ltrim($notification->order_id, '#'));
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found'], 404);
        }

        $this->send_notification($order->get_id(), $notification->event_type, $order);
        wp_send_json_success(['message' => '✅ Notification resent successfully']);
    }

    /**
     * ============ RENDER FUNCTIONS ============
     */
    public function render_dashboard() {
        global $wpdb;
        
        // Get stats
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_notifications = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $this->table_name ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $sent_notifications = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'sent'", $this->table_name ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $failed_notifications = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed'", $this->table_name ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_responses = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $this->responses_table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $processed_responses = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE processed = 1", $this->responses_table ) );
        
        // Get recent responses
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $recent_responses = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC LIMIT 10", $this->responses_table )
        );
        
        $webhook_url = rest_url('srliorno/v1/customer-response');
        ?>
        <div class="wrap">
            <h1>📱 SRLines Order Notifications</h1>
            
            <div class="notice notice-info" style="border-left-color: #25d366;">
                <p><strong>📡 Webhook URL for CRM:</strong> <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 4px; font-size: 13px;"><?php echo esc_url($webhook_url); ?></code></p>
                <p><strong>📝 Payload format:</strong> <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 4px;">{"message": "0", "from": "+923001234567", "msg_id": "wamid.xxx"}</code></p>
                <p><small>Customer response: <strong>0 = Confirm</strong>, <strong>1 = Cancel</strong></small></p>
            </div>
            
            <div class="dashboard-grid">
                <!-- Stats Cards -->
                <div class="stat-card">
                    <div class="stat-icon">📨</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo esc_html($total_notifications ?: 0); ?></div>
                        <div class="stat-label">Total Notifications</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value sent"><?php echo esc_html($sent_notifications ?: 0); ?></div>
                        <div class="stat-label">Sent</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">❌</div>
                    <div class="stat-content">
                        <div class="stat-value failed"><?php echo esc_html($failed_notifications ?: 0); ?></div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">💬</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo esc_html($total_responses ?: 0); ?></div>
                        <div class="stat-label">Total Responses</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✓</div>
                    <div class="stat-content">
                        <div class="stat-value processed"><?php echo esc_html($processed_responses ?: 0); ?></div>
                        <div class="stat-label">Processed</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-content">
                        <div class="stat-value pending"><?php echo esc_html(($total_responses ?: 0) - ($processed_responses ?: 0)); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Responses -->
            <div class="widget">
                <h3>📨 Recent Customer Responses</h3>
                <?php if (!empty($recent_responses)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th>Message ID</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_responses as $response): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($response->order_id); ?></strong></td>
                                <td>
                                    <?php if ($response->action === 'confirmed'): ?>
                                        <span class="badge badge-confirmed">✅ Confirm</span>
                                    <?php else: ?>
                                        <span class="badge badge-cancelled">❌ Cancel</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($response->processed): ?>
                                        <span class="badge badge-processed">✅ Processed</span>
                                    <?php elseif ($response->retry_count >= 3): ?>
                                        <span class="badge badge-failed">❌ Failed</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">⏳ Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(substr($response->customer_phone, 0, 8)); ?>...</td>
                                <td><code><?php echo esc_html(substr($response->msg_id, -12)); ?></code></td>
                                <td><?php echo esc_html(human_time_diff(strtotime($response->created_at), current_time('timestamp'))) . ' ago'; ?></td>
                                <td>
                                    <?php if (!$response->processed && $response->retry_count < 3): ?>
                                    <button class="button button-small process-response" 
                                            data-id="<?php echo esc_attr($response->id); ?>"
                                            data-order="<?php echo esc_attr($response->order_id); ?>"
                                            data-action="<?php echo esc_attr($response->action); ?>">
                                        Process Now
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 30px; color: #666;">No customer responses yet.</p>
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions -->
            <div class="widget">
                <h3>⚡ Quick Actions</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="<?php echo esc_url( admin_url('admin.php?page=srliorno-settings') ); ?>" class="button button-primary">⚙️ Configure Settings</a>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=srliorno-responses') ); ?>" class="button">📋 View All Responses</a>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=srliorno-notifications') ); ?>" class="button">📨 View All Notifications</a>
                </div>
            </div>
        </div>
        
        <style>
            .dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            
            .stat-card {
                background: white;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 15px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                transition: all 0.3s ease;
            }
            
            .stat-card:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.05);
                border-color: #25d366;
            }
            
            .stat-icon {
                font-size: 32px;
                width: 50px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f0f6fc;
                border-radius: 12px;
            }
            
            .stat-content {
                flex: 1;
            }
            
            .stat-value {
                font-size: 28px;
                font-weight: 700;
                line-height: 1.2;
            }
            
            .stat-value.sent { color: #28a745; }
            .stat-value.failed { color: #dc3545; }
            .stat-value.processed { color: #17a2b8; }
            .stat-value.pending { color: #ffc107; }
            
            .stat-label {
                font-size: 13px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .widget {
                background: white;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            
            .widget h3 {
                margin-top: 0;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #25d366;
                color: #075e54;
            }
            
            .badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .badge-confirmed { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .badge-cancelled { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .badge-processed { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
            .badge-pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
            .badge-failed { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            
            @media (max-width: 768px) {
                .dashboard-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    public function render_settings() {
        $settings = get_option($this->option_name, []);
        $notification_settings = $settings['notificationSettings'] ?? [];
        $webhook_url = rest_url('srliorno/v1/customer-response');
        ?>
        <div class="wrap">
            <h1>⚙️ Order Notification Settings</h1>
            
            <div class="notice notice-info" style="border-left-color: #25d366;">
                <p><strong>📡 Webhook URL for CRM:</strong> <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 4px;"><?php echo esc_url($webhook_url); ?></code></p>
                <p><small>Configure your CRM to POST customer responses to this URL with JSON payload.</small></p>
                <p><small>Include the <strong>X-Webhook-Secret</strong> header with your webhook secret for authentication.</small></p>
            </div>
            
            <div class="settings-container" style="background: white; border: 1px solid #e2e4e7; border-radius: 8px; padding: 20px; margin-top: 20px;">
                <form id="srliorno-settings-form">
                    <?php wp_nonce_field('srliorno_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="crm_api_key">🔑 Meta CRM API Key</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="crm_api_key" 
                                       name="crm_api_key" 
                                       value="<?php echo esc_attr($notification_settings['crmApiKey'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="Enter your Meta API key"
                                       style="width: 100%; max-width: 500px;" />
                                <p class="description">Your Meta WhatsApp Business API key from CRM system</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="webhook_secret">🔐 Webhook Secret</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="webhook_secret" 
                                       name="webhook_secret" 
                                       value="<?php echo esc_attr($notification_settings['webhookSecret'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="Enter a secret key for webhook authentication"
                                       style="width: 100%; max-width: 500px;" />
                                <p class="description">Shared secret used to authenticate incoming webhook requests from your CRM. The CRM must send this value in the <code>X-Webhook-Secret</code> header.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">📦 Order Confirmation</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="order_created_enabled" 
                                           name="order_created_enabled" 
                                           <?php checked($notification_settings['orderCreated']['enabled'] ?? true); ?> />
                                    Enable order confirmation notifications
                                </label>
                                <p class="description">Customers can reply with <strong>"0"</strong> to confirm or <strong>"1"</strong> to cancel</p>
                                <br>
                                <label for="order_created_template">Template Name:</label>
                                <input type="text" 
                                       id="order_created_template" 
                                       name="order_created_template" 
                                       value="<?php echo esc_attr($notification_settings['orderCreated']['templateName'] ?? 'confirm_order'); ?>" 
                                       class="regular-text" 
                                       style="width: 300px;" />
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">🚚 Fulfillment Notifications</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="fulfillment_created_enabled" 
                                           name="fulfillment_created_enabled" 
                                           <?php checked($notification_settings['fulfillmentCreated']['enabled'] ?? true); ?> />
                                    Enable order shipped notifications
                                </label>
                                <br><br>
                                <label for="fulfillment_created_template">Template Name:</label>
                                <input type="text" 
                                       id="fulfillment_created_template" 
                                       name="fulfillment_created_template" 
                                       value="<?php echo esc_attr($notification_settings['fulfillmentCreated']['templateName'] ?? 'confirm_fulfill'); ?>" 
                                       class="regular-text" 
                                       style="width: 300px;" />
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="default_phone">📱 Default Phone Number</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="default_phone" 
                                       name="default_phone" 
                                       value="<?php echo esc_attr($notification_settings['defaultPhone'] ?? '+923339776136'); ?>" 
                                       class="regular-text" 
                                       placeholder="+923001234567"
                                       style="width: 300px;" />
                                <p class="description">Used when customer phone number is missing from order</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e4e7;">
                        <button type="submit" class="button button-primary button-hero" style="background: #25d366; border-color: #128c7e;">
                            💾 Save Settings
                        </button>
                        <button type="button" id="test-api-key" class="button button-hero" style="margin-left: 10px;">
                            🔍 Test API Key
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_responses() {
        global $wpdb;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination parameter only, no data modification.
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $this->responses_table ) );
        $total_pages = ceil($total / $per_page);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $responses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $this->responses_table,
            $per_page,
            $offset
        ));
        ?>
        <div class="wrap">
            <h1>📋 Order Responses</h1>
            
            <div class="notice notice-info" style="border-left-color: #25d366;">
                <p>Customer responses from WhatsApp - confirmations and cancellations.</p>
            </div>
            
            <div style="background: white; border: 1px solid #e2e4e7; border-radius: 8px; padding: 20px; margin-top: 20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>Order #</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>Customer Phone</th>
                            <th>Message ID</th>
                            <th>Received</th>
                            <th>Processed</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($responses)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: #666;">
                                    No customer responses found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($responses as $response): ?>
                            <tr>
                                <td><?php echo esc_html($response->id); ?></td>
                                <td><strong>#<?php echo esc_html($response->order_id); ?></strong></td>
                                <td>
                                    <?php if ($response->action === 'confirmed'): ?>
                                        <span class="badge badge-confirmed">✅ Confirm</span>
                                    <?php else: ?>
                                        <span class="badge badge-cancelled">❌ Cancel</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($response->processed): ?>
                                        <span class="badge badge-processed">✅ Processed</span>
                                    <?php elseif ($response->retry_count >= 3): ?>
                                        <span class="badge badge-failed">❌ Failed</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">⏳ Pending</span>
                                    <?php endif; ?>
                                    <?php if ($response->retry_count > 0): ?>
                                        <br><small style="color: #666;">Retries: <?php echo esc_html($response->retry_count); ?>/3</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(substr($response->customer_phone, 0, 8)); ?>...</td>
                                <td><code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html(substr($response->msg_id, -12)); ?></code></td>
                                <td><?php echo esc_html(human_time_diff(strtotime($response->created_at), current_time('timestamp'))) . ' ago'; ?></td>
                                <td>
                                    <?php if ($response->processed_at): ?>
                                        <?php echo esc_html(human_time_diff(strtotime($response->processed_at), current_time('timestamp'))) . ' ago'; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$response->processed && $response->retry_count < 3): ?>
                                    <button class="button button-small process-response" 
                                            data-id="<?php echo esc_attr($response->id); ?>"
                                            data-order="<?php echo esc_attr($response->order_id); ?>"
                                            data-action="<?php echo esc_attr($response->action); ?>"
                                            style="background: #25d366; border-color: #128c7e; color: white;">
                                        Process Now
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post( paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ]) );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_notifications() {
        global $wpdb;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination parameter only, no data modification.
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $this->table_name ) );
        $total_pages = ceil($total / $per_page);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $this->table_name,
            $per_page,
            $offset
        ));
        ?>
        <div class="wrap">
            <h1>📨 Order Notifications</h1>
            
            <div style="background: white; border: 1px solid #e2e4e7; border-radius: 8px; padding: 20px; margin-top: 20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>Order #</th>
                            <th>Event</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Sent</th>
                            <th width="100">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($notifications)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #666;">
                                    No notifications sent yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                            <tr>
                                <td><?php echo esc_html($notification->id); ?></td>
                                <td><strong><?php echo esc_html($notification->order_id); ?></strong></td>
                                <td>
                                    <?php if ($notification->event_type === 'orderCreated'): ?>
                                        <span style="color: #28a745;">📦 Order</span>
                                    <?php else: ?>
                                        <span style="color: #17a2b8;">🚚 Fulfillment</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($notification->customer_name); ?></td>
                                <td><?php echo esc_html(substr($notification->phone, 0, 8)); ?>...</td>
                                <td>
                                    <?php if ($notification->status === 'sent'): ?>
                                        <span style="color: #28a745;">✅ Sent</span>
                                    <?php elseif ($notification->status === 'queued'): ?>
                                        <span style="color: #ffc107;">⏳ Queued</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">❌ Failed</span>
                                        <?php if ($notification->error): ?>
                                            <br><small style="color: #666;"><?php echo esc_html(substr($notification->error, 0, 50)); ?>...</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($notification->sent_at): ?>
                                        <?php echo esc_html(human_time_diff(strtotime($notification->sent_at), current_time('timestamp'))) . ' ago'; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($notification->status !== 'sent'): ?>
                                    <button class="button button-small resend-notification" 
                                            data-id="<?php echo esc_attr($notification->id); ?>"
                                            data-order="<?php echo esc_attr($notification->order_id); ?>">
                                        Resend
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post( paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ]) );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * ============ UTILITY FUNCTIONS ============
     */
    private function extract_product_names($items) {
        if (empty($items)) {
            return 'Products not available';
        }

        $names = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product) {
                $names[] = $product->get_name();
            }
        }

        if (count($names) === 1) {
            return $names[0];
        } elseif (count($names) === 2) {
            return implode(' and ', $names);
        } elseif (count($names) > 2) {
            return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
        }

        return 'Products not available';
    }

    private function get_tracking_items($order) {
        $tracking_items = [];
        
        if (function_exists('yith_wc_get_tracking_data')) {
            $tracking_data = yith_wc_get_tracking_data($order->get_id());
            if (!empty($tracking_data)) {
                return $tracking_data;
            }
        }

        if (function_exists('ast_get_tracking_items')) {
            $tracking_items = ast_get_tracking_items($order->get_id());
            if (!empty($tracking_items)) {
                return $tracking_items;
            }
        }

        $tracking_number = $order->get_meta('_tracking_number');
        if (!empty($tracking_number)) {
            $tracking_items[] = [
                'tracking_number' => $tracking_number,
                'tracking_url' => $order->get_meta('_tracking_url') ?: ''
            ];
        }

        return $tracking_items;
    }

    private function normalize_phone_number($phone) {
        if (empty($phone)) {
            return null;
        }

        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '0') {
            $cleaned = '+92' . substr($cleaned, 1);
        } elseif (strlen($cleaned) === 12 && substr($cleaned, 0, 2) === '92') {
            $cleaned = '+' . $cleaned;
        } elseif (strlen($cleaned) === 10 && substr($cleaned, 0, 1) !== '0') {
            $cleaned = '+92' . $cleaned;
        } elseif (strlen($cleaned) > 0 && substr($cleaned, 0, 1) !== '+') {
            $cleaned = '+' . $cleaned;
        }

        return $cleaned;
    }

    public function cleanup_old_records() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $context_deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)", $this->context_table )
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $responses_deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM %i WHERE processed = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)", $this->responses_table )
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $notifications_deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM %i WHERE status IN ('sent', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)", $this->table_name )
        );
        
        $this->logger->info('🧹 Cleanup completed', [
            'context' => $context_deleted,
            'responses' => $responses_deleted,
            'notifications' => $notifications_deleted
        ]);
    }
}

// Initialize the plugin
SRLIORNO_Plugin::get_instance();
?>