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

// --- Domæne-autorisation (MIDLERTIDIGT DEAKTIVERET) ---
function idguard_is_authorized_domain() {
    // MIDLERTIDIGT: Returner altid true for at tillade alle domæner
    return true;
    
    /*
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
    */
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

function idguard_init() {
    // Indlæs kun script på WooCommerce checkout-siden OG hvis domænet er autoriseret
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    if (!idguard_is_authorized_domain()) {
        return;
    }
    $checkout_url = wc_get_checkout_url();
    $cart_url = wc_get_cart_url();
    $script_url = plugins_url('idguard.js', __FILE__);
    wp_enqueue_script('idguard-script', $script_url, [], '1.1.0', true);
    $nonce = wp_create_nonce('idguard_nonce');
    $customization = [
        'popupTitle' => get_option('idguard_popup_title', 'Din ordre indeholder aldersbegrænsede varer'),
        'popupMessage' => get_option('idguard_popup_message', 'Den danske lovgivning kræver at vi kontrollerer din alder med MitID inden du kan købe aldersbegrænsede varer.'),
        'confirmButton' => get_option('idguard_popup_button_text', 'Fortsæt købet'),
        'cancelButton' => get_option('idguard_popup_cancel_button_text', 'Gå tilbage'),
        'popupTextColor' => get_option('idguard_popup_text_color', '#000000'),
        'popupBackgroundColor' => get_option('idguard_popup_background_color', '#ffffff'),
        'popupVerifyButtonColor' => get_option('idguard_popup_verify_button_color', '#004cb8'),
        'popupVerifyButtonTextColor' => get_option('idguard_popup_verify_button_text_color', '#ffffff'),
        'popupCancelButtonColor' => get_option('idguard_popup_cancel_button_color', '#d6d6d6'),
        'popupCancelButtonTextColor' => get_option('idguard_popup_cancel_button_text_color', '#000000'),
        'cancelRedirectOption' => get_option('idguard_cancel_redirect_option', 'cart'),
        'customCancelUrl' => get_option('idguard_custom_cancel_url', '')
    ];
    $is_order_received = is_wc_endpoint_url('order-received');
    wp_localize_script('idguard-script', 'idguardData', [
        'pluginUrl' => plugins_url('', __FILE__),
        'checkoutUrl' => $checkout_url,
        'cartUrl' => $cart_url,
        'requiredAge' => idguard_get_required_age_for_verification(),
        'nonce' => $nonce,
        'customization' => $customization,
        'isOrderReceivedPage' => $is_order_received
    ]);
}
add_action('wp_enqueue_scripts', 'idguard_init');

// --- Admin-menu ---
function idguard_add_admin_menu() {
    add_menu_page(
        __('Indstillinger', 'idguard'),
        __('IDguard', 'idguard'),
        'manage_options',
        'idguard',
        'idguard_settings_page',
        'dashicons-shield',
        100
    );
    add_submenu_page(
        'idguard',
        __('Design & Tekster', 'idguard'),
        __('Design & Tekster', 'idguard'),
        'manage_options',
        'idguard_popup',
        'idguard_popup_page'
    );
    add_submenu_page(
        'idguard',
        __('Dokumentation & Hjælp', 'idguard'),
        __('Dokumentation & Hjælp', 'idguard'),
        'manage_options',
        'idguard_documentation',
        'idguard_documentation_page'
    );
    add_submenu_page(
        'idguard',
        __('Kundeservice', 'idguard'),
        __('Kundeservice', 'idguard'),
        'manage_options',
        'idguard_support',
        'idguard_support_page'
    );
}
add_action('admin_menu', 'idguard_add_admin_menu', 20);

// Register settings
function idguard_register_settings() {
    // General settings
    register_setting('idguard_general_settings', 'idguard_age_verification_mode');
    register_setting('idguard_general_settings', 'idguard_global_age_limit');

    // Popup settings
    register_setting('idguard_popup_settings', 'idguard_popup_text_color');
    register_setting('idguard_popup_settings', 'idguard_popup_background_color');
    register_setting('idguard_popup_settings', 'idguard_popup_verify_button_color');
    register_setting('idguard_popup_settings', 'idguard_popup_cancel_button_color');
    register_setting('idguard_popup_settings', 'idguard_popup_verify_button_text_color');
    register_setting('idguard_popup_settings', 'idguard_popup_cancel_button_text_color');
    register_setting('idguard_popup_settings', 'idguard_popup_title');
    register_setting('idguard_popup_settings', 'idguard_popup_message');
    register_setting('idguard_popup_settings', 'idguard_popup_button_text');
    register_setting('idguard_popup_settings', 'idguard_popup_cancel_button_text');
    register_setting('idguard_popup_settings', 'idguard_cancel_redirect_option');
    register_setting('idguard_popup_settings', 'idguard_custom_cancel_url');
}
add_action('admin_init', 'idguard_register_settings');

// Settings page content
function idguard_settings_page() {
    ?>
    <div class="wrap">
        <h1><span style="color:#004cb8;">⛨</span> <?php _e('IDguard Indstillinger', 'idguard'); ?></h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('idguard_general_settings');
                do_settings_sections('idguard_general_settings');
            ?>
            <h2><?php _e('Vælg aldersverifikationsmetode', 'idguard'); ?></h2>
            <input type="hidden" name="idguard_age_verification_mode" id="idguard_age_verification_mode" value="<?php echo esc_attr(get_option('idguard_age_verification_mode', 'off')); ?>">
            <?php submit_button(__('Gem indstillinger', 'idguard'), 'primary', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;')); ?>
        </form>
    </div>
    <?php
}

function idguard_popup_page() {
    ?>
    <div class="wrap">
        <h1><span style="color:#004cb8;">⛨</span> <?php _e('IDguard Popup Indstillinger', 'idguard'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('idguard_popup_settings');
            do_settings_sections('idguard_popup_settings');
            ?>
            <?php submit_button(__('Gem indstillinger', 'idguard'), 'primary', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;')); ?>
        </form>
    </div>
    <?php
}

function idguard_documentation_page() {
    ?>
    <div class="wrap">
        <h1>📖 <?php _e('Dokumentation & Hjælp', 'idguard'); ?></h1>
        <p><?php _e('Her finder du dokumentation for IDguard plugin.', 'idguard'); ?></p>
    </div>
    <?php
}

function idguard_support_page() {
    ?>
    <div class="wrap">
        <h1>🛟 <?php _e('Kundeservice', 'idguard'); ?></h1>
        <p><?php _e('Kontakt os på kontakt@arpecompany.dk for support.', 'idguard'); ?></p>
    </div>
    <?php
}

// Get the required age for verification
function idguard_get_required_age_for_verification() {
    return false; // Simplified for now
}
