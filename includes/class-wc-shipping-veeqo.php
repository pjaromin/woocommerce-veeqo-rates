<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WC_Shipping_Veeqo' ) ) :

class WC_Shipping_Veeqo extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'veeqo_rates';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'Veeqo Rates';
        $this->method_description = 'Live rates via Veeqo Rate Shopping API';
        $this->supports           = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled            = $this->get_option('enabled', 'yes');
        $this->title              = $this->get_option('title', 'Live Shipping');
        $this->api_token          = trim((string) $this->get_option('api_token', ''));
        $this->allocation_id      = trim((string) $this->get_option('allocation_id', ''));
        $this->default_length     = (float) $this->get_option('default_length', '25');
        $this->default_width      = (float) $this->get_option('default_width',  '20');
        $this->default_height     = (float) $this->get_option('default_height', '10');
        $this->package_weight_pad = (float) $this->get_option('package_weight_pad', '0.1');
        $this->debug_mode         = $this->get_option('debug_mode', 'no') === 'yes';

        add_action("woocommerce_update_options_shipping_{$this->id}", [$this, 'process_admin_options']);
    }

    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => 'Enable',
                'type'  => 'checkbox',
                'label' => 'Enable',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Method title',
                'type'  => 'text',
                'default' => 'Live Shipping'
            ),
            'api_token' => array(
                'title' => 'Veeqo API key',
                'type'  => 'password',
                'default' => ''
            ),
            'allocation_id' => array(
                'title' => 'Veeqo allocation_id (for testing)',
                'type'  => 'text',
                'description' => 'Optional: quotes will be fetched for this allocation in Veeqo. Leave blank until you have one.',
                'default' => ''
            ),
            'default_length' => array(
                'title' => 'Default length (cm)',
                'type'  => 'number',
                'default' => '25'
            ),
            'default_width'  => array(
                'title' => 'Default width (cm)',
                'type'  => 'number',
                'default' => '20'
            ),
            'default_height' => array(
                'title' => 'Default height (cm)',
                'type'  => 'number',
                'default' => '10'
            ),
            'package_weight_pad' => array(
                'title' => 'Box/void fill weight pad (kg)',
                'type'  => 'number',
                'default' => '0.1'
            ),
            'debug_mode' => array(
                'title' => 'Debug mode',
                'type'  => 'checkbox',
                'label' => 'Add a $4.99 probe rate and verbose logs',
                'default' => 'no'
            ),
        );
    }

    public function is_available( $package ) {
        $logger = wc_get_logger();
        $logger->info(
            'VEEQO is_available: enabled='.$this->enabled.' token_set='.($this->api_token !== '' ? 'yes':'no'),
            ['source'=>'veeqo_rates']
        );
        return ( 'yes' === $this->enabled );
    }

    public function calculate_shipping( $package = array() ) {
        $logger = wc_get_logger();
        $ctx    = ['source' => 'veeqo_rates'];

        $logger->info('VEEQO calc: start', $ctx);

        if ( $this->debug_mode ) {
            $this->add_rate([
                'id'    => $this->id . ':probe',
                'label' => 'Veeqo (debug) $4.99',
                'cost'  => 4.99,
            ]);
        }

        if ( $this->api_token === '' ) {
            $logger->error('VEEQO calc: missing api_token', $ctx);
            return;
        }

        // Build parcel weight from cart + pad (future: sync to allocation)
        $total_weight_kg = 0.0;
        if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                if ( empty( $item['data'] ) ) { continue; }
                $qty = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;
                $w   = (float) wc_get_weight( $item['data']->get_weight() ?: 0, 'kg' );
                $total_weight_kg += $w * $qty;
            }
        }
        if ( $total_weight_kg <= 0 ) { $total_weight_kg = 0.25; }
        $total_weight_kg += (float) $this->package_weight_pad;
        $parcel = [
            'length_cm' => (float) ( $this->default_length ?: 25 ),
            'width_cm'  => (float) ( $this->default_width  ?: 20 ),
            'height_cm' => (float) ( $this->default_height ?: 10 ),
            'weight_kg' => max( 0.01, $total_weight_kg ),
        ];

        $base = 'https://api.veeqo.com/shipping/quotes/amazon_shipping_v2';
        if ( $this->allocation_id !== '' ) {
            $params = [ 'allocation_id' => $this->allocation_id ];
        } else {
            // This usually 404s; left for debugging only.
            $dest   = isset( $package['destination'] ) ? (array) $package['destination'] : [];
            $params = [
                'country_code' => $dest['country']  ?? '',
                'postal_code'  => $dest['postcode'] ?? '',
                'city'         => $dest['city']     ?? '',
                'address_line' => trim( ($dest['address_1'] ?? '') . ' ' . ($dest['address_2'] ?? '') ),
                'length_cm'    => $parcel['length_cm'],
                'width_cm'     => $parcel['width_cm'],
                'height_cm'    => $parcel['height_cm'],
                'weight_kg'    => $parcel['weight_kg'],
            ];
        }
        $url = esc_url_raw( add_query_arg( $params, $base ) );
        $logger->info('VEEQO URL: ' . $url, $ctx);

        $resp = wp_remote_get( $url, [
            'headers' => [
                'x-api-key' => $this->api_token,
                'Accept'    => 'application/json',
            ],
            'timeout'    => 20,
            'user-agent' => 'WooVeeqoRates/' . WCVEEQO_RATES_VERSION . ' (+WordPress ' . get_bloginfo('version') . ')'
        ] );

        if ( is_wp_error( $resp ) ) {
            $logger->error('VEEQO HTTP error: ' . $resp->get_error_message(), $ctx);
            return;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $resp );
        $body_raw  = wp_remote_retrieve_body( $resp );
        $logger->info('VEEQO HTTP ' . $http_code . ' body: ' . substr( $body_raw, 0, 900 ), $ctx);

        if ( $http_code !== 200 ) {
            if ( in_array($http_code, [401,403], true) ) {
                $logger->error('VEEQO auth failure (check API key / permissions)', $ctx);
            } elseif ( $http_code === 404 ) {
                $logger->error('VEEQO 404 (allocation_id likely required or invalid)', $ctx);
            } else {
                $logger->error('VEEQO non-200: ' . $http_code, $ctx);
            }
            return;
        }

        $data = json_decode( $body_raw, true );
        if ( ! is_array( $data ) || empty( $data ) ) {
            $logger->error('VEEQO parse: empty/invalid JSON after 200', $ctx);
            return;
        }
        if ( isset( $data['rates'] ) && is_array( $data['rates'] ) ) {
            $data = $data['rates'];
        }

        $added = 0;
        foreach ( $data as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            $rate_id = (string) ( $r['id'] ?? $r['rate_id'] ?? '' );
            $label   = (string) ( $r['service_name'] ?? $r['carrier_service'] ?? $r['carrier'] ?? 'Carrier' );
            $amount  = (float)  ( $r['amount'] ?? $r['price'] ?? $r['total'] ?? 0 );
            if ( $rate_id === '' ) { continue; }

            $rate = [
                'id'        => $this->id . ':' . sanitize_title( $rate_id ),
                'label'     => apply_filters( 'veeqo_rates_label', $label, $r ),
                'cost'      => max( 0, $amount ),
                'meta_data' => [ 'veeqo_rate_id' => $rate_id ],
            ];

            $this->add_rate( apply_filters( 'veeqo_rates_woo_rate', $rate, $r ) );
            $added++;
        }

        $logger->info('VEEQO added rates: ' . $added, $ctx);
    }
}

endif;
