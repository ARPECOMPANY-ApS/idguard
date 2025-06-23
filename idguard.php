<?php
/**
 * Plugin Name: IDguard
 * Plugin URI: https://idguard.dk
 * Description: Foretag automatisk alderstjek med MitID ved betaling på WooCommerce-webshops
 * Version: 2.1.1.3
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
    $settings_link = '<a href="' . admin_url('admin.php?page=idguard') . '">' . idguard_dk_text('⛨ Konfigurer IDguard') . '</a>';
    array_unshift($links, $settings_link); // Add the link to the beginning of the array
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

// jQuery to handle the dismissal of the notice
add_action('admin_footer', function() {
    ?>
    <script type="text/javascript">
        jQuery(function($) {
            $(document).on('click', '.notice-dismiss', function() {
                var type = $(this).closest('.notice').data('notice');
                $.ajax(ajaxurl, {
                    type: 'POST',
                    data: {
                        action: 'dismissed_notice_handler',
                        type: type,
                        idguard_nonce: '<?php echo wp_create_nonce('idguard_nonce'); ?>'
                    }
                });
            });
        });
    </script>
    <?php
});

// AJAX handler to store the state of dismissible notices
add_action('wp_ajax_dismissed_notice_handler', 'idguard_ajax_notice_handler');

function idguard_ajax_notice_handler() {
    // Nonce check for security
    if (!isset($_POST['idguard_nonce']) || !wp_verify_nonce($_POST['idguard_nonce'], 'idguard_nonce')) {
        wp_send_json_error(__('Sikkerhedsfejl: ugyldig forespørgsel.', 'idguard'));
        wp_die();
    }
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    if ($type) {
        update_option('dismissed-' . $type, true);
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Mangler type-parameter.', 'idguard'));
    }
    wp_die();
}

// Redirect to settings page upon plugin activation
function idguard_activate() {
    try {
        // Initialize options
        add_option('idguard_plugin_activated', true);
        add_option('dismissed-idguard_notice', false);
        
        // Verify WooCommerce is active
        if (!class_exists('WooCommerce')) {
            throw new Exception('WooCommerce is required for IDguard to function');
        }
        
        // Verify minimum PHP version
        if (version_compare(PHP_VERSION, '5.6', '<')) {
            throw new Exception('IDguard requires PHP 5.6 or higher');
        }
        
        // Add a transient to flag successful activation
        set_transient('idguard_activated', true, 30);
        
    } catch (Exception $e) {
        // Log the error but don't prevent activation
        error_log('IDguard activation notice: ' . $e->getMessage());
        update_option('idguard_activation_error', $e->getMessage());
    }
}
register_activation_hook(__FILE__, 'idguard_activate');

function idguard_init() {
    // Indlæs kun script på WooCommerce checkout-siden
    if (!function_exists('is_checkout') || !is_checkout()) {
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

// --- Domæne-autorisation ---
function idguard_is_authorized_domain() {
    $domain = parse_url(home_url(), PHP_URL_HOST);
    $transient_key = 'idguard_domain_auth_' . md5($domain);
    $cached = get_transient($transient_key);
    if ($cached !== false) return $cached === '1';
    $response = wp_remote_get('https://assets.idguard.dk/api/authorize?domain=' . urlencode($domain), ['timeout' => 8]);
    $authorized = false;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['authorized']) && $data['authorized'] === true) {
            $authorized = true;
        }
    }
    set_transient($transient_key, $authorized ? '1' : '0', 60*30); // 30 min cache
    return $authorized;
}
add_action('admin_notices', function() {
    if (!idguard_is_authorized_domain()) {
        echo '<div class="notice notice-error"><strong>🔒 IDguard er ikke autoriseret til dette domæne.</strong><br>Kontakt support for at få adgang. Plugin-funktionalitet er deaktiveret.</div>';
    }
});
function idguard_block_if_not_authorized() {
    if (!idguard_is_authorized_domain()) {
        if (is_admin()) {
            add_action('admin_menu', function() {
                remove_menu_page('idguard');
                add_menu_page('IDguard', 'IDguard', 'manage_options', 'idguard_unauthorized', function() {
                    echo '<div class="wrap"><h1>🔒 IDguard ikke autoriseret</h1><p>Dette domæne er ikke godkendt til brug af IDguard. Kontakt <a href="mailto:kontakt@arpecompany.dk">support</a> for at få adgang.</p></div>';
                }, 'dashicons-shield', 100);
            }, 99);
        }
        // Stop videre hooks/funktioner
        return false;
    }
    return true;
}
if (!idguard_block_if_not_authorized()) return;
// --- Forbedret admin-menu ---
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

function idguard_enqueue_admin_scripts($hook) {
    if ($hook == 'toplevel_page_idguard' || $hook == 'idguard_page_idguard_popup') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker', array('jquery', 'wp-color-picker'), false, null, true);
        
        // Add this script to initialize color pickers
        wp_add_inline_script('wp-color-picker', '
            jQuery(document).ready(function($) {
                $(".my-color-field").wpColorPicker();
            });
        ');
    }
}
add_action('admin_enqueue_scripts', 'idguard_enqueue_admin_scripts');

add_action('admin_notices', function() {
    if ($error = get_option('idguard_activation_error', false)) {
        echo '<div class="error"><p>IDguard activation notice: ' . esc_html($error) . '</p></div>';
        delete_option('idguard_activation_error');
    }
});

function idguard_popup_page() {
    // Get existing settings with proper defaults
    $popup_text_color = get_option('idguard_popup_text_color', '#000000');
    $popup_background_color = get_option('idguard_popup_background_color', '#ffffff');
    $popup_verify_button_color = get_option('idguard_popup_verify_button_color', '#004cb8');
    $popup_verify_button_text_color = get_option('idguard_popup_verify_button_text_color', '#ffffff');
    $popup_cancel_button_color = get_option('idguard_popup_cancel_button_color', '#f44336');
    $popup_cancel_button_text_color = get_option('idguard_popup_cancel_button_text_color', '#ffffff');
    $cancel_redirect_option = get_option('idguard_cancel_redirect_option', 'cart');
    $custom_cancel_url = get_option('idguard_custom_cancel_url', '');
    $popup_title = get_option('idguard_popup_title', 'Din ordre indeholder aldersbegrænsede varer');
    $popup_message = get_option('idguard_popup_message', 'Den danske lovgivning kræver at vi kontrollerer din alder med MitID inden du kan købe aldersbegrænsede varer.');
    $popup_button_text = get_option('idguard_popup_button_text', 'Fortsæt købet');
    $popup_cancel_button_text = get_option('idguard_popup_cancel_button_text', 'Gå tilbage');
    ?>
    <style>
        .idguard-onboarding {
            background: #e6f7ff;
            border-left: 5px solid #0091ea;
            padding: 18px 20px;
            margin-bottom: 25px;
            border-radius: 6px;
        }
        .idguard-section {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 30px;
            padding: 20px 25px 10px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .idguard-section h2 {
            margin-top: 0;
            color: #004cb8;
        }
        .idguard-helper {
            color: #666;
            font-size: 0.97em;
            margin-bottom: 10px;
        }
        .idguard-label {
            font-weight: 600;
        }
        .idguard-preview-btn {
            font-size: 1.1em;
            padding: 0.7em 2em;
            background: #004cb8;
            color: #fff;
            border-radius: 5px;
            border: none;
            margin-bottom: 20px;
            cursor: pointer;
        }
        .idguard-preview-btn:hover {
            background: #003a8c;
        }
        #idguard-popup-preview {
            z-index: 99999;
        }
    </style>
    <div class="wrap">
        <h1><span style="color:#004cb8;">⛨</span> <?php _e('IDguard Popup Indstillinger', 'idguard'); ?></h1>
        <div class="idguard-onboarding">
            <strong><?php _e('Sådan kommer du i gang:', 'idguard'); ?></strong>
            <ol style="margin-top:8px;">
                <li><?php _e('Tilpas popup-udseende og tekster nedenfor.', 'idguard'); ?></li>
                <li><?php _e('Klik på <b>Forhåndsvis popup</b> for at se, hvordan det ser ud for dine kunder.', 'idguard'); ?></li>
                <li><?php _e('Gem indstillingerne nederst på siden.', 'idguard'); ?></li>
                <li><?php _e('Test i din webshop for at sikre korrekt integration.', 'idguard'); ?></li>
            </ol>
            <div style="margin-top:10px;">
                <a href="<?php echo admin_url('admin.php?page=idguard_documentation'); ?>" class="button-secondary" style="margin-right:10px;">📖 <?php _e('Se dokumentation', 'idguard'); ?></a>
                <a href="mailto:kontakt@arpecompany.dk" class="button-secondary">📬 <?php _e('Kontakt support', 'idguard'); ?></a>
            </div>
        </div>
        <button type="button" class="idguard-preview-btn" id="idguard-preview-popup">
            <?php _e('Forhåndsvis popup', 'idguard'); ?>
        </button>
        <form method="post" action="options.php">
            <?php
            settings_fields('idguard_popup_settings');
            do_settings_sections('idguard_popup_settings');
            ?>
            <div class="idguard-section">
                <h2>🎨 <?php _e('Udseende af popup', 'idguard'); ?></h2>
                <div class="idguard-helper"><?php _e('Tilpas farver og knapper, så de matcher din butiks design og branding.', 'idguard'); ?></div>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Tekstfarve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_text_color" name="idguard_popup_text_color" value="<?php echo esc_attr($popup_text_color); ?>" class="my-color-field" data-default-color="#000000" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Baggrundsfarve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_background_color" name="idguard_popup_background_color" value="<?php echo esc_attr($popup_background_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Bekræft-knap farve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_verify_button_color" name="idguard_popup_verify_button_color" value="<?php echo esc_attr($popup_verify_button_color); ?>" class="my-color-field" data-default-color="#004cb8" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Bekræft-knap tekstfarve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_verify_button_text_color" name="idguard_popup_verify_button_text_color" value="<?php echo esc_attr($popup_verify_button_text_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Annuller-knap farve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_cancel_button_color" name="idguard_popup_cancel_button_color" value="<?php echo esc_attr($popup_cancel_button_color); ?>" class="my-color-field" data-default-color="#f44336" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Annuller-knap tekstfarve', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_cancel_button_text_color" name="idguard_popup_cancel_button_text_color" value="<?php echo esc_attr($popup_cancel_button_text_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                    </tr>
                </table>
            </div>
            <div class="idguard-section">
                <h2>📝 <?php _e('Popup tekstindstillinger', 'idguard'); ?></h2>
                <div class="idguard-helper"><?php _e('Rediger teksterne, der vises i popup-vinduet. Gør det tydeligt for kunden, hvorfor aldersbekræftelse er nødvendig.', 'idguard'); ?></div>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Popup titel', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_title" name="idguard_popup_title" value="<?php echo esc_attr($popup_title); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Popup besked', 'idguard'); ?></th>
                        <td><textarea id="idguard_popup_message" name="idguard_popup_message" rows="5" cols="50"><?php echo esc_textarea($popup_message); ?></textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Bekræft-knap tekst', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_button_text" name="idguard_popup_button_text" value="<?php echo esc_attr($popup_button_text); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Annuller-knap tekst', 'idguard'); ?></th>
                        <td><input type="text" id="idguard_popup_cancel_button_text" name="idguard_popup_cancel_button_text" value="<?php echo esc_attr($popup_cancel_button_text); ?>" /></td>
                    </tr>
                </table>
            </div>
            <div class="idguard-section">
                <h2>🔗 <?php _e('Indstillinger for annuller-knap redirect', 'idguard'); ?></h2>
                <div class="idguard-helper"><?php _e('Vælg hvor kunden sendes hen, hvis de vælger at annullere aldersbekræftelsen.', 'idguard'); ?></div>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" class="idguard-label"><?php _e('Redirect til', 'idguard'); ?></th>
                        <td>
                            <select name="idguard_cancel_redirect_option" id="idguard_cancel_redirect_option">
                                <option value="home" <?php selected($cancel_redirect_option, 'home'); ?>><?php _e('Forside', 'idguard'); ?></option>
                                <option value="cart" <?php selected($cancel_redirect_option, 'cart'); ?>><?php _e('Kurv', 'idguard'); ?></option>
                                <option value="custom" <?php selected($cancel_redirect_option, 'custom'); ?>><?php _e('Egen URL', 'idguard'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top" id="custom_url_row" style="<?php echo ($cancel_redirect_option === 'custom') ? '' : 'display: none;'; ?>">
                        <th scope="row" class="idguard-label"><?php _e('Egen URL', 'idguard'); ?></th>
                        <td>
                            <input type="text" name="idguard_custom_cancel_url" value="<?php echo esc_attr($custom_cancel_url); ?>" />
                            <p class="description"><?php _e('Indtast en relativ URL (fx /shop eller /kontakt) eller en absolut URL (fx https://eksempel.dk/shop)', 'idguard'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php submit_button(__('Gem indstillinger', 'idguard'), 'primary', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;')); ?>
        </form>
        <div id="idguard-popup-preview" style="display:none;"></div>
    </div>
    <script>
    jQuery(function($){
        $('#idguard-preview-popup').on('click', function(){
            // Hent aktuelle værdier fra felterne
            var title = $('#idguard_popup_title').val();
            var message = $('#idguard_popup_message').val();
            var confirmText = $('#idguard_popup_button_text').val();
            var cancelText = $('#idguard_popup_cancel_button_text').val();
            var textColor = $('#idguard_popup_text_color').val();
            var bgColor = $('#idguard_popup_background_color').val();
            var verifyBtnColor = $('#idguard_popup_verify_button_color').val();
            var verifyBtnTextColor = $('#idguard_popup_verify_button_text_color').val();
            var cancelBtnColor = $('#idguard_popup_cancel_button_color').val();
            var cancelBtnTextColor = $('#idguard_popup_cancel_button_text_color').val();
            // Byg popup HTML så det matcher frontend
            var html = '<div id="idguard-popup-demo" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;">'+
                '<div role="dialog" aria-modal="true" aria-label="Aldersbekræftelse" tabindex="-1" style="background:'+bgColor+';color:'+textColor+';padding:2em 1.5em 1.5em 1.5em;border-radius:12px;max-width:400px;width:95%;box-shadow:0 4px 32px rgba(0,0,0,0.18);text-align:center;position:relative;">'+
                '<button aria-label="Luk" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:2em;line-height:1;color:'+textColor+';cursor:pointer;">&times;</button>'+ 
                '<h2 style="color:'+textColor+';font-size:1.3em;margin-bottom:1em;">'+title+'</h2>'+ 
                '<p style="margin-bottom:1.5em;">'+message+'</p>'+ 
                '<div id="idguard-popup-error" style="display:none;color:#c00;margin-bottom:1em;"></div>'+ 
                '<button class="idguard-popup-confirm" style="margin-bottom:1em;width:100%;font-size:1.1em;padding:0.7em;background:'+verifyBtnColor+';color:'+verifyBtnTextColor+';border:none;border-radius:5px;cursor:pointer;">'+confirmText+'</button>'+ 
                '<button class="idguard-popup-cancel" style="width:100%;font-size:1.1em;padding:0.7em;background:'+cancelBtnColor+';color:'+cancelBtnTextColor+';border:none;border-radius:5px;cursor:pointer;">'+cancelText+'</button>'+ 
                '<div id="idguard-popup-loading" style="display:none;margin-top:1em;"><span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Vent venligst...', 'idguard')); ?></div>'+ 
                '</div></div>';
            $('#idguard-popup-preview').html(html).show();
        });
        $(document).on('click', '#idguard-popup-demo button[aria-label="Luk"]', function(){
            $('#idguard-popup-preview').hide().empty();
        });
        // Luk popup hvis man klikker udenfor
        $(document).on('mousedown', function(e){
            var popup = $('#idguard-popup-demo > div');
            if($('#idguard-popup-demo').is(':visible') && !$(e.target).closest(popup).length && !$(e.target).is('#idguard-preview-popup')){
                $('#idguard-popup-preview').hide().empty();
            }
        });
    });
    </script>
    <?php
}

function idguard_documentation_page() {
    $domain = parse_url(home_url(), PHP_URL_HOST);
    $is_auth = idguard_is_authorized_domain();
    echo '<div class="idguard-documentation">';
    echo '<h1>📖 ' . __('Dokumentation & Hjælp', 'idguard') . '</h1>';
    if (!$is_auth) {
        echo '<div class="notice notice-error" style="margin-bottom:20px;"><strong>🔒 Dette domæne er ikke autoriseret til IDguard.</strong><br>Kontakt <a href="mailto:kontakt@arpecompany.dk">support</a> for at få adgang.</div>';
    } else {
        echo '<div class="idguard-doc-section" style="background:#e6f7ff;padding:18px 20px;border-radius:6px;margin-bottom:25px;">';
        echo '<strong>Velkommen til IDguard!</strong> Her finder du alt om opsætning, brug, fejlfinding og support.';
        echo '</div>';
    }
    echo '<section class="idguard-doc-section"><h2>🚀 ' . __('Kom godt i gang', 'idguard') . '</h2>';
    echo '<ol>';
    echo '<li><b>Installer og aktiver plugin.</b></li>';
    echo '<li><b>Autoriser dit domæne</b> (kontakt support hvis nødvendigt).</li>';
    echo '<li><b>Gå til <a href="admin.php?page=idguard">Indstillinger</a> og vælg ønsket aldersverifikation.</b></li>';
    echo '<li><b>Tilpas popup under <a href="admin.php?page=idguard_popup">Design & Tekster</a>.</b></li>';
    echo '<li><b>Test integrationen i din webshop.</b></li>';
    echo '</ol></section>';
    echo '<section class="idguard-doc-section"><h2>⚙️ ' . __('Installation & Krav', 'idguard') . '</h2>';
    echo '<ul>';
    echo '<li>WordPress 5.0 eller nyere</li>';
    echo '<li>WooCommerce 4.0 eller nyere</li>';
    echo '<li>PHP 5.6 eller nyere</li>';
    echo '<li>Domænet skal være autoriseret</li>';
    echo '</ul></section>';
    echo '<section class="idguard-doc-section"><h2>🛠️ ' . __('Brug & Funktioner', 'idguard') . '</h2>';
    echo '<ul>';
    echo '<li>Aldersverifikation med MitID på checkout</li>';
    echo '<li>Indstil aldersgrænser på produkter og kategorier</li>';
    echo '<li>Tilpas popupens design og tekster</li>';
    echo '<li>Se ID-token på ordrer</li>';
    echo '</ul></section>';
    echo '<section class="idguard-doc-section"><h2>❓ ' . __('Ofte stillede spørgsmål (FAQ)', 'idguard') . '</h2>';
    echo '<ul>';
    echo '<li><b>Mit domæne er ikke autoriseret?</b> Kontakt support for at få adgang.</li>';
    echo '<li><b>Popup vises ikke?</b> Tjek at aldersverifikation er aktiveret og popup er korrekt opsat.</li>';
    echo '<li><b>Hvordan ændrer jeg tekster og farver?</b> Gå til <a href="admin.php?page=idguard_popup">Design & Tekster</a>.</li>';
    echo '<li><b>Hvordan får jeg support?</b> Se fanen "Kundeservice".</li>';
    echo '</ul></section>';
    echo '<section class="idguard-doc-section"><h2>🧑‍💻 ' . __('Fejlfinding', 'idguard') . '</h2>';
    echo '<ul>';
    echo '<li>Tjek at domænet er autoriseret</li>';
    echo '<li>Genindlæs siden og tøm cache</li>';
    echo '<li>Deaktiver andre plugins midlertidigt</li>';
    echo '<li>Kontakt support hvis problemet fortsætter</li>';
    echo '</ul></section>';
    echo '<section class="idguard-doc-section"><h2>📬 ' . __('Kontakt & Support', 'idguard') . '</h2>';
    echo '<p>Mail: <a href="mailto:kontakt@arpecompany.dk">kontakt@arpecompany.dk</a><br>Telefon: +45 12 34 56 78<br>FAQ og guides: <a href="https://idguard.dk">idguard.dk</a></p>';
    echo '</section>';
    echo '</div>';
}
function idguard_support_page() {
    $domain = parse_url(home_url(), PHP_URL_HOST);
    $is_auth = idguard_is_authorized_domain();
    ?>
    <style>
        .support-section {
            margin: 20px 0;
            padding: 18px 20px;
            background-color: #e6f7ff;
            border: 1px solid #0091ea;
            border-radius: 6px;
        }
        .support-title {
            font-weight: bold;
            font-size: 1.5em;
            margin-bottom: 10px;
        }
        .support-link {
            display: block;
            margin: 5px 0;
            color: #0073aa;
            text-decoration: none;
        }
        .support-link:hover {
            text-decoration: underline;
        }
        .idguard-status {
            margin: 18px 0 10px 0;
            padding: 12px 16px;
            border-radius: 5px;
            background: #fffbe6;
            border: 1px solid #ffe58f;
            color: #ad8b00;
        }
        .idguard-status.ok {
            background: #f6ffed;
            border-color: #b7eb8f;
            color: #389e0d;
        }
        .idguard-status.err {
            background: #fff1f0;
            border-color: #ffa39e;
            color: #cf1322;
        }
    </style>
    <div class="wrap">
        <h1>🛟 <?php _e('Kundeservice', 'idguard'); ?></h1>
        <div class="support-section">
            <div class="support-title"><?php _e('Har du brug for hjælp?', 'idguard'); ?></div>
            <div class="idguard-status <?php echo $is_auth ? 'ok' : 'err'; ?>">
                <?php if ($is_auth) {
                    echo '✅ Dit domæne er autoriseret til IDguard.';
                } else {
                    echo '❌ Dette domæne er <b>ikke</b> autoriseret. Kontakt <a href="mailto:kontakt@arpecompany.dk">support</a> for at få adgang.';
                } ?>
            </div>
            <p><?php _e('Vi er her for at hjælpe dig! Følg disse trin:', 'idguard'); ?></p>
            <ol>
                <li>Læs <a href="admin.php?page=idguard_documentation">dokumentationen</a> og FAQ.</li>
                <li>Tjek at dit domæne er autoriseret.</li>
                <li>Kontakt os hvis du har brug for hjælp.</li>
            </ol>
            <a href="mailto:kontakt@arpecompany.dk" class="support-link">📬 kontakt@arpecompany.dk</a>
            <a href="https://idguard.dk" class="support-link" target="_blank">🌐 idguard.dk</a>
        </div>
        <div class="support-section">
            <div class="support-title"><?php _e('Ofte stillede spørgsmål', 'idguard'); ?></div>
            <ul>
                <li><b>Hvordan får jeg adgang?</b> Kontakt support for autorisation.</li>
                <li><b>Hvordan ændrer jeg popup?</b> Gå til "Design & Tekster".</li>
                <li><b>Hvordan ser jeg ID-token?</b> På ordredetaljer i WooCommerce.</li>
                <li><b>Hvordan får jeg teknisk hjælp?</b> Skriv til kontakt@arpecompany.dk</li>
            </ul>
        </div>
    </div>
    <?php
}

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

// Add age limit dropdown to products
add_action('woocommerce_product_options_general_product_data', 'idguard_add_age_limit_to_products');
function idguard_add_age_limit_to_products() {
    global $post;

    // Add age limit dropdown
    woocommerce_wp_select(array(
        'id' => 'idguard_age_limit',
        'label' => __('Aldersgrænse', 'idguard'),
        'options' => array(
            '' => __('Ingen aldersgrænse sat', 'idguard'),
            '15' => '15+',
            '16' => '16+',
            '18' => '18+',
            '21' => '21+',
        ),
        'desc_tip' => true,
        'description' => __('Vælg aldersgrænsen for dette produkt.', 'idguard'),
    ));
}
// Save the product's age limit
add_action('woocommerce_process_product_meta', 'idguard_save_product_age_limit');
function idguard_save_product_age_limit($post_id) {
    $age_limit = isset($_POST['idguard_age_limit']) ? $_POST['idguard_age_limit'] : '';
    update_post_meta($post_id, 'idguard_age_limit', sanitize_text_field($age_limit));
}

// Save the product's age verification requirement
function idguard_save_product_meta($post_id) {
    $age_check = isset($_POST['idguard_age_check']) ? 'yes' : 'no';
    update_post_meta($post_id, 'idguard_age_check', $age_check);
}
add_action('woocommerce_process_product_meta', 'idguard_save_product_meta');

// Add a custom field to WooCommerce categories
function idguard_add_category_field() {
    ?>
    <div class="form-field">
        <?php
        woocommerce_wp_select(array(
            'id' => 'idguard_age_limit_category',
            'label' => __('Aldersgrænse', 'idguard'),
            'options' => array(
                '' => __('Ingen aldersgrænse sat', 'idguard'),
                '15' => '15+',
                '16' => '16+',
                '18' => '18+',
                '21' => '21+',
            ),
            'desc_tip' => true,
            'description' => __('Vælg aldersgrænsen for denne kategori.', 'idguard'),
        ));
        ?>
    </div>
    <?php
}
add_action('product_cat_add_form_fields', 'idguard_add_category_field');

// Add the field to the category edit form
function idguard_edit_category_field($term) {
    $age_limit = get_term_meta($term->term_id, 'idguard_age_limit_category', true);
    ?>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="idguard_age_limit_category"><?php _e('Aldersgrænse', 'idguard'); ?></label></th>
        <td>
            <?php
            woocommerce_wp_select(array(
                'id' => 'idguard_age_limit_category',
                'label' => '', // Retained to avoid undefined array key error
                'options' => array(
                    '' => __('Ingen aldersgrænse sat', 'idguard'),
                    '15' => '15+',
                    '16' => '16+',
                    '18' => '18+',
                    '21' => '21+',
                ),
                'selected' => true,
                'value' => $age_limit,
                'desc_tip' => true,
                'description' => __('Vælg aldersgrænsen for denne kategori?', 'idguard'), // Added a question mark here
            ));
            ?>
        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'idguard_edit_category_field');

function idguard_save_category_meta($term_id) {
    if (isset($_POST['idguard_age_limit_category'])) {
        $age_limit = sanitize_text_field($_POST['idguard_age_limit_category']);
        update_term_meta($term_id, 'idguard_age_limit_category', $age_limit);
    }
}

add_action('created_product_cat', 'idguard_save_category_meta', 10, 2);
add_action('edited_product_cat', 'idguard_save_category_meta', 10, 2);

// Get the required age for verification
function idguard_get_required_age_for_verification() {
    $verification_mode = get_option('idguard_age_verification_mode', 'off');

    if ($verification_mode === 'off') {
        return false;
    } elseif ($verification_mode === 'global') {
        return get_option('idguard_global_age_limit', '18'); // Return global age limit
    } elseif ($verification_mode === 'category' || $verification_mode === 'product' || $verification_mode === 'category_and_product') {
        if (WC()->cart && WC()->cart->get_cart()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $cart_item['product_id'];
                if ($verification_mode === 'category') {
                    $terms = get_the_terms($product_id, 'product_cat');
                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            $age_limit = get_term_meta($term->term_id, 'idguard_age_limit_category', true); // Fetch category age limit
                            if ($age_limit) {
                                return $age_limit; // Return category age limit
                            }
                        }
                    }
                } elseif ($verification_mode === 'product') {
                    if ($age_limit = get_post_meta($product_id, 'idguard_age_limit', true)) {
                        return $age_limit; // Return product age limit
                    }
                } elseif ($verification_mode === 'category_and_product') {
                    $highest_age_limit = false; // Variable to track the highest age limit
                    $terms = get_the_terms($product_id, 'product_cat');
                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            $age_limit = get_term_meta($term->term_id, 'idguard_age_limit_category', true); // Fetch category age limit
                            if ($age_limit && (!$highest_age_limit || $age_limit > $highest_age_limit)) {
                                $highest_age_limit = $age_limit; // Update highest age limit if found
                            }
                        }
                    }
                    if ($age_limit = get_post_meta($product_id, 'idguard_age_limit', true)) {
                        if (!$highest_age_limit || $age_limit > $highest_age_limit) {
                            $highest_age_limit = $age_limit; // Update highest age limit if found
                        }
                    }
                    return $highest_age_limit; // Return the highest age limit found
                }
            }
        }
    }

    return false; // Default return to false if no age limits are found
}

// Settings page content
function idguard_settings_page() {
    ?>
    <style>
        .instruction-box {
            border: 1px solid #ddd;
            padding: 14px 18px;
            margin-bottom: 15px;
            border-radius: 6px;
            transition: background-color 0.3s, box-shadow 0.3s;
            cursor: pointer;
            background: #f8fafc;
        }
        .instruction-box.selected {
            background-color: #e6f7ff;
            border-color: #0091ea;
            box-shadow: 0px 4px 8px rgba(0, 145, 234, 0.08);
        }
        .instruction-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .age-limit {
            display: none;
        }
        .idguard-onboarding {
            background: #e6f7ff;
            border-left: 5px solid #0091ea;
            padding: 18px 20px;
            margin-bottom: 25px;
            border-radius: 6px;
        }
        .idguard-helper {
            color: #666;
            font-size: 0.97em;
            margin-bottom: 10px;
        }
    </style>
    <script>
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            const boxes = document.querySelectorAll('.instruction-box');
            const hiddenInput = document.getElementById('idguard_age_verification_mode');
            const ageLimitSelect = document.getElementById('idguard_global_age_limit');
            boxes.forEach(box => {
                box.addEventListener('click', () => {
                    boxes.forEach(b => b.classList.remove('selected'));
                    box.classList.add('selected');
                    hiddenInput.value = box.dataset.value;
                    if (box.dataset.value === 'global') {
                        document.querySelector('.age-limit').style.display = 'block';
                    } else {
                        document.querySelector('.age-limit').style.display = 'none';
                    }
                });
            });
            const currentSetting = hiddenInput.value;
            document.querySelector(`.instruction-box[data-value="${currentSetting}"]`)?.classList.add('selected');
            if (currentSetting === 'global') {
                document.querySelector('.age-limit').style.display = 'block';
            }
        });
    })();
    </script>
    <div class="wrap">
        <h1><span style="color:#004cb8;">⛨</span> <?php _e('IDguard Indstillinger', 'idguard'); ?></h1>
        <div class="idguard-onboarding">
            <strong><?php _e('Kom hurtigt i gang:', 'idguard'); ?></strong>
            <ol style="margin-top:8px;">
                <li><?php _e('Vælg den ønskede metode for aldersverifikation nedenfor.', 'idguard'); ?></li>
                <li><?php _e('Gem indstillingerne nederst på siden.', 'idguard'); ?></li>
                <li><?php _e('Sæt aldersgrænser på produkter eller kategorier hvis nødvendigt.', 'idguard'); ?></li>
                <li><?php _e('Test i din webshop for at sikre korrekt integration.', 'idguard'); ?></li>
            </ol>
            <div style="margin-top:10px;">
                <a href="<?php echo admin_url('admin.php?page=idguard_documentation'); ?>" class="button-secondary" style="margin-right:10px;">📖 <?php _e('Se dokumentation', 'idguard'); ?></a>
                <a href="mailto:kontakt@arpecompany.dk" class="button-secondary">📬 <?php _e('Kontakt support', 'idguard'); ?></a>
            </div>
        </div>
        <form method="post" action="options.php">
            <?php
                settings_fields('idguard_general_settings');
                do_settings_sections('idguard_general_settings');
            ?>
            <h2><?php _e('Vælg aldersverifikationsmetode', 'idguard'); ?></h2>
            <div class="idguard-helper" style="margin-bottom:18px;">
                <?php _e('Vælg hvordan og hvornår kunder skal bekræfte deres alder. Hold musen over hver mulighed for mere info.', 'idguard'); ?>
            </div>
            <div class="instruction-box" data-value="off" title="<?php _e('Ingen aldersverifikation påkrævet.', 'idguard'); ?>">
                <div class="instruction-title">🔓 <?php _e('Slået fra', 'idguard'); ?></div>
                <div><?php _e('Ingen aldersverifikation vil være nødvendig.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="global" title="<?php _e('Alle produkter kræver aldersverifikation.', 'idguard'); ?>">
                <div class="instruction-title">🌍 <?php _e('Global', 'idguard'); ?></div>
                <div><?php _e('Aldersverifikation vil være nødvendig for alle produkter.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="category" title="<?php _e('Kun produkter i bestemte kategorier kræver aldersverifikation.', 'idguard'); ?>">
                <div class="instruction-title">📁 <?php _e('Kategorispecifik', 'idguard'); ?></div>
                <div><?php _e('Aldersverifikation vil være nødvendig for produkter i bestemte kategorier.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="product" title="<?php _e('Kun bestemte produkter kræver aldersverifikation.', 'idguard'); ?>">
                <div class="instruction-title">📦 <?php _e('Produktspecifik', 'idguard'); ?></div>
                <div><?php _e('Aldersverifikation vil være nødvendig for bestemte produkter.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="category_and_product" title="<?php _e('Kombineret: både kategori og produkt kan kræve aldersverifikation. Højeste grænse gælder.', 'idguard'); ?>">
                <div class="instruction-title">🔗 <?php _e('Kategori og Produktspecifik', 'idguard'); ?></div>
                <div><?php _e('Aldersverifikation vil være nødvendig baseret på kategorier og/eller individuelle produkter. Kategorier har højeste prioritet, hvis aktiveret.', 'idguard'); ?></div>
            </div>
            <input type="hidden" name="idguard_age_verification_mode" id="idguard_age_verification_mode" value="<?php echo esc_attr(get_option('idguard_age_verification_mode', 'off')); ?>">
            <div class="age-limit">
                <h3><?php _e('Vælg aldersgrænse for global indstilling', 'idguard'); ?></h3>
                <select name="idguard_global_age_limit" id="idguard_global_age_limit">
                    <option value="15" <?php selected(get_option('idguard_global_age_limit'), '15'); ?>>15+</option>
                    <option value="16" <?php selected(get_option('idguard_global_age_limit'), '16'); ?>>16+</option>
                    <option value="18" <?php selected(get_option('idguard_global_age_limit'), '18'); ?>>18+</option>
                    <option value="21" <?php selected(get_option('idguard_global_age_limit'), '21'); ?>>21+</option>
                </select>
                <p class="idguard-helper"><?php _e('Denne aldersgrænse vil gælde globalt, når aldersverifikation er aktiveret.', 'idguard'); ?></p>
            </div>
            <?php submit_button(__('Gem indstillinger', 'idguard'), 'primary', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;')); ?>
        </form>
    </div>
    <?php
}

// Add filter to provide plugin information for updates
add_filter('plugins_api', 'idguard_plugin_info', 20, 3);
function idguard_plugin_info($res, $action, $args) {
    // Ensure the action is for plugin information
    if ('plugin_information' !== $action) {
        return $res;
    }

    // Ensure the correct slug is being used
    if (plugin_basename(__FILE__) !== $args->slug) {
        return $res;
    }

    // Fetch plugin information from external JSON
    $remote = wp_remote_get('https://assets.idguard.dk/plugins/wordpress/plugin-info.json', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json']
    ]);

    // Check if the request is successful and not returning any errors
    if (is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote))) {
        return $res;
    }

    // Decode the JSON response
    $remote_data = json_decode(wp_remote_retrieve_body($remote));

    // Prepare the plugin information
    $res = new stdClass();
    $res->name = $remote_data->name;
    $res->slug = $remote_data->slug;
    $res->version = $remote_data->version;
    $res->tested = $remote_data->tested;
    $res->requires = $remote_data->requires;
    $res->requires_php = $remote_data->requires_php;
    $res->author = $remote_data->author;
    $res->download_link = $remote_data->download_url;
    $res->last_updated = $remote_data->last_updated;
    $res->sections = (array) $remote_data->sections;

    return $res;
}

// Add filter for updating
add_filter('site_transient_update_plugins', 'idguard_check_for_update');
function idguard_check_for_update($transient) {
    // Only check for updates if the transient is empty
    if (empty($transient->checked)) {
        return $transient;
    }

    // Fetch the plugin information from our JSON file
    $remote = wp_remote_get('https://assets.idguard.dk/plugins/wordpress/plugin-info.json', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json']
    ]);

    if (is_wp_error($remote)) {
        error_log('Error fetching remote data: ' . $remote->get_error_message());
        return $transient;
    }

    // Check if the request is successful
    if (200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote))) {
        error_log('Error: Unexpected response code or empty body.');
        return $transient;
    }

    $body = wp_remote_retrieve_body($remote);
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body); // Remove BOM if present

    $remote_data = json_decode($body);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Failed to decode JSON: ' . json_last_error_msg());
        return $transient; // Exit if JSON decoding fails
    }

    // Check if remote data has the required properties
    if (!isset($remote_data->version, $remote_data->slug, $remote_data->download_url)) {
        error_log('Remote data is missing required properties.');
        return $transient;
    }

    // Check if there is an update available
    if (isset($transient->checked['idguard/idguard.php']) && 
        version_compare($remote_data->version, $transient->checked['idguard/idguard.php'], '>')) {
        $plugin_info = [
            'slug' => 'idguard/idguard.php',
            'new_version' => $remote_data->version,
            'url' => 'https://idguard.dk',
            'package' => $remote_data->download_url
        ];
        $transient->response['idguard/idguard.php'] = (object) $plugin_info;
    }

    return $transient;
}
