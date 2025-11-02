<?php
defined('ABSPATH') || exit;

if ( class_exists('Veeqo_Rates_CLI', false) ) {
    return;
}

class Veeqo_Rates_CLI {
    private static function request( $method, $path, $api_key, $body = null ) {
        $url = 'https://api.veeqo.com' . $path;
        $args = array(
            'method'  => $method,
            'headers' => array(
                'accept'        => 'application/json',
                'x-api-key'     => $api_key,
                'content-type'  => 'application/json',
            ),
            'timeout' => 30,
        );
        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }
        $res = wp_remote_request( $url, $args );
        if ( is_wp_error($res) ) {
            WP_CLI::error( $res->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $data = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            WP_CLI::error( 'HTTP ' . $code . ' ' . $raw );
        }
        return $data;
    }

    public function whoami( $args, $assoc_args ) {
        $api_key = $assoc_args['api_key'] ?? '';
        if ( ! $api_key ) { WP_CLI::error('Missing --api_key'); }
        $channels   = self::request('GET', '/channels', $api_key);
        $warehouses = self::request('GET', '/warehouses', $api_key);
        WP_CLI\Utils\format_items('table', $channels, array('id','name') );
        WP_CLI::log('---');
        WP_CLI\Utils\format_items('table', $warehouses, array('id','name','code') );
    }
}

WP_CLI::add_command('veeqo rates:whoami', [ 'Veeqo_Rates_CLI', 'whoami' ]);
