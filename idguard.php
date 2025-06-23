<?php
/**
 * Plugin Name: IDguard
 * Plugin URI: https://idguard.dk
 * Description: Foretag automatisk alderstjek med MitID ved betaling på WooCommerce-webshops
 * Version: 2.1.1.61
 * Author: IDguard
 * Author URI: https://idguard.dk
 * Text Domain: idguard
 */

if (!defined('ABSPATH')) {
    exit; // Forhindrer direkte adgang
}

// Hjælpefunktion til dansk tekst
if (!function_exists('idguard_dk_text')) {
    function idguard_dk_text($text) {
        return __($text, 'idguard');
    }
}

define('IDGUARD_MIN_PHP_VER', '5.6');
define('IDGUARD_MIN_WP_VER', '5.0');
define('IDGUARD_MIN_WC_VER', '4.0');

// --- Domæne-autorisation ---
function idguard_is_authorized_domain() {
    $domain = parse_url(home_url(), PHP_URL_HOST);
    $transient_key = 'idguard_domain_auth_' . md5($domain);
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        error_log('[IDguard] Domæne: ' . $domain . ' (cached: ' . $cached . ')');
        return $cached === '1';
    }
    $response = wp_remote_get('https://assets.idguard.dk/api/authorize', ['timeout' => 8]);
    $authorized = false;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        error_log('[IDguard] Domæne: ' . $domain . ' | Svar fra server: ' . $body);
        if (isset($data['authorized']) && $data['authorized'] === true) {
            $authorized = true;
        }
    } else {
        error_log('[IDguard] Domæne: ' . $domain . ' | FEJL ved kontakt til server.');
    }
    set_transient($transient_key, $authorized ? '1' : '0', 60*30); // 30 min cache
    return $authorized;
}

// Redirect to the settings page after activation
function idguard_redirect_after_activation() {
    if (get_option('idguard_plugin_activated', false)) {
        delete_option('idguard_plugin_activated');
        wp_redirect(admin_url('admin.php?page=idguard'));
        exit;
    }
}
add_action('admin_init', 'idguard_redirect_after_activation');

// Hook to add settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'my_custom_plugin_settings_link');

// Handle update completion properly
add_action('upgrader_process_complete', function($upgrader, $options) {
    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        if (isset($options['plugins']) && in_array(plugin_basename(__FILE__), $options['plugins'])) {
            // Reactivate the plugin after update
            activate_plugin(plugin_basename(__FILE__));
        }
    }
}, 10, 2);

function my_custom_plugin_settings_link($links) {
    // Vis kun link hvis domænet er autoriseret
    if (function_exists('idguard_is_authorized_domain') && idguard_is_authorized_domain()) {
        $settings_link = '<a href="' . admin_url('admin.php?page=idguard') . '">' . idguard_dk_text('⛨ Konfigurer IDguard') . '</a>';
        array_unshift($links, $settings_link);
    }
    return $links;
}

// Load the plugin text domain for translations (TODO: Add DK language)
function idguard_load_textdomain() {
    load_plugin_textdomain('idguard', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'idguard_load_textdomain');

// Hook to save the id_token with the order
add_action('woocommerce_checkout_update_order_meta', 'save_id_token_with_order');
function save_id_token_with_order($order_id) {
    if (isset($_POST['id_token'])) {
        update_post_meta($order_id, '_id_token', sanitize_text_field($_POST['id_token']));
    }
}

// Display the id_token in the order admin panel
add_action('woocommerce_admin_order_data_after_order_details', 'display_id_token_in_admin_order_meta');
function display_id_token_in_admin_order_meta($order) {
    $id_token = get_post_meta($order->get_id(), '_id_token', true);
    if ($id_token) {
        echo '<p><strong>' . idguard_dk_text('ID Token') . ':</strong> ' . esc_html($id_token) . '</p>';
    }
}

// Function to display the admin notice
function idguard_admin_notice() {
    // Vis kun hvis domænet er autoriseret
    if (!idguard_is_authorized_domain()) return;
    if ( ! get_option('dismissed-idguard_notice', false) ) {
        ?>
        <div class="notice notice-success is-dismissible" data-notice="idguard_notice">
            <p><?php _e('🎉 Tak fordi du har valgt IDguard! Gennemgå venligst dine indstillinger for at sikre, at alt er sat korrekt op.', 'idguard'); ?></p>
            <p>
                <a href="<?php echo admin_url('options-general.php?page=idguard'); ?>" class="button button-primary">
                    <?php _e('Konfigurer IDguard', 'idguard'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'idguard_admin_notice');

// Pænere og mere informativ "ikke autoriseret" notice
add_action('admin_notices', function() {
    if (!idguard_is_authorized_domain()) {
        echo '<style>
        .idguard-unauth-notice {
            background: linear-gradient(90deg, #fff0f0 0%, #ffeaea 100%);
            border-left: 6px solid #cf1322;
            border-radius: 7px;
            box-shadow: 0 2px 12px rgba(207,19,34,0.07);
            padding: 22px 28px 18px 28px;
            margin: 30px 0 25px 0;
            font-size: 1.08em;
            color: #222;
        }
        .idguard-unauth-notice h2 {
            color: #cf1322;
            margin-top: 0;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            gap: 0.5em;
        }
        .idguard-unauth-notice .idguard-support-btn {
            background: #cf1322;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 0.6em 1.5em;
            font-size: 1em;
            margin-top: 12px;
            text-decoration: none;
            display: inline-block;
        }
        .idguard-unauth-notice .idguard-support-btn:hover {
            background: #a50e1a;
        }
        </style>';
        echo '<div class="idguard-unauth-notice">
            <h2>🔒 IDguard er ikke autoriseret til dette domæne</h2>
            <p><b>Plugin-funktionalitet er deaktiveret.</b></p>
            <ul style="margin: 12px 0 0 18px;">
                <li>Dette domæne er ikke godkendt til brug af IDguard.</li>
                <li>Kontakt support for at få adgang eller høre mere om licens.</li>
            </ul>
            <a href="mailto:kontakt@arpecompany.dk" class="idguard-support-btn">📬 Kontakt support</a>
        </div>';
    }
});
