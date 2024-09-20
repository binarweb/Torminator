<?php

/*
Plugin Name: Torminator
Plugin URI:  https://github.com/binarweb/Torminator/
Description: Protect your website by blocking unwanted traffic from Tor Exit Nodes.
Version:     0.0.1
Requires at least: 6.0.0
Requires PHP: 7.0.0
Author:      binarweb
Author URI:  https://binarweb.ro
License:     GPL-3.0-or-later
*/

$Torminator_exit_nodes_tor = 'https://check.torproject.org/torbulkexitlist';
$Torminator_exit_nodes_filename = 'torbulkexitlist';
$Torminator_table_name = 'torminator_ip_addresses';

function Torminator_main()
{
    global $Torminator_exit_nodes_filename;

    $ip_address = Torminator_getUserIpAddress();

    if (Torminator_isIpAddressBlocked($ip_address)) {
        $message = '
        <div style="text-align: center">
            <h1>Access denied</h1>
            <p>
                You are not allowed to access this website.<br>
                Your IP is: ' . esc_html($ip_address) . '
            </p>
        </div>
        ';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_die($message, 'Access denied', array(
            'response' => 403
        ));
    }
}

function Torminator_getUserIpAddress()
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '0.0.0.0'
    ;
}

function Torminator_isIpAddressBlocked($ipAddress)
{
    // Check the IP address in cache
    $cache_key = 'torminator_blocked_ip_' . md5($ipAddress);
    $cached_result = wp_cache_get($cache_key);

    if ($cached_result !== false) {
        return $cached_result;
    }

    $is_blocked = Torminator_isIpAlreadyInDb($ipAddress);

    // Cache the result
    wp_cache_set($cache_key, $is_blocked, '', 300); // Cache for 5 minutes

    return $is_blocked;
}

function Torminator_updateIpAddresses()
{
    if (! Torminator_updateIpAddressesFile()) {
        return;
    }

    Torminator_setIpAddressesAsUpdating();
    Torminator_saveIpAddressesInDbFromFile();
    Torminator_deleteOldIpAddresses();
}

function Torminator_updateIpAddressesFile()
{
    global $wp_filesystem, $Torminator_exit_nodes_tor, $Torminator_exit_nodes_filename;

    if (! function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Ensure we're using the direct filesystem method
    add_filter('filesystem_method', function() { return 'direct'; });

    // Start the filesystem
    WP_Filesystem();

    $filepath = plugin_dir_path(__FILE__) . "resources/" . $Torminator_exit_nodes_filename;
    $response = wp_remote_get($Torminator_exit_nodes_tor);

    if ($response instanceof WP_Error) {
        print_r($response);
        return false;
    }

    $content = str_replace(" ", PHP_EOL, $response['body']);

    // Create the resources directory
    $wp_filesystem->mkdir(dirname($filepath));

    // Save the file
    $wp_filesystem->put_contents($filepath, $content);

    // Remove the filter after we're done
    remove_filter('filesystem_method', function() { return 'direct'; });

    return true;
}

function Torminator_setIpAddressesAsUpdating()
{
    global $wpdb, $Torminator_table_name;

    $table_name = $wpdb->prefix . $Torminator_table_name;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE %i SET `is_updating` = 1",
            $table_name
        )
    );
}

function Torminator_saveIpAddressesInDbFromFile()
{
    global $wp_filesystem, $Torminator_exit_nodes_filename;

    $filepath = plugin_dir_path(__FILE__) . "resources/" . $Torminator_exit_nodes_filename;

    if ($wp_filesystem->exists($filepath)) {
        $lines = $wp_filesystem->get_contents_array($filepath);

        // Make sure the Tor file contains at leat 50 IPs
        if (count($lines) < 51) {
            return;
        }

        foreach ($lines as $ip) {
            $ip = rtrim($ip); // Remove the new line
            Torminator_saveIpAddress($ip);
        }
    }
}

function Torminator_saveIpAddress($ipAddress)
{
    global $wpdb, $Torminator_table_name;

    $table_name = $wpdb->prefix . $Torminator_table_name;

    if (Torminator_isIpAlreadyInDb($ipAddress)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            array('is_updating' => 0),
            array('ip_address' => sanitize_text_field($ipAddress))
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $table_name,
            array('ip_address' => sanitize_text_field($ipAddress))
        );
    }
}

function Torminator_isIpAlreadyInDb($ipAddress)
{
    global $wpdb, $Torminator_table_name;

    $table_name = $wpdb->prefix . $Torminator_table_name;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM %i WHERE `ip_address` = %s LIMIT 1", $table_name, sanitize_text_field($ipAddress)
        )
    );

    return empty($result) ? false : count($result) > 0;
}

function Torminator_deleteOldIpAddresses()
{
    global $wpdb, $Torminator_table_name;

    $table_name = $wpdb->prefix . $Torminator_table_name;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM %i WHERE `is_updating` = 1", $table_name
        )
    );
}

function Torminator_activate()
{
    global $wpdb, $Torminator_exit_nodes_filename, $Torminator_table_name;

    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'torminator_ip_addresses';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        ip_address varchar(100) NOT NULL,
        is_updating int(1) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    if (! function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }

    dbDelta($sql);

    // Activate the IP updater cron
    if (! wp_next_scheduled('Torminator_IP_updater_cron')) {
        wp_schedule_event(time(), 'daily', 'Torminator_IP_updater_cron');
    }
}

function Torminator_deactivate()
{
    // Deactivate the IP updater cron
    $timestamp = wp_next_scheduled('Torminator_IP_updater_cron');

    if ($timestamp) {
        wp_unschedule_event($timestamp, 'Torminator_IP_updater_cron');
    }
}

function Torminator_uninstall()
{
    global $wpdb, $Torminator_table_name;

    $table_name = $wpdb->prefix . 'torminator_ip_addresses';

    // Delete IPs table
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query(
        $wpdb->prepare(
            "DROP TABLE IF EXISTS %s", $table_name
        )
    );
}

register_activation_hook(__FILE__, 'Torminator_activate');
register_deactivation_hook(__FILE__, 'Torminator_deactivate');
register_uninstall_hook(__FILE__, 'Torminator_uninstall');

add_action('plugins_loaded', 'Torminator_main');
add_action('Torminator_IP_updater_cron', 'Torminator_updateIpAddresses');
