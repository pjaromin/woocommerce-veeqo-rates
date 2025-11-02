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
        $this->method_description = __('Fetch live shipping quotes from Veeqo. This method will create a temporary Veeqo order and allocation for rating, update the package (dimensions/weight), then return quotes to WooCommerce checkout.', 'veeqo-rates');
        $this->supports           = array('shipping-zones', 'instance-settings', 'instance-settings-modal');

        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
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
                'description' => __('Your Veeqo <code>x-api-key</code>.', 'veeqo-rates'),
                'default'     => '',
            ),
            'channel_id' => array(
                'title'       => __('Channel / Store ID', 'veeqo-rates'),
                'type'        => 'number',
                'description' => __('Veeqo channel/store to attach created orders.', 'veeqo-rates'),
                'default'     => '',
            ),
            'warehouse_id' => array(
                'title'       => __('Warehouse ID', 'veeqo-rates'),
                'type'        => 'number',
                'description' => __('Warehouse that owns the allocation used for rating.', 'veeqo-rates'),
                'default'     => '',
            ),
            'pkg_l_cm' => array(
                'title'       => __('Default Length (cm)', 'veeqo-rates'),
                'type'        => 'number',
                'default'     => '25',
            ),
            'pkg_w_cm' => array(
                'title'       => __('Default Width (cm)', 'veeqo-rates'),
                'type'        => 'number',
                'default'     => '20',
            ),
            'pkg_h_cm' => array(
                'title'       => __('Default Height (cm)', 'veeqo-rates'),
                'type'        => 'number',
                'default'     => '10',
            ),
            'pkg_weight_g' => array(
                'title'       => __('Default Weight (g)', 'veeqo-rates'),
                'type'        => 'number',
                'default'     => '500',
            ),
            'title' => array(
                'title'       => __('Method Title (Checkout Label)', 'veeqo-rates'),
                'type'        => 'text',
                'default'     => __('Shipping', 'veeqo-rates'),
            ),
        );
    }

    public function calculate_shipping( $package = array() ) {
        $logger = wc_get_logger();
        $logctx = array('source' => 'veeqo_rates');

        if ( 'yes' !== $this->get_instance_option('enabled', 'yes') ) {
            return;
        }

        $api_key      = trim((string) $this->get_instance_option('api_key'));
        $channel_id   = absint($this->get_instance_option('channel_id'));
        $warehouse_id = absint($this->get_instance_option('warehouse_id'));

        if ( empty($api_key) || ! $channel_id || ! $warehouse_id ) {
            $logger->warning('Missing required settings (api_key/channel_id/warehouse_id).', $logctx);
            return;
        }

        $parcel = $this->build_parcel_from_cart( $package );

        $dest = $package['destination'];
        $customer = wp_get_current_user();
        $email = method_exists('WC_Checkout', 'instance') && WC()->customer ? WC()->customer->get_email() : '';
        if (empty($email) && $customer && !empty($customer->user_email)) {
            $email = $customer->user_email;
        }
        $phone = WC()->customer ? WC()->customer->get_billing_phone() : '';

        $deliver_to = array(
            'name'       => trim( ($dest['first_name'] ?? '') . ' ' . ($dest['last_name'] ?? '') ),
            'company'    => $dest['company'] ?? '',
            'phone'      => $phone,
            'email'      => $email,
            'address1'   => $dest['address_1'] ?? ($dest['address'] ?? ''),
            'address2'   => $dest['address_2'] ?? '',
            'city'       => $dest['city'] ?? '',
            'state'      => $dest['state'] ?? '',
            'zip'        => $dest['postcode'] ?? '',
            'country'    => $dest['country'] ?? '',
        );

        // 1) Create order
        try {
            $order_payload = $this->make_order_payload( $channel_id, $deliver_to, $package );
            $order = $this->veeqo_request( 'POST', '/orders', $api_key, $order_payload );
            $order_id = (int) ($order['id'] ?? 0);
            if (!$order_id) { throw new Exception('No order ID returned'); }
        } catch (Throwable $e) {
            $logger->error('Create order failed: ' . $e->getMessage(), $logctx);
            return;
        }

        // 2) Create allocation
        try {
            $allocation_payload = $this->make_allocation_payload( $warehouse_id, $order );
            $allocation = $this->veeqo_request( 'POST', sprintf('/orders/%d/allocations', $order_id), $api_key, $allocation_payload );
            $allocation_id = (int) ($allocation['id'] ?? 0);
            if (!$allocation_id) { throw new Exception('No allocation ID returned'); }
        } catch (Throwable $e) {
            $logger->error('Create allocation failed: ' . $e->getMessage(), $logctx);
            return;
        }

        // 3) Update allocation package
        try {
            $this->veeqo_request(
                'PUT',
                sprintf('/allocations/%d/package', $allocation_id),
                $api_key,
                array(
                    'package' => array(
                        'weight_grams' => (int) $parcel['weight_g'],
                        'length_cm'    => (float) $parcel['l_cm'],
                        'width_cm'     => (float) $parcel['w_cm'],
                        'height_cm'    => (float) $parcel['h_cm'],
                    ),
                )
            );
        } catch (Throwable $e) {
            $logger->error('Update allocation package failed: ' . $e->getMessage(), $logctx);
            return;
        }

        // 4) Retrieve quotes
        try {
            $query = http_build_query(array(
                'allocation_id' => $allocation_id,
                'from_allocation_package' => 'true',
            ));
            $quotes = $this->veeqo_request( 'GET', '/shipping/quotes/amazon_shipping_v2?' . $query, $api_key );
            if ( empty($quotes) || ! is_array($quotes) ) {
                throw new Exception('No quotes returned');
            }

            foreach ($quotes as $q) {
                $label = apply_filters( 'veeqo_rates_label',
                    trim(sprintf('%s %s',
                        $q['carrier_name'] ?? __('Carrier','veeqo-rates'),
                        $q['service_name'] ?? __('Service','veeqo-rates')
                    )),
                    $q
                );

                $rate = array(
                    'id'    => 'veeqo_' . $allocation_id . '_' . sanitize_title_with_dashes($q['service_code'] ?? uniqid('svc_')),
                    'label' => $label,
                    'cost'  => isset($q['cost']) ? ((float)$q['cost'] / 100.0) : 0.0,
                    'meta_data' => array(
                        'veeqo_allocation_id' => $allocation_id,
                        'veeqo_service_code'  => $q['service_code'] ?? '',
                        'veeqo_carrier'       => $q['carrier_name'] ?? '',
                        'veeqo_eta_days'      => $q['delivery_days'] ?? '',
                    ),
                );

                $rate = apply_filters( 'veeqo_rates_woo_rate', $rate, $q );
                $this->add_rate( $rate );
            }

        } catch (Throwable $e) {
            $logger->warning('No rates returned: ' . $e->getMessage(), $logctx);
        }
    }

    private function build_parcel_from_cart( $package ) {
        $l = (float) $this->get_instance_option('pkg_l_cm', 25);
        $w = (float) $this->get_instance_option('pkg_w_cm', 20);
        $h = (float) $this->get_instance_option('pkg_h_cm', 10);
        $weight_g = (float) $this->get_instance_option('pkg_weight_g', 500);

        $cart_wg = 0;
        foreach ( $package['contents'] as $item ) {
            if ( empty($item['data']) ) { continue; }
            $p = $item['data'];
            $qty = (int) $item['quantity'];
            $wg = wc_get_weight( (float)$p->get_weight(), 'g' );
            if ($wg > 0) { $cart_wg += ($wg * $qty); }
        }
        if ($cart_wg > 0) { $weight_g = $cart_wg; }
        return array('l_cm'=>$l,'w_cm'=>$w,'h_cm'=>$h,'weight_g'=>$weight_g);
    }

    private function make_order_payload( $channel_id, array $deliver_to, $package ) {
        $line_items = array();
        foreach ( $package['contents'] as $item ) {
            if ( empty($item['data']) ) { continue; }
            $product = $item['data'];
            $sku     = (string) $product->get_sku();
            $qty     = (int) $item['quantity'];

            $sellable_id = $this->lookup_sellable_id_by_sku( $sku );
            if (!$sellable_id) { continue; }

            $line_items[] = array(
                'sellable_id' => $sellable_id,
                'quantity'    => $qty,
                'price'       => (float) wc_get_price_excluding_tax($product),
                'taxless'     => true,
            );
        }

        // Fallback to cart total as grand_total if needed is handled by Veeqo; for rating, line items suffice.
        return array(
            'order' => array(
                'channel_id' => $channel_id,
                'deliver_to_attributes' => array(
                    'shipping_address_attributes' => array(
                        'name'     => $deliver_to['name'],
                        'company'  => $deliver_to['company'],
                        'address1' => $deliver_to['address1'],
                        'address2' => $deliver_to['address2'],
                        'city'     => $deliver_to['city'],
                        'state'    => $deliver_to['state'],
                        'zip'      => $deliver_to['zip'],
                        'country'  => $deliver_to['country'],
                        'phone'    => $deliver_to['phone'],
                    ),
                    'email' => $deliver_to['email'],
                ),
                'line_items_attributes' => $line_items,
                'customer_attributes'   => array(
                    'first_name' => trim(explode(' ', $deliver_to['name'])[0] ?? ''),
                    'last_name'  => trim(preg_replace('/^[^\s]+\s*/','', $deliver_to['name'])),
                    'email'      => $deliver_to['email'],
                ),
                'notes' => 'Temporary order created for rate shopping (WooCommerce).',
            ),
        );
    }

    private function make_allocation_payload( $warehouse_id, array $order ) {
        $lis = array();
        if ( ! empty($order['line_items']) && is_array($order['line_items']) ) {
            foreach ( $order['line_items'] as $li ) {
                if (empty($li['sellable_id']) || empty($li['quantity'])) { continue; }
                $lis[] = array(
                    'sellable_id' => (int) $li['sellable_id'],
                    'quantity'    => (int) $li['quantity'],
                );
            }
        }
        return array(
            'allocation' => array(
                'warehouse_id' => $warehouse_id,
                'line_items_attributes' => $lis,
            ),
        );
    }

    private function lookup_sellable_id_by_sku( $sku ) {
        if ( ! $sku ) { return 0; }
        $api_key = trim((string) $this->get_instance_option('api_key'));
        try {
            $resp = $this->veeqo_request( 'GET', '/products?query=' . rawurlencode($sku), $api_key );
            if ( is_array($resp) ) {
                foreach ( $resp as $prod ) {
                    if ( ! empty($prod['sellables']) && is_array($prod['sellables']) ) {
                        foreach ( $prod['sellables'] as $s ) {
                            if ( isset($s['sku_code']) && strcasecmp($s['sku_code'],$sku) === 0 ) {
                                return (int) $s['id'];
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            wc_get_logger()->warning('SKU lookup failed for ' . $sku . ': ' . $e->getMessage(), array('source'=>'veeqo_rates'));
        }
        return 0;
    }

    private function veeqo_request( $method, $path, $api_key, $body = null ) {
        $url = 'https://api.veeqo.com' . $path;
        $args = array(
            'method'  => $method,
            'headers' => array(
                'accept'        => 'application/json',
                'x-api-key'     => $api_key,
                'content-type'  => 'application/json',
            ),
            'timeout' => 20,
        );
        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }
        $res = wp_remote_request( $url, $args );
        if ( is_wp_error($res) ) {
            throw new Exception( $res->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $data = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array($data) ? wp_json_encode($data) : $raw;
            throw new Exception("HTTP $code $msg");
        }
        return $data;
    }
}
