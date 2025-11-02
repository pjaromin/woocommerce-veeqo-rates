<?php
defined('ABSPATH') || exit;

if ( class_exists('WC_Shipping_Veeqo_Rates', false) ) {
    return;
}

class WC_Shipping_Veeqo_Rates extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id                 = 'veeqo_rates';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('Veeqo Rates', 'veeqo-rates');
        $this->method_description = __('Fetch live shipping quotes from Veeqo Rate Shopping API.', 'veeqo-rates');
        $this->supports           = array('shipping-zones', 'instance-settings', 'instance-settings-modal');

        $this->init();
        
        // Set title from instance settings
        $this->title = $this->get_instance_option('title', __('Veeqo Live Rates', 'veeqo-rates'));

        wc_get_logger()->info('Constructor called for instance ' . $this->instance_id, array('source' => 'veeqo_rates'));
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }
    
    public function process_admin_options() {
        $result = parent::process_admin_options();
        // Update title after saving settings
        $this->title = $this->get_instance_option('title', __('Veeqo Live Rates', 'veeqo-rates'));
        return $result;
    }
    
    public function is_available( $package = array() ) {
        $enabled = $this->get_instance_option('enabled', 'yes');
        $test_mode = $this->get_instance_option('test_mode', 'no');
        $api_key = trim((string) $this->get_instance_option('api_key'));
        
        wc_get_logger()->info('is_available called - instance: ' . $this->instance_id . ' enabled: ' . $enabled . ' test_mode: ' . $test_mode . ' has_api_key: ' . (!empty($api_key) ? 'yes' : 'no'), array('source' => 'veeqo_rates'));
        
        if ( 'yes' !== $enabled ) {
            wc_get_logger()->info('Method disabled', array('source' => 'veeqo_rates'));
            return false;
        }
        
        // Available if test mode OR has API key
        $available = ($test_mode === 'yes') || !empty($api_key);
        wc_get_logger()->info('Method available: ' . ($available ? 'yes' : 'no'), array('source' => 'veeqo_rates'));
        return $available;
    }

    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'veeqo-rates'),
                'type'        => 'checkbox',
                'label'       => __('Enable Veeqo Rates', 'veeqo-rates'),
                'default'     => 'yes',
            ),
            'api_key' => array(
                'title'       => __('Veeqo API Key', 'veeqo-rates'),
                'type'        => 'password',
                'description' => __('Your Veeqo API key.', 'veeqo-rates'),
                'default'     => '',
            ),
            'channel_id' => array(
                'title'       => __('Channel / Store ID', 'veeqo-rates'),
                'type'        => 'text',
                'description' => __('Numeric Channel ID from Veeqo.', 'veeqo-rates'),
                'default'     => '',
            ),
            'warehouse_id' => array(
                'title'       => __('Warehouse ID', 'veeqo-rates'),
                'type'        => 'text',
                'description' => __('Numeric Warehouse ID from Veeqo.', 'veeqo-rates'),
                'default'     => '',
            ),
            'pkg_l_cm' => array(
                'title'       => __('Default Length (cm)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => '25',
            ),
            'pkg_w_cm' => array(
                'title'       => __('Default Width (cm)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => '20',
            ),
            'pkg_h_cm' => array(
                'title'       => __('Default Height (cm)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => '10',
            ),
            'pkg_weight_g' => array(
                'title'       => __('Default Weight (g)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => '500',
            ),
            'test_mode' => array(
                'title'       => __('Test Mode', 'veeqo-rates'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode (offline mock rates)', 'veeqo-rates'),
                'default'     => 'no',
                'description' => __('Returns fake shipping rates for testing without API calls.', 'veeqo-rates'),
            ),
            'title' => array(
                'title'       => __('Method Title (Checkout Label)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => __('Veeqo Live Rates', 'veeqo-rates'),
            ),
        );
    }

    public function calculate_shipping( $package = array() ) {
        $logger = wc_get_logger();
        $logctx = array('source' => 'veeqo_rates');
        $logger->info('calculate_shipping called', $logctx);

        if ( 'yes' !== $this->get_instance_option('enabled', 'yes') ) {
            $logger->info('Method disabled in calculate_shipping', $logctx);
            return;
        }

        $test_mode = ('yes' === $this->get_instance_option('test_mode', 'no'));
        $logger->info('Test mode: ' . ($test_mode ? 'enabled' : 'disabled'), $logctx);

        // Test mode - return mock rates
        if ( $test_mode ) {
            $this->add_test_rates();
            return;
        }

        $api_key = trim((string) $this->get_instance_option('api_key'));
        
        if ( empty($api_key) ) {
            $logger->warning('Missing API key', $logctx);
            return;
        }

        $channel_id = trim((string) $this->get_instance_option('channel_id'));
        $warehouse_id = trim((string) $this->get_instance_option('warehouse_id'));
        
        if ( empty($channel_id) || empty($warehouse_id) ) {
            $logger->warning('Missing Channel ID or Warehouse ID', $logctx);
            return;
        }

        // Get delivery methods and calculate quantity-based rates
        try {
            $delivery_methods = $this->veeqo_request('GET', '/delivery_methods', $api_key);
            
            if (empty($delivery_methods) || !is_array($delivery_methods)) {
                throw new Exception('No delivery methods available');
            }
            
            // Calculate total quantity and weight from cart
            $total_qty = 0;
            $total_weight = 0;
            foreach ($package['contents'] as $item) {
                $total_qty += $item['quantity'];
                $total_weight += $item['data']->get_weight() * $item['quantity'];
            }
            
            $logger->info('Cart totals - Qty: ' . $total_qty . ', Weight: ' . $total_weight, $logctx);
            
            // Filter to methods with names and costs > 0
            $valid_methods = array_filter($delivery_methods, function($method) {
                return !empty($method['name']) && (float)$method['cost'] > 0;
            });
            
            foreach ($valid_methods as $method) {
                $base_cost = (float)$method['cost'];
                
                // Apply quantity multiplier (simple example)
                $qty_multiplier = max(1, ceil($total_qty / 10)); // Every 10 items adds cost
                $adjusted_cost = $base_cost * $qty_multiplier;
                
                $this->add_rate(array(
                    'id' => 'veeqo_method_' . $method['id'],
                    'label' => $method['name'] . ' (Qty: ' . $total_qty . ')',
                    'cost' => $adjusted_cost
                ));
            }
            
            $logger->info('Added ' . count($valid_methods) . ' quantity-adjusted delivery methods from Veeqo', $logctx);
            
        } catch (Exception $e) {
            $logger->error('Veeqo rate error: ' . $e->getMessage(), $logctx);
            
            // If channel type error, list available channels
            if (strpos($e->getMessage(), 'channel type') !== false) {
                $this->list_available_channels($api_key, $logger, $logctx);
            }
            
            // Explore API endpoints
            $this->explore_shipping_endpoints($api_key, $logger, $logctx);
            $this->examine_available_data($api_key, $logger, $logctx);
        }
    }

    private function build_parcel_from_cart( $package ) {
        $l = (float) $this->get_instance_option('pkg_l_cm', 25);
        $w = (float) $this->get_instance_option('pkg_w_cm', 20);
        $h = (float) $this->get_instance_option('pkg_h_cm', 10);
        $weight_g = (float) $this->get_instance_option('pkg_weight_g', 500);
        return array('l_cm'=>$l,'w_cm'=>$w,'h_cm'=>$h,'weight_g'=>$weight_g);
    }
    
    private function add_test_rates() {
        $logger = wc_get_logger();
        $logctx = array('source' => 'veeqo_rates');
        $logger->info('Adding test rates', $logctx);
        
        $test_rates = array(
            array('id' => 'test_standard', 'label' => 'Standard Shipping (Test)', 'cost' => 5.99),
            array('id' => 'test_express', 'label' => 'Express Shipping (Test)', 'cost' => 12.99),
            array('id' => 'test_overnight', 'label' => 'Overnight Shipping (Test)', 'cost' => 24.99)
        );
        
        foreach ($test_rates as $rate) {
            $this->add_rate($rate);
        }
        $logger->info('Added ' . count($test_rates) . ' test rates', $logctx);
    }
    
    private function get_first_sellable_id($api_key) {
        try {
            $products = $this->veeqo_request('GET', '/products?page=1&per_page=1', $api_key);
            if (is_array($products) && !empty($products)) {
                $product = $products[0];
                if (!empty($product['sellables']) && is_array($product['sellables'])) {
                    return (int) $product['sellables'][0]['id'];
                }
            }
        } catch (Exception $e) {
            wc_get_logger()->warning('Failed to get sellable_id: ' . $e->getMessage(), array('source' => 'veeqo_rates'));
        }
        return 0;
    }
    
    private function list_available_channels($api_key, $logger, $logctx) {
        try {
            $channels = $this->veeqo_request('GET', '/channels', $api_key);
            if (is_array($channels)) {
                $logger->info('Available channels in your Veeqo account:', $logctx);
                foreach ($channels as $channel) {
                    $logger->info(sprintf('Channel ID: %d, Name: %s, Type: %s', 
                        $channel['id'] ?? 0,
                        $channel['name'] ?? 'Unknown',
                        $channel['type'] ?? 'Unknown'
                    ), $logctx);
                }
                $logger->info('Look for channels with type "woocommerce", "api", or "manual" for rate shopping', $logctx);
            }
        } catch (Exception $e) {
            $logger->warning('Could not list channels: ' . $e->getMessage(), $logctx);
        }
    }
    
    private function explore_shipping_endpoints($api_key, $logger, $logctx) {
        $logger->info('Exploring Veeqo API shipping endpoints...', $logctx);
        
        $endpoints_to_test = array(
            '/shipping',
            '/shipping/rates',
            '/shipping/quotes',
            '/shipping/carriers',
            '/shipping/services',
            '/rates',
            '/quotes',
            '/carriers',
            '/delivery_methods',
            '/shipping_methods'
        );
        
        foreach ($endpoints_to_test as $endpoint) {
            try {
                $this->veeqo_request('GET', $endpoint, $api_key);
                $logger->info('✓ Endpoint available: ' . $endpoint, $logctx);
            } catch (Exception $e) {
                $code = '';
                if (preg_match('/HTTP (\d+):/', $e->getMessage(), $matches)) {
                    $code = $matches[1];
                }
                $logger->info('✗ Endpoint ' . $endpoint . ' - HTTP ' . $code, $logctx);
            }
        }
    }
    
    private function examine_available_data($api_key, $logger, $logctx) {
        $logger->info('Examining available shipping data...', $logctx);
        
        try {
            $carriers = $this->veeqo_request('GET', '/carriers', $api_key);
            $logger->info('Carriers data: ' . wp_json_encode($carriers), $logctx);
        } catch (Exception $e) {
            $logger->warning('Could not get carriers: ' . $e->getMessage(), $logctx);
        }
        
        try {
            $delivery_methods = $this->veeqo_request('GET', '/delivery_methods', $api_key);
            $logger->info('Delivery methods data: ' . wp_json_encode($delivery_methods), $logctx);
        } catch (Exception $e) {
            $logger->warning('Could not get delivery methods: ' . $e->getMessage(), $logctx);
        }
    }
    
    private function veeqo_request($method, $path, $api_key, $body = null) {
        $url = 'https://api.veeqo.com' . $path;
        $args = array(
            'method' => $method,
            'headers' => array(
                'accept' => 'application/json',
                'x-api-key' => $api_key,
                'content-type' => 'application/json'
            ),
            'timeout' => 5
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        
        $logger = wc_get_logger();
        $logger->debug("Veeqo API Request: $method $url", array('source' => 'veeqo_rates'));
        
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new Exception($res->get_error_message());
        }
        
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        
        $logger->debug("Veeqo API Response: HTTP $code", array('source' => 'veeqo_rates'));
        
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? wp_json_encode($data) : $raw;
            throw new Exception("HTTP $code: $msg");
        }
        return $data;
    }
}