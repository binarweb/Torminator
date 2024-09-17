<?php

/*
Plugin Name: Torminator
Plugin URI:  https://example.com/my-custom-plugin
Description: A brief description of what the plugin does.
Version:     0.0.1
Author:      Your Name
Author URI:  https://example.com
License:     MIT
Text Domain: my-custom-plugin
*/

function Torminator_main()
{
    // $ip_address = Torminator_getUserIpAddress();
    $ip_address = '171.25.193.25';
    if (Torminator_isIpAddressBlocked($ip_address)) {
        header('HTTP/1.1 403 Forbidden');

        echo 'You are not allowed to access this website.';

        exit;
    }
}

function Torminator_init()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'torminator_ip_addresses';
    $charset_collate = $wpdb->get_charset_collate();

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ip_address varchar(100) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        dbDelta($sql);

        $ipAddresses = Torminator_getIpAddressesFromFile('torbulkexitlist');

        foreach ($ipAddresses as $ipAddress) {
            Torminator_saveIpAddress($ipAddress);
        }
    }
}

function Torminator_getIpAddressesFromFile($filename)
{
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    global $wp_filesystem;
    WP_Filesystem();
    
    $file = plugin_dir_path( __FILE__ ) . "resources/" . $filename;

    if ( $wp_filesystem->exists( $file ) ) {
        $file_contents = $wp_filesystem->get_contents( $file );
        
        return explode("\n", nl2br( esc_html( $file_contents ) ));
    } else
        die('File does not exist.');
}

function Torminator_saveIpAddress($ipAddress)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'torminator_ip_addresses';

    $wpdb->insert(
        $table_name,
        array(
            'ip_address' => sanitize_text_field($ipAddress)
        )
    );
}

function Torminator_isIpAddressBlocked($ipAddress)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'torminator_ip_addresses';

    $query = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE ip_address = %s", sanitize_text_field($ipAddress)
    );

    $result = $wpdb->get_results($query);

    if (empty($result))
        return false;

    return count($result) > 0;
}

function Torminator_getUserIpAddress()
{
    return  $_SERVER['REMOTE_ADDR'];
}

register_activation_hook(__FILE__, 'Torminator_init');
add_action('plugins_loaded', 'Torminator_main');