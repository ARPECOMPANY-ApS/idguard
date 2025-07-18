<?php
/**
 * Plugin Name: IDguard
 * Plugin URI: https://idguard.dk
 * Description: Foretag automatisk alderstjek med MitID ved betaling på WooCommerce-webshops
 * Version: 2.1.1.64
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
    if (get_transient('idguard_plugin_activated')) {
        delete_transient('idguard_plugin_activated');
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
    $settings_link = '<a href="' . admin_url('admin.php?page=idguard') . '">' . idguard_dk_text('Konfigurer IDguard') . '</a>';
    array_unshift($links, $settings_link);
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
            <p><?php _e('Tak fordi du har valgt IDguard! Gennemgå venligst dine indstillinger for at sikre, at alt er sat korrekt op.', 'idguard'); ?></p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=idguard'); ?>" class="button button-primary">
                    <?php _e('Konfigurer IDguard', 'idguard'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'idguard_admin_notice');

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

// Fallback: Define if missing
if (!function_exists('idguard_get_required_age_for_verification')) {
    function idguard_get_required_age_for_verification() {
        // Return global age limit or default to 18
        $age = get_option('idguard_global_age_limit', '18');
        $allowed_ages = array('15', '16', '18', '21');
        if (!in_array($age, $allowed_ages)) {
            $age = '18';
        }
        return $age;
    }
}
}
add_action('wp_enqueue_scripts', 'idguard_init');

// --- Admin-menu ---
function idguard_add_admin_menu() {
    add_menu_page(
        __('Konfigurer IDguard', 'idguard'),
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
    // Handle form submission
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'idguard_general_settings-options')) {
        $mode = sanitize_text_field($_POST['idguard_age_verification_mode']);
        $global_age = sanitize_text_field($_POST['idguard_global_age_limit']);
        // Validate global age limit against allowed presets
        $allowed_ages = array('15', '16', '18', '21');
        if (!in_array($global_age, $allowed_ages)) {
            $global_age = '18'; // Default to 18 if invalid
        }
        update_option('idguard_age_verification_mode', $mode);
        update_option('idguard_global_age_limit', $global_age);
        // Show notice above the page content, not inside the form
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Indstillinger gemt!', 'idguard') . '</p></div>';
        });
    }
    
    $current_mode = get_option('idguard_age_verification_mode', 'off');
    $current_global_age = get_option('idguard_global_age_limit', '18');
    ?>
    <style>
    .idguard-welcome-banner {
        background: #004cb8;
        color: white;
        padding: 1em 1.5em;
        border-radius: 6px;
        margin-bottom: 1.5em;
        font-size: 1em;
    }
    .idguard-admin {
        background: white;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 1.5em;
        margin: 1em 0;
    }
    .idguard-mode-card {
        background: #f9f9f9;
        border: 2px solid #ddd;
        border-radius: 6px;
        padding: 1em;
        margin: 0.8em 0;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .idguard-mode-card:hover {
        border-color: #004cb8;
    }
    .idguard-mode-card.selected {
        border-color: #004cb8;
        background: #f0f4ff;
    }
    .idguard-mode-card h3 {
        margin: 0 0 0.5em 0;
        color: #333;
        font-size: 1.1em;
    }
    .idguard-mode-card p {
        margin: 0 0 0.8em 0;
        color: #666;
        font-size: 0.95em;
    }
    .idguard-status {
        display: inline-block;
        padding: 0.2em 0.6em;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: bold;
        text-transform: uppercase;
        margin-left: 0.5em;
    }
    .status-enabled { background: #d4edda; color: #155724; }
    .status-disabled { background: #f8d7da; color: #721c24; }
    .idguard-global-age {
        display: none;
        margin-top: 1em;
        padding: 0.8em;
        background: #f5f5f5;
        border-radius: 4px;
    }
    .idguard-global-age.show {
        display: block;
    }
    .idguard-help-text {
        background: #e8f4fd;
        border-left: 4px solid #004cb8;
        padding: 1em;
        margin: 1em 0;
        border-radius: 0 4px 4px 0;
        font-size: 0.9em;
    }
    .idguard-help-text h4 {
        margin-top: 0;
        color: #004cb8;
    }
    .idguard-preview-btn {
        background: #004cb8;
        color: white;
        border: none;
        padding: 0.7em 1.2em;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1em;
        margin-top: 1em;
    }
    .idguard-preview-btn:hover {
        background: #003a9b;
    }
    .idguard-age-select {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.8em 2.5em 0.8em 1em;
        font-size: 1em;
        font-weight: 500;
        color: #1a202c;
        min-width: 140px;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
        background-position: right 0.5rem center;
        background-repeat: no-repeat;
        background-size: 1.5em 1.5em;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }
    .idguard-age-select:hover {
        border-color: #004cb8;
        background: linear-gradient(135deg, #ffffff 0%, #f0f4ff 100%);
        box-shadow: 0 2px 8px rgba(0,76,184,0.1);
    }
    .idguard-age-select:focus {
        border-color: #004cb8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,76,184,0.15);
        background: white;
    }
    .idguard-age-select option {
        padding: 0.5em;
        font-weight: 500;
    }
    </style>
    
    <div class="wrap">
        <?php if (!get_option('idguard_onboarding_completed', false)): ?>
        <div class="idguard-welcome-banner">
            <p><strong>Velkommen til IDguard!</strong> Opsæt automatisk aldersverifikation på få minutter.</p>
        </div>
        <?php endif; ?>

        <div class="idguard-admin">
            <h1><span style="color:#004cb8;">IDguard</span> <?php _e('Indstillinger', 'idguard'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('idguard_general_settings-options'); ?>

                <h2><?php _e('Aldersverifikations-metode', 'idguard'); ?></h2>
                <p><?php _e('Vælg hvordan IDguard skal håndtere aldersverifikation på din webshop.', 'idguard'); ?></p>

                <div class="idguard-mode-card <?php echo $current_mode === 'off' ? 'selected' : ''; ?>" data-mode="off">
                    <h3>Deaktiveret <span class="idguard-status status-disabled">Inaktiv</span></h3>
                    <p>Ingen aldersverifikation. Alle kunder kan gennemføre køb uden begrænsninger.</p>
                    <input type="radio" name="idguard_age_verification_mode" value="off" <?php checked($current_mode, 'off'); ?> style="margin-top: 1em;">
                </div>

                <div class="idguard-mode-card <?php echo $current_mode === 'global' ? 'selected' : ''; ?>" data-mode="global">
                    <h3>Global aldersgrænse <span class="idguard-status status-enabled">Aktiv</span></h3>
                    <p>Alle produkter på webshop'en kræver samme aldersverifikation. Enkel opsætning.</p>
                    <input type="radio" name="idguard_age_verification_mode" value="global" <?php checked($current_mode, 'global'); ?> style="margin-top: 1em;">

                    <div class="idguard-global-age <?php echo $current_mode === 'global' ? 'show' : ''; ?>">
                        <label><strong>Minimum alder:</strong></label>
                        <select name="idguard_global_age_limit" class="idguard-age-select">
                            <option value="15" <?php selected($current_global_age, '15'); ?>>15 år</option>
                            <option value="16" <?php selected($current_global_age, '16'); ?>>16 år</option>
                            <option value="18" <?php selected($current_global_age, '18'); ?>>18 år</option>
                            <option value="21" <?php selected($current_global_age, '21'); ?>>21 år</option>
                        </select>
                        <p style="font-size: 0.9em; color: #666; margin-top: 0.5em;">Kun 15, 16, 18 og 21 år er tilgængelige via MitID API.</p>
                    </div>
                </div>

                <div class="idguard-mode-card <?php echo $current_mode === 'individual' ? 'selected' : ''; ?>" data-mode="individual">
                    <h3>Individuelle aldersgrænser <span class="idguard-status status-enabled">Avanceret</span></h3>
                    <p>Sæt forskellige aldersgrænser på produkter og kategorier. Fuld kontrol over hvad der kræver verifikation.</p>
                    <input type="radio" name="idguard_age_verification_mode" value="individual" <?php checked($current_mode, 'individual'); ?> style="margin-top: 1em;">

                    <?php if ($current_mode === 'individual'): ?>
                    <div style="margin-top: 1em;">
                        <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">Rediger produkter</a>
                        <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_cat&post_type=product'); ?>" class="button">Rediger kategorier</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="idguard-help-text">
                    <h4>Tip til opsætning</h4>
                    <ul>
                        <li><strong>Global:</strong> Vælg denne hvis alle dine produkter kræver samme aldersverifikation</li>
                        <li><strong>Individuel:</strong> Vælg denne hvis du har blandede produkter med forskellige alderskrav</li>
                        <li><strong>API begrænsning:</strong> Kun 15, 16, 18 og 21 år er tilgængelige via MitID API</li>
                        <li><strong>Test altid:</strong> Brug preview-funktionen til at teste før du aktiverer</li>
                    </ul>
                </div>

                <?php submit_button(__('Gem indstillinger', 'idguard'), 'primary large', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;margin-top:2em;')); ?>
            </form>

            <div style="margin-top: 2em; text-align: center;">
                <button onclick="idguardShowPopup()" class="idguard-preview-btn">Forhåndsvis popup</button>
                <p style="color:#666; font-size:0.9em; margin-top:0.5em;">Test hvordan popup'en vil se ud for dine kunder</p>
            </div>
        </div>
    </div>

    <script>
    // Remove WordPress footer and version from inside plugin settings
    document.addEventListener('DOMContentLoaded', function() {
        var wpFooter = document.getElementById('footer-left');
        if (wpFooter) wpFooter.style.display = 'none';
        var wpVersion = document.getElementById('footer-upgrade');
        if (wpVersion) wpVersion.style.display = 'none';
        // Remove "Thank you for creating with WordPress" if present
        var all = document.querySelectorAll('p, div');
        all.forEach(function(el) {
            if (el.textContent && el.textContent.match(/Thank you for creating with WordPress|WordPress version/i)) {
                el.style.display = 'none';
            }
        });
    });
    </script>
    
    <script>
    jQuery(document).ready(function($) {
        // Mode card selection
        $('.idguard-mode-card').click(function() {
            $('.idguard-mode-card').removeClass('selected');
            $(this).addClass('selected');
            $(this).find('input[type="radio"]').prop('checked', true);
            
            // Show/hide global age input
            if ($(this).data('mode') === 'global') {
                $('.idguard-global-age').addClass('show');
            } else {
                $('.idguard-global-age').removeClass('show');
            }
        });
        
        // Initialize on page load
        $('input[name="idguard_age_verification_mode"]:checked').closest('.idguard-mode-card').addClass('selected');
        if ($('input[value="global"]').is(':checked')) {
            $('.idguard-global-age').addClass('show');
        }
    });
    </script>
    <?php
}

function idguard_popup_page() {
    // Handle form submission
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'idguard_popup_settings-options')) {
        $settings = array(
            'idguard_popup_title' => sanitize_text_field($_POST['idguard_popup_title']),
            'idguard_popup_message' => sanitize_textarea_field($_POST['idguard_popup_message']),
            'idguard_popup_button_text' => sanitize_text_field($_POST['idguard_popup_button_text']),
            'idguard_popup_cancel_button_text' => sanitize_text_field($_POST['idguard_popup_cancel_button_text']),
            'idguard_popup_text_color' => sanitize_hex_color($_POST['idguard_popup_text_color']),
            'idguard_popup_background_color' => sanitize_hex_color($_POST['idguard_popup_background_color']),
            'idguard_popup_verify_button_color' => sanitize_hex_color($_POST['idguard_popup_verify_button_color']),
            'idguard_popup_verify_button_text_color' => sanitize_hex_color($_POST['idguard_popup_verify_button_text_color']),
            'idguard_popup_cancel_button_color' => sanitize_hex_color($_POST['idguard_popup_cancel_button_color']),
            'idguard_popup_cancel_button_text_color' => sanitize_hex_color($_POST['idguard_popup_cancel_button_text_color']),
            'idguard_cancel_redirect_option' => sanitize_text_field($_POST['idguard_cancel_redirect_option']),
            'idguard_custom_cancel_url' => sanitize_url($_POST['idguard_custom_cancel_url'])
        );
        
        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }
        
        echo '<div class="notice notice-success"><p>' . __('Popup indstillinger gemt!', 'idguard') . '</p></div>';
    }
    
    // Get current values
    $title = get_option('idguard_popup_title', 'Din ordre indeholder aldersbegrænsede varer');
    $message = get_option('idguard_popup_message', 'Den danske lovgivning kræver at vi kontrollerer din alder med MitID inden du kan købe aldersbegrænsede varer.');
    $confirm_text = get_option('idguard_popup_button_text', 'Fortsæt købet');
    $cancel_text = get_option('idguard_popup_cancel_button_text', 'Gå tilbage');
    $text_color = get_option('idguard_popup_text_color', '#000000');
    $bg_color = get_option('idguard_popup_background_color', '#ffffff');
    $verify_btn_color = get_option('idguard_popup_verify_button_color', '#004cb8');
    $verify_btn_text_color = get_option('idguard_popup_verify_button_text_color', '#ffffff');
    $cancel_btn_color = get_option('idguard_popup_cancel_button_color', '#d6d6d6');
    $cancel_btn_text_color = get_option('idguard_popup_cancel_button_text_color', '#000000');
    $cancel_redirect = get_option('idguard_cancel_redirect_option', 'cart');
    $custom_url = get_option('idguard_custom_cancel_url', '');
    ?>
    <style>
    .idguard-popup-admin {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 2em;
        margin: 1em 0;
    }
    .idguard-form-section {
        background: white;
        border-radius: 8px;
        padding: 2em;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .idguard-preview-section {
        background: white;
        border-radius: 8px;
        padding: 1.5em;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        position: sticky;
        top: 32px;
        height: fit-content;
    }
    .form-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1.5em;
        margin: 1.5em 0;
        border-left: 4px solid #004cb8;
    }
    .form-section h3 {
        margin-top: 0;
        color: #004cb8;
        font-size: 1.2em;
        display: flex;
        align-items: center;
        gap: 0.5em;
    }
    .form-group {
        margin-bottom: 1.5em;
    }
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5em;
        color: #1a202c;
        font-size: 0.95em;
    }
    .form-group input, .form-group textarea, .form-group select {
        width: 100%;
        padding: 0.7em;
        border: 2px solid #e2e8f0;
        border-radius: 6px;
        font-size: 1em;
        transition: border-color 0.3s ease;
    }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
        border-color: #004cb8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,76,184,0.1);
    }
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    .color-input-group {
        display: flex;
        align-items: center;
        gap: 0.8em;
    }
    .color-input-group input[type="color"] {
        width: 50px;
        height: 40px;
        border-radius: 6px;
        border: 2px solid #e2e8f0;
        cursor: pointer;
    }
    .color-input-group input[type="text"] {
        width: 90px;
        font-family: monospace;
        font-size: 0.9em;
    }
    .popup-preview {
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        padding: 1em;
        background: #f8f9fa;
        text-align: center;
        margin-top: 1em;
    }
    .preview-popup-content {
        border-radius: 8px;
        padding: 1.5em;
        max-width: 280px;
        margin: 0 auto;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        transition: all 0.3s ease;
    }
    .preview-button {
        padding: 0.6em 1.2em;
        border: none;
        border-radius: 6px;
        font-size: 0.9em;
        margin: 0.4em 0.2em;
        cursor: pointer;
        min-width: 100px;
        transition: all 0.3s ease;
        font-weight: 500;
    }
    .preview-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .section-divider {
        border: none;
        height: 1px;
        background: linear-gradient(90deg, #004cb8, transparent);
        margin: 0;
    }
    .help-text {
        background: #e8f4fd;
        border-left: 4px solid #004cb8;
        padding: 1em;
        margin: 1.5em 0;
        border-radius: 0 6px 6px 0;
        font-size: 0.9em;
    }
    .custom-url-field {
        display: none;
        margin-top: 1em;
        padding: 1em;
        background: #f0f4ff;
        border-radius: 6px;
        border: 1px dashed #004cb8;
    }
    .custom-url-field.show {
        display: block;
    }
    .preview-header {
        text-align: center;
        margin-bottom: 1em;
        padding-bottom: 1em;
        border-bottom: 1px solid #e2e8f0;
    }
    .preview-header h3 {
        margin: 0;
        color: #004cb8;
        font-size: 1.1em;
    }
    .preview-header p {
        margin: 0.5em 0 0 0;
        color: #666;
        font-size: 0.85em;
    }
    @media (max-width: 1200px) {
        .idguard-popup-admin {
            grid-template-columns: 1fr;
        }
        .idguard-preview-section {
            position: static;
        }
    }
    </style>
    
    <div class="wrap">
        <h1><span style="color:#004cb8;">IDguard</span> <?php _e('Popup Design & Tekster', 'idguard'); ?></h1>
        
        <div class="idguard-popup-admin">
            <div class="idguard-form-section">
                <form method="post" action="">
                    <?php wp_nonce_field('idguard_popup_settings-options'); ?>
                    
                    <div class="form-section">
                        <h3><?php _e('Popup Tekster', 'idguard'); ?></h3>
                        
                        <div class="form-group">
                            <label for="popup_title"><?php _e('Popup Titel', 'idguard'); ?></label>
                            <input type="text" id="popup_title" name="idguard_popup_title" value="<?php echo esc_attr($title); ?>" onchange="updatePreview()" placeholder="Din ordre indeholder aldersbegrænsede varer">
                        </div>
                        
                        <div class="form-group">
                            <label for="popup_message"><?php _e('Popup Besked', 'idguard'); ?></label>
                            <textarea id="popup_message" name="idguard_popup_message" onchange="updatePreview()" placeholder="Den danske lovgivning kræver at vi kontrollerer din alder..."><?php echo esc_textarea($message); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_button"><?php _e('Bekræft Knap Tekst', 'idguard'); ?></label>
                            <input type="text" id="confirm_button" name="idguard_popup_button_text" value="<?php echo esc_attr($confirm_text); ?>" onchange="updatePreview()" placeholder="Fortsæt købet">
                        </div>
                        
                        <div class="form-group">
                            <label for="cancel_button"><?php _e('Annuller Knap Tekst', 'idguard'); ?></label>
                            <input type="text" id="cancel_button" name="idguard_popup_cancel_button_text" value="<?php echo esc_attr($cancel_text); ?>" onchange="updatePreview()" placeholder="Gå tilbage">
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3><?php _e('Popup Farver', 'idguard'); ?></h3>
                        
                        <div class="form-group">
                            <label for="text_color"><?php _e('Tekst Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="text_color" name="idguard_popup_text_color" value="<?php echo esc_attr($text_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($text_color); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="bg_color"><?php _e('Baggrund Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="bg_color" name="idguard_popup_background_color" value="<?php echo esc_attr($bg_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($bg_color); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="verify_btn_color"><?php _e('Bekræft Knap Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="verify_btn_color" name="idguard_popup_verify_button_color" value="<?php echo esc_attr($verify_btn_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($verify_btn_color); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="verify_btn_text_color"><?php _e('Bekræft Knap Tekst Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="verify_btn_text_color" name="idguard_popup_verify_button_text_color" value="<?php echo esc_attr($verify_btn_text_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($verify_btn_text_color); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="cancel_btn_color"><?php _e('Annuller Knap Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="cancel_btn_color" name="idguard_popup_cancel_button_color" value="<?php echo esc_attr($cancel_btn_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($cancel_btn_color); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="cancel_btn_text_color"><?php _e('Annuller Knap Tekst Farve', 'idguard'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="cancel_btn_text_color" name="idguard_popup_cancel_button_text_color" value="<?php echo esc_attr($cancel_btn_text_color); ?>" onchange="updatePreview()">
                                <input type="text" value="<?php echo esc_attr($cancel_btn_text_color); ?>" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3><?php _e('Annullering Indstillinger', 'idguard'); ?></h3>
                        
                        <div class="form-group">
                            <label for="cancel_redirect"><?php _e('Når kunden annullerer alderstjekket, viderestil til:', 'idguard'); ?></label>
                            <select id="cancel_redirect" name="idguard_cancel_redirect_option" onchange="toggleCustomUrl()">
                                <option value="cart" <?php selected($cancel_redirect, 'cart'); ?>><?php _e('Indkøbskurv', 'idguard'); ?></option>
                                <option value="home" <?php selected($cancel_redirect, 'home'); ?>><?php _e('Forside', 'idguard'); ?></option>
                                <option value="custom" <?php selected($cancel_redirect, 'custom'); ?>><?php _e('Brugerdefineret URL', 'idguard'); ?></option>
                            </select>
                            
                            <div class="custom-url-field <?php echo $cancel_redirect === 'custom' ? 'show' : ''; ?>">
                                <label for="custom_url"><?php _e('Brugerdefineret URL:', 'idguard'); ?></label>
                                <input type="url" id="custom_url" name="idguard_custom_cancel_url" value="<?php echo esc_attr($custom_url); ?>" placeholder="https://dinwebshop.dk/side">
                            </div>
                        </div>
                        
                        <div class="help-text">
                            <strong>Tips til bedre brugeroplevelse:</strong>
                            <ul>
                                <li>Hold beskederne korte og tydelige</li>
                                <li>Brug farver der matcher dit brand</li>
                                <li>Test popup'en på forskellige enheder</li>
                            </ul>
                        </div>
                    </div>
                    
                    <?php submit_button(__('Gem Design Indstillinger', 'idguard'), 'primary large', 'submit', true, array('style' => 'font-size:1.1em;padding:0.8em 2em;background:#004cb8;border-radius:6px;border:none;margin-top:1.5em;')); ?>
                </form>
            </div>
            
            <div class="idguard-preview-section">
                <div class="preview-header">
                    <h3><?php _e('Live Forhåndsvisning', 'idguard'); ?></h3>
                    <p><?php _e('Se hvordan popup\'en vil se ud for dine kunder', 'idguard'); ?></p>
                </div>
                
                <div class="popup-preview">
                    <div id="preview-popup" class="preview-popup-content" style="position:relative; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border-radius: 20px; padding: 40px 24px 32px 24px; max-width: 400px; margin: 0 auto; background: linear-gradient(145deg, #ffffff, #e6e6e6);">
                        <h2 id="preview-title" style="font-size:26px; color:#333; margin-bottom:10px; font-weight:600; letter-spacing:0.01em; line-height:1.2;"></h2>
                        <p id="preview-message" style="font-size:16px; color:#555; margin-bottom:20px; line-height:1.5;"></p>
                        <button id="preview-confirm" class="idGuardButton idGuardVerifyButton preview-button" style="margin-top:8px; padding:13px 25px; border:none; cursor:pointer; font-size:16px; border-radius:8px; transition:background-color 0.3s,transform 0.2s,box-shadow 0.3s; outline:none; display:inline-flex; align-items:center; justify-content:center; font-weight:400; letter-spacing:0.5px; width:100%; position:relative; overflow:hidden;">
                            <span class="mitid-logo-container" style="position:absolute; left:0; top:0; bottom:0; width:50px; display:flex; align-items:center; justify-content:center; border-top-left-radius:8px; border-bottom-left-radius:8px; transition:background-color 0.3s; padding:0.5rem; background-color:#003a9b;">
                                <img src="<?php echo plugins_url('logo-mitid.webp', __FILE__); ?>" class="mitid-logo" style="height:28px; width:auto; filter:brightness(0) invert(1);">
                            </span>
                            <span class="verify-text" style="margin-left:40px; width:100%; text-align:center;"></span>
                        </button>
                        <button id="preview-cancel" class="idGuardButton idGuardCancelButton preview-button" style="margin-top:8px; padding:13px 25px; border:none; cursor:pointer; font-size:16px; border-radius:8px; transition:background-color 0.3s,transform 0.2s,box-shadow 0.3s; outline:none; display:inline-flex; align-items:center; justify-content:center; font-weight:400; letter-spacing:0.5px; width:100%; position:relative; overflow:hidden; background:#d6d6d6; color:#000;">
                            <span class="verify-text" style="width:100%; text-align:center;"></span>
                        </button>
                    </div>
                </div>
                
                <div style="margin-top: 1.5em; text-align: center;">
                    <button onclick="idguardShowPopup()" class="button button-primary"><?php _e('Test Fuld Popup', 'idguard'); ?></button>
                    <p style="color:#666; font-size:0.8em; margin-top:0.5em;"><?php _e('Åbn popup i fuld størrelse', 'idguard'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Color input synchronization
    document.querySelectorAll('input[type="color"]').forEach(function(colorInput) {
        colorInput.addEventListener('input', function() {
            this.nextElementSibling.value = this.value;
            updatePreview();
        });
    });
    
    // Custom URL toggle
    function toggleCustomUrl() {
        const select = document.getElementById('cancel_redirect');
        const customField = document.querySelector('.custom-url-field');
        
        if (select.value === 'custom') {
            customField.classList.add('show');
        } else {
            customField.classList.remove('show');
        }
    }
    
    // Live preview update
    function updatePreview() {
        const preview = document.getElementById('preview-popup');
        const title = document.getElementById('popup_title').value;
        const message = document.getElementById('popup_message').value;
        const confirmText = document.getElementById('confirm_button').value;
        const cancelText = document.getElementById('cancel_button').value;
        const textColor = document.getElementById('text_color').value;
        const bgColor = document.getElementById('bg_color').value;
        const verifyBtnColor = document.getElementById('verify_btn_color').value;
        const verifyBtnTextColor = document.getElementById('verify_btn_text_color').value;
        const cancelBtnColor = document.getElementById('cancel_btn_color').value;
        const cancelBtnTextColor = document.getElementById('cancel_btn_text_color').value;

        // Update content
        document.getElementById('preview-title').textContent = title;
        document.getElementById('preview-message').textContent = message;
        // Confirm button text inside .verify-text span
        document.querySelector('#preview-confirm .verify-text').textContent = confirmText;
        // Cancel button text inside .verify-text span
        document.querySelector('#preview-cancel .verify-text').textContent = cancelText;

        // Update colors and styles
        preview.style.background = `linear-gradient(145deg, #ffffff, #e6e6e6)`;
        preview.style.color = textColor;
        preview.style.borderRadius = '20px';
        preview.style.boxShadow = '0 4px 20px rgba(0,0,0,0.15)';

        // Confirm button
        const confirmBtn = document.getElementById('preview-confirm');
        confirmBtn.style.backgroundColor = verifyBtnColor;
        confirmBtn.style.color = verifyBtnTextColor;
        // MitID logo container
        const mitidLogo = confirmBtn.querySelector('.mitid-logo-container');
        mitidLogo.style.backgroundColor = darkenColor(verifyBtnColor, 20);

        // Cancel button
        const cancelBtn = document.getElementById('preview-cancel');
        cancelBtn.style.backgroundColor = cancelBtnColor;
        cancelBtn.style.color = cancelBtnTextColor;
    }
    
    // Initialize preview
    updatePreview();
    
    // Add preview popup function for admin
    window.idguardShowPopup = function() {
        // Get current settings
        const title = document.getElementById('popup_title') ? document.getElementById('popup_title').value : 'Din ordre indeholder aldersbegrænsede varer';
        const message = document.getElementById('popup_message') ? document.getElementById('popup_message').value : 'Den danske lovgivning kræver at vi kontrollerer din alder med MitID inden du kan købe aldersbegrænsede varer.';
        const confirmText = document.getElementById('confirm_button') ? document.getElementById('confirm_button').value : 'Fortsæt købet';
        const cancelText = document.getElementById('cancel_button') ? document.getElementById('cancel_button').value : 'Gå tilbage';
        const textColor = document.getElementById('text_color') ? document.getElementById('text_color').value : '#000000';
        const bgColor = document.getElementById('bg_color') ? document.getElementById('bg_color').value : '#ffffff';
        const verifyBtnColor = document.getElementById('verify_btn_color') ? document.getElementById('verify_btn_color').value : '#004cb8';
        const verifyBtnTextColor = document.getElementById('verify_btn_text_color') ? document.getElementById('verify_btn_text_color').value : '#ffffff';
        const cancelBtnColor = document.getElementById('cancel_btn_color') ? document.getElementById('cancel_btn_color').value : '#d6d6d6';
        const cancelBtnTextColor = document.getElementById('cancel_btn_text_color') ? document.getElementById('cancel_btn_text_color').value : '#000000';
        
        // Helper function to darken a color (from idguard.js)
        function darkenColor(color, percent) {
            if (color.startsWith('#')) {
                let num = parseInt(color.replace("#", ""), 16);
                let amt = Math.round(2.55 * percent);
                let R = (num >> 16) - amt;
                let G = (num >> 8 & 0x00FF) - amt;
                let B = (num & 0x0000FF) - amt;
                return "#" + (
                    0x1000000 +
                    (R < 0 ? 0 : R) * 0x10000 +
                    (G < 0 ? 0 : G) * 0x100 +
                    (B < 0 ? 0 : B)
                ).toString(16).slice(1);
            }
            return color;
        }
        
        // Create modal HTML exactly like in idguard.js
        var modalHTML = '<div id="idGuardModal" class="idGuardModal" style="color: ' + textColor + ';">' +
            '<div class="idGuardModalContent" style="background: ' + bgColor + '; color: ' + textColor + ';">' +
                '<h2>' + title + '</h2>' +
                '<p>' + message + '</p>' +
                '<button id="verifyButton" class="idGuardButton idGuardVerifyButton" style="background-color: ' + verifyBtnColor + '; color: ' + verifyBtnTextColor + ';">' +
                    '<span class="mitid-logo-container" style="background-color: ' + darkenColor(verifyBtnColor, 20) + ';">' +
                        '<img src="<?php echo plugins_url('logo-mitid.webp', __FILE__); ?>" class="mitid-logo">' +
                    '</span>' +
                    '<span class="verify-text">' + confirmText + '</span>' +
                '</button>' +
                '<button id="cancelButton" class="idGuardButton idGuardCancelButton" style="background-color: ' + cancelBtnColor + '; color: ' + cancelBtnTextColor + ';">' + cancelText + '</button>' +
            '</div>' +
        '</div>';
        
        // Add styles exactly like in idguard.js
        var modalStyles = '<style>' +
            '.idGuardModal { display: flex; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.8); justify-content: center; align-items: center; animation: fadeIn 0.3s ease-in-out; backdrop-filter: blur(0.3rem); }' +
            '.idGuardModalContent { background: linear-gradient(145deg, #ffffff, #e6e6e6); border-radius: 20px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2); padding: 40px; text-align: center; max-width: 400px; width: 90%; animation: slideIn 0.3s ease-in-out forwards; position: relative; }' +
            '.idGuardModalContent h2 { font-size: 26px; color: #333; margin-bottom: 10px; }' +
            '.idGuardModalContent p { font-size: 16px; color: #555; margin-bottom: 20px; line-height: 1.5; }' +
            '.idGuardButton, .idGuardCancelButton { margin-top: 8px; padding: 13px 25px; border: none; cursor: pointer; font-size: 16px; border-radius: 8px; transition: background-color 0.3s, transform 0.2s, box-shadow 0.3s; outline: none; display: inline-flex; align-items: center; justify-content: center; font-weight: 400; letter-spacing: 0.5px; width: 100%; position: relative; overflow: hidden; }' +
            '.idGuardButton:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(0, 123, 255, 0.5); }' +
            '.idGuardCancelButton:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(244, 67, 54, 0.5); }' +
            '.mitid-logo-container { position: absolute; left: 0; top: 0; bottom: 0; width: 50px; display: flex; align-items: center; justify-content: center; border-top-left-radius: 8px; border-bottom-left-radius: 8px; transition: background-color 0.3s; padding: 0.5rem; }' +
            '.mitid-logo { height: auto; width: auto; filter: brightness(0) invert(1); }' +
            '.verify-text { margin-left: 40px; }' +
            '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }' +
            '@keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }' +
        '</style>';
        
        // Remove existing modal if any
        var existingModal = document.getElementById('idGuardModal');
        if (existingModal) {
            existingModal.remove();
        }
        var existingStyles = document.getElementById('idguard-modal-styles');
        if (existingStyles) {
            existingStyles.remove();
        }
        
        // Add styles to head
        var styleElement = document.createElement('div');
        styleElement.id = 'idguard-modal-styles';
        styleElement.innerHTML = modalStyles;
        document.head.appendChild(styleElement);
        
        // Add modal to body
        var modalElement = document.createElement('div');
        modalElement.innerHTML = modalHTML;
        document.body.appendChild(modalElement.firstChild);
        
        // Add event listeners
        document.getElementById('cancelButton').addEventListener('click', function() {
            document.getElementById('idGuardModal').remove();
            document.getElementById('idguard-modal-styles').remove();
        });
        
        document.getElementById('idGuardModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.remove();
                document.getElementById('idguard-modal-styles').remove();
            }
        });
        
        // Close on escape key
        var escapeHandler = function(e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('idGuardModal');
                if (modal) {
                    modal.remove();
                    document.getElementById('idguard-modal-styles').remove();
                    document.removeEventListener('keydown', escapeHandler);
                }
            }
        };
        document.addEventListener('keydown', escapeHandler);
    };
    </script>
    <?php
}

function idguard_documentation_page() {
    ?>
    <style>
    .idguard-docs {
        background: white;
        border-radius: 8px;
        padding: 2em;
        margin: 1em 0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5em;
        margin: 2em 0;
    }
    .docs-card {
        background: #f8f9fa;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1.5em;
        transition: all 0.3s ease;
    }
    .docs-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .docs-card h3 {
        margin-top: 0;
        color: #004cb8;
        display: flex;
        align-items: center;
        gap: 0.5em;
    }
    .step-list {
        counter-reset: step-counter;
        list-style: none;
        padding: 0;
    }
    .step-list li {
        counter-increment: step-counter;
        margin: 1em 0;
        padding: 1em;
        background: white;
        border-radius: 6px;
        border-left: 4px solid #004cb8;
        position: relative;
    }
    .step-list li::before {
        content: counter(step-counter);
        position: absolute;
        left: -15px;
        top: 15px;
        background: #004cb8;
        color: white;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 0.8em;
    }
    .faq-item {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        margin: 1em 0;
        overflow: hidden;
    }
    .faq-question {
        background: #f8f9fa;
        padding: 1em 1.5em;
        font-weight: 600;
        cursor: pointer;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .faq-answer {
        padding: 1em 1.5em;
        display: none;
    }
    .faq-answer.show {
        display: block;
    }
    .code-block {
        background: #1a1a1a;
        color: #ffffff;
        padding: 1em;
        border-radius: 6px;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
        overflow-x: auto;
        margin: 1em 0;
    }
    .alert-box {
        padding: 1em;
        border-radius: 6px;
        margin: 1em 0;
        border-left: 4px solid;
    }
    .alert-info {
        background: #e8f4fd;
        border-color: #004cb8;
        color: #004cb8;
    }
    .alert-warning {
        background: #fff3cd;
        border-color: #ffc107;
        color: #856404;
    }
    .alert-success {
        background: #d4edda;
        border-color: #28a745;
        color: #155724;
    }
    </style>
    
    <div class="wrap">
        <h1><?php _e('IDguard Dokumentation & Hjælp', 'idguard'); ?></h1>
        
        <div class="idguard-docs">
            <div class="alert-box alert-info">
                <strong>Velkommen til IDguard dokumentationen!</strong><br>
                Her finder du guides, tips og svar på de mest stillede spørgsmål om automatisk aldersverifikation med MitID.
            </div>
            
            <div class="docs-grid">
                <div class="docs-card">
                    <h3>Kom i gang</h3>
                    <p>Grundlæggende opsætning af IDguard på din webshop.</p>
                    <a href="#getting-started" class="button">Læs guide</a>
                </div>
                
                <div class="docs-card">
                    <h3>Konfiguration</h3>
                    <p>Detaljeret guide til indstillinger og tilpasning.</p>
                    <a href="#configuration" class="button">Se indstillinger</a>
                </div>
                
                <div class="docs-card">
                    <h3>Design tilpasning</h3>
                    <p>Tilpas popup-design til dit brand og dine farver.</p>
                    <a href="#design" class="button">Læs om design</a>
                </div>
                
                <div class="docs-card">
                    <h3>Fejlfinding</h3>
                    <p>Løsninger på almindelige problemer og fejl.</p>
                    <a href="#troubleshooting" class="button">Se fejlfinding</a>
                </div>
            </div>
            
            <h2 id="getting-started">Kom i gang med IDguard</h2>
            
            <p>IDguard automatiserer aldersverifikation med MitID i din WooCommerce webshop. Følg denne opdaterede trin-for-trin guide for at komme i gang:</p>
            
            <ol class="step-list">
                <li>
                    <strong>Vælg verifikationsmetode</strong><br>
                    Gå til <em>IDguard → Indstillinger</em> og vælg mellem global aldersgrænse eller individuelle grænser pr. produkt/kategori. Den forbedrede brugerflade gør det nemt at se forskellen mellem metoderne.
                </li>
                <li>
                    <strong>Sæt aldersgrænser med nye dropdowns</strong><br>
                    Hvis du valgte "Global", brug den nye pænere dropdown til at vælge aldersgrænse. Ved "Individuel" har produkt- og kategori-felterne også fået forbedret design med ikoner og bedre styling.
                </li>
                <li>
                    <strong>Tilpas popup design med live preview</strong><br>
                    Under <em>Design & Tekster</em> kan du nu se dine ændringer live i forhåndsvisningen til højre, mens du redigerer farver og tekster.
                </li>
                <li>
                    <strong>Test funktionaliteten</strong><br>
                    Brug både den lille preview og "Test Fuld Popup" funktionen til at teste før du aktiverer.
                </li>
                <li>
                    <strong>Gå live</strong><br>
                    Når alt er sat op, vil aldersverifikation automatisk træde i kraft ved checkout.
                </li>
            </ol>
            
            <div class="alert-box alert-success">
                <strong>Pro tip:</strong> Start med global aldersgrænse for enkel opsætning, og skift til individuelle grænser hvis du har brug for mere kontrol.
            </div>
            
            <h2 id="configuration">Konfiguration i detaljer</h2>
            
            <h3>Aldersverifikations metoder</h3>
            
            <div class="alert-box alert-info">
                <strong>Vigtig information om aldersgrænser:</strong><br>
                IDguard understøtter kun følgende aldersgrænser via MitID API: <strong>15, 16, 18 og 21 år</strong>. Andre aldersgrænser er ikke mulige.
            </div>
            
            <h4>Deaktiveret</h4>
            <p>Ingen aldersverifikation. Alle kunder kan købe alle produkter uden begrænsninger.</p>
            
            <h4>Global aldersgrænse</h4>
            <p>Samme aldersgrænse for alle produkter på webshop'en. Simpel løsning for butikker hvor alle produkter kræver samme alder.</p>
            <ul>
                <li>Vælg en af de tilgængelige aldersgrænser: 15, 16, 18 eller 21 år</li>
                <li>Alle produkter i kurven udløser verifikation</li>
                <li>Hurtig opsætning</li>
            </ul>
            
            <h4>Individuelle aldersgrænser</h4>
            <p>Forskellige aldersgrænser på produkter og kategorier. Giver fuld kontrol over hvilke produkter der kræver verifikation.</p>
            <ul>
                <li>Sæt aldersgrænse på individuelle produkter (15, 16, 18 eller 21 år)</li>
                <li>Sæt aldersgrænse på hele kategorier (15, 16, 18 eller 21 år)</li>
                <li>Kun produkter med aldersgrænse udløser verifikation</li>
                <li>Produktgrænse overskriver kategorigrænse</li>
            </ul>
            
            <h3>Produktspecifikke indstillinger</h3>
            <p>Når du har valgt "Individuelle aldersgrænser", kan du sætte aldersgrænser direkte på produkter:</p>
            
            <ol>
                <li>Gå til <em>Produkter → Alle produkter</em></li>
                <li>Rediger et produkt</li>
                <li>Find "Aldersgrænse" feltet under "Produktdata"</li>
                <li>Indtast minimum alder (f.eks. 18)</li>
                <li>Gem produktet</li>
            </ol>
            
            <h3>Kategori indstillinger</h3>
            <p>Du kan også sætte aldersgrænser på hele produktkategorier:</p>
            
            <ol>
                <li>Gå til <em>Produkter → Kategorier</em></li>
                <li>Rediger en kategori</li>
                <li>Find "Aldersgrænse" feltet</li>
                <li>Indtast minimum alder</li>
                <li>Gem kategorien</li>
            </ol>
            
            <h2 id="design">Design tilpasning</h2>
            
            <p>IDguard version 2.1.1.63 kommer med en ny og forbedret designoplevelse med live forhåndsvisning og pænere kontrolelementer.</p>

            <h3>Nyheder i UI/UX</h3>
            <div class="alert-box alert-success">
                <strong>Nye forbedringer i version 2.1.1.63:</strong>
                <ul>
                    <li><strong>Live preview:</strong> Se dine designændringer øjeblikkeligt i sidebar'en til højre</li>
                    <li><strong>Pænere dropdowns:</strong> Alle aldersfelter har fået moderne styling med ikoner</li>
                    <li><strong>Struktureret layout:</strong> Design & Tekster siden er nu opdelt i klare sektioner</li>
                    <li><strong>Forbedret responsive:</strong> Bedre oplevelse på mobile enheder</li>
                    <li><strong>Visuelle ikoner:</strong> 🔒 ikoner på alle aldersfelter for bedre genkendelighed</li>
                </ul>
            </div>

            <h3>Tekst tilpasning</h3>
            <p>Den nye strukturerede layout gør det nemt at redigere popup tekster:</p>
            <ul>
                <li><strong>Popup titel:</strong> Hovedoverskrift i popup'en</li>
                <li><strong>Popup besked:</strong> Forklarende tekst til kunden</li>
                <li><strong>Bekræft knap:</strong> Tekst på knappen der starter MitID</li>
                <li><strong>Annuller knap:</strong> Tekst på knappen der afbryder</li>
            </ul>
            
            <h3>Farve tilpasning med live preview</h3>
            <p>Med den nye live forhåndsvisning kan du nu se dine farveændringer øjeblikkeligt:</p>
            <ul>
                <li><strong>Tekst farve:</strong> Farve på al tekst i popup'en</li>
                <li><strong>Baggrund:</strong> Popup'ens baggrundfarve</li>
                <li><strong>Bekræft knap farver:</strong> Baggrund og tekst på "Fortsæt" knappen</li>
                <li><strong>Annuller knap farver:</strong> Baggrund og tekst på "Tilbage" knappen</li>
            </ul>

            <h3>Forbedrede aldersfelter</h3>
            <p>Alle steder hvor du kan sætte aldersgrænser har nu fået moderniseret design:</p>
            <ul>
                <li><strong>Global indstilling:</strong> Pæn dropdown i hovedindstillinger</li>
                <li><strong>Produktsider:</strong> Moderne dropdown med 🔒 ikoner i WooCommerce produktredigering</li>
                <li><strong>Kategorier:</strong> Konsistent styling på kategori-redigeringssider</li>
                <li><strong>Hover-effekter:</strong> Diskrete animationer og farveændringer ved mus-over</li>
            </ul>

            <h3>Omdirigeringsindstillinger</h3>
            <p>Vælg hvor kunden sendes hen hvis de annullerer aldersverifikationen:</p>
            <ul>
                <li><strong>Indkøbskurv:</strong> Tilbage til kurv siden (anbefalet)</li>
                <li><strong>Forside:</strong> Til webshoppens forside</li>
                <li><strong>Brugerdefineret:</strong> Til en specifik side du vælger</li>
            </ul>
            
            <h2 id="shortcode">IDguard Shortcode - Brug uden for WooCommerce</h2>
            
            <p>Fra version 2.1.1.64 kan du nu bruge IDguard popup'en overalt på din hjemmeside med en shortcode - ikke kun i WooCommerce checkout.</p>

            <div class="alert-box alert-success">
                <strong>Nyt i version 2.1.1.64:</strong> IDguard kan nu bruges på enhver side, blog post eller widget med en simpel shortcode.
            </div>

            <h3>Grundlæggende brug</h3>
            <p>Den simpleste måde at tilføje en IDguard knap:</p>
            <div class="code-block">
                <code>[idguard]</code>
            </div>
            <p>Dette opretter en knap med standardindstillinger (18 års grænse).</p>

            <h3>Avancerede parametre</h3>
            <p>Du kan tilpasse shortcode'n med følgende parametre:</p>
            
            <div class="code-block">
                <code>[idguard age="21" title="Aldersverifikation påkrævet" message="Du skal være 21 år for at fortsætte" button_text="Verificer mit CPR" redirect_url="https://minside.dk/vip-sektion"]</code>
            </div>

            <h4>Tilgængelige parametre:</h4>
            <ul>
                <li><strong>age:</strong> Aldersgrænse (15, 16, 18 eller 21) - Standard: 18</li>
                <li><strong>title:</strong> Popup titel - Standard: Fra dine popup indstillinger</li>
                <li><strong>message:</strong> Popup besked - Standard: Fra dine popup indstillinger</li>
                <li><strong>button_text:</strong> Tekst på trigger-knappen - Standard: "Verificer min alder"</li>
                <li><strong>redirect_url:</strong> Hvor brugeren sendes efter verifikation - Standard: Ingen omdirigering</li>
                <li><strong>class:</strong> CSS klasse til styling af knappen - Standard: "idguard-shortcode"</li>
            </ul>

            <h3>Praktiske eksempler</h3>
            
            <h4>Eksklusivt indhold (18+)</h4>
            <div class="code-block">
                <code>[idguard age="18" title="Adgang til voksen indhold" message="Du skal være 18 år for at se dette indhold" button_text="Bekræft min alder" redirect_url="/voksen-indhold"]</code>
            </div>

            <h4>Alkohol webshop landing page</h4>
            <div class="code-block">
                <code>[idguard age="21" title="Velkommen til vores spiritus afdeling" message="For at handle spiritus skal du være mindst 21 år" button_text="Jeg er over 21 år" redirect_url="/alkohol-shop"]</code>
            </div>

            <h4>Simpel begrænset side</h4>
            <div class="code-block">
                <code>[idguard age="16" button_text="Fortsæt til siden"]</code>
            </div>

            <h3>Styling af shortcode knappen</h3>
            <p>Du kan style knappen med CSS ved at målrette klassen:</p>
            <div class="code-block">
                <code>.idguard-shortcode {<br>
    &nbsp;&nbsp;background: #ff6b35 !important;<br>
    &nbsp;&nbsp;color: white !important;<br>
    &nbsp;&nbsp;padding: 1em 2em;<br>
    &nbsp;&nbsp;border-radius: 25px;<br>
    &nbsp;&nbsp;font-weight: bold;<br>
    &nbsp;&nbsp;box-shadow: 0 4px 15px rgba(255,107,53,0.3);<br>
}</code>
            </div>

            <p>Eller brug en brugerdefineret klasse:</p>
            <div class="code-block">
                <code>[idguard class="min-special-knap"]</code>
            </div>

            <div class="alert-box alert-info">
                <strong>Tips:</strong> Shortcode'n arver dine farve- og tekstindstillinger fra "Design & Tekster" siden, så popup'en ser ens ud overalt på sitet.
            </div>
            
            <h2 id="troubleshooting">Fejlfinding</h2>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    Popup'en vises ikke ved checkout
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Mulige årsager og løsninger:</strong></p>
                    <ul>
                        <li>Tjek at aldersverifikation er aktiveret under IDguard indstillinger</li>
                        <li>Hvis du bruger individuelle grænser, tjek at produkter i kurven har aldersgrænser sat</li>
                        <li>Kontroller at du er på checkout-siden (ikke kurv-siden)</li>
                        <li>Tjek browser konsol for JavaScript fejl</li>
                    </ul>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    MitID processen virker ikke
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Tjek følgende:</strong></p>
                    <ul>
                        <li>Kontroller at dit domæne er autoriseret til IDguard</li>
                        <li>Tjek internet forbindelse</li>
                        <li>Prøv en anden browser</li>
                        <li>Kontakt support hvis problemet fortsætter</li>
                    </ul>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    Hvordan bruger jeg den nye live preview funktion?
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Live forhåndsvisning i version 2.1.1.63:</strong></p>
                    <ul>
                        <li>Gå til <em>IDguard → Design & Tekster</em></li>
                        <li>Ret tekster eller farver i venstre side</li>
                        <li>Se ændringerne øjeblikkeligt i preview til højre</li>
                        <li>Klik "Test Fuld Popup" for at se popup i fuld størrelse</li>
                        <li>Gem kun når du er tilfreds med resultatet</li>
                    </ul>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    Popup'en matcher ikke mit design
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Tilpasning med nye funktioner:</strong></p>
                    <ul>
                        <li>Gå til <em>IDguard → Design & Tekster</em></li>
                        <li>Brug farve-vælgerne til at matche dit brand</li>
                        <li>Tilpas tekster til dit tonefald</li>
                        <li>Brug live forhåndsvisningen til at se ændringer</li>
                        <li>Test på forskellige skærmstørrelser</li>
                    </ul>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    Kunder kan omgå aldersverifikation
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Sikkerhed:</strong></p>
                    <ul>
                        <li>IDguard kontrollerer også på server-siden</li>
                        <li>Ordrer uden gyldig verifikation afvises</li>
                        <li>Kontakt support hvis du oplever problemer</li>
                    </ul>
                </div>
            </div>
            
            <div class="alert-box alert-warning">
                <strong>Vigtigt:</strong> IDguard håndterer kun den tekniske aldersverifikation. Det er webshoppens ansvar at sikre juridisk compliance med danske love om salg af aldersbegrænsede varer.
            </div>
            
            <h2>Nyttige links</h2>
            <ul>
                <li><a href="<?php echo admin_url('admin.php?page=idguard'); ?>">IDguard Indstillinger</a></li>
                <li><a href="<?php echo admin_url('admin.php?page=idguard_popup'); ?>">Design & Tekster</a></li>
                <li><a href="<?php echo admin_url('admin.php?page=idguard_support'); ?>">Kundeservice</a></li>
                <li><a href="https://idguard.dk" target="_blank">IDguard hjemmeside</a></li>
            </ul>
        </div>
    </div>
    
    <script>
    function toggleFaq(element) {
        const answer = element.nextElementSibling;
        const icon = element.querySelector('span');
        
        if (answer.classList.contains('show')) {
            answer.classList.remove('show');
            icon.textContent = '+';
        } else {
            // Close all other FAQ items
            document.querySelectorAll('.faq-answer.show').forEach(item => {
                item.classList.remove('show');
                item.previousElementSibling.querySelector('span').textContent = '+';
            });
            
            answer.classList.add('show');
            icon.textContent = '-';
        }
    }
    </script>
    <?php
}

function idguard_support_page() {
    // Handle support form submission
    if (isset($_POST['submit_support']) && wp_verify_nonce($_POST['support_nonce'], 'idguard_support')) {
        $name = sanitize_text_field($_POST['support_name']);
        $email = sanitize_email($_POST['support_email']);
        $subject = sanitize_text_field($_POST['support_subject']);
        $message = sanitize_textarea_field($_POST['support_message']);
        $site_url = home_url();
        
        // Prepare email
        $to = 'kontakt@arpecompany.dk';
        $email_subject = '[IDguard Support] ' . $subject;
        $email_body = "Navn: $name\n";
        $email_body .= "Email: $email\n";
        $email_body .= "Website: $site_url\n\n";
        $email_body .= "Besked:\n$message";
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $name . ' <' . $email . '>',
            'Reply-To: ' . $email
        );
        
        if (wp_mail($to, $email_subject, $email_body, $headers)) {
            echo '<div class="notice notice-success"><p>' . __('Din besked er sendt! Vi vender tilbage hurtigst muligt.', 'idguard') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Der opstod en fejl ved afsendelse. Kontakt os direkte på kontakt@arpecompany.dk', 'idguard') . '</p></div>';
        }
    }
    ?>
    <style>
    .idguard-support {
        background: white;
        border-radius: 8px;
        padding: 2em;
        margin: 1em 0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .support-grid {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 2em;
        margin: 2em 0;
    }
    .support-form {
        background: #f8f9fa;
        padding: 2em;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    .support-info {
        background: linear-gradient(135deg, #004cb8 0%, #0066cc 100%);
        color: white;
        padding: 2em;
        border-radius: 8px;
        height: fit-content;
    }
    .form-group {
        margin-bottom: 1.5em;
    }
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5em;
        color: #1a202c;
    }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 0.75em;
        border: 2px solid #e2e8f0;
        border-radius: 6px;
        font-size: 1em;
        transition: border-color 0.3s ease;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: #004cb8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,76,184,0.1);
    }
    .form-group textarea {
        min-height: 120px;
        resize: vertical;
    }
    .contact-method {
        display: flex;
        align-items: center;
        gap: 1em;
        margin: 1em 0;
        padding: 1em;
        background: rgba(255,255,255,0.1);
        border-radius: 6px;
    }
    .contact-method .dashicons {
        font-size: 1.5em;
    }
    .status-indicator {
        display: inline-block;
        padding: 0.3em 0.8em;
        border-radius: 15px;
        font-size: 0.8em;
        font-weight: bold;
        text-transform: uppercase;
        margin-left: 0.5em;
    }
    .status-active {
        background: #d4edda;
        color: #155724;
    }
    .status-inactive {
        background: #f8d7da;
        color: #721c24;
    }
    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1em;
        margin: 2em 0;
    }
    .quick-link {
        background: #f8f9fa;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 1em;
        text-align: center;
        transition: all 0.3s ease;
        text-decoration: none;
        color: #1a202c;
    }
    .quick-link:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        text-decoration: none;
        color: #004cb8;
    }
    .quick-link .dashicons {
        font-size: 2em;
        color: #004cb8;
        margin-bottom: 0.5em;
    }
    @media (max-width: 1000px) {
        .support-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
    
    <div class="wrap">
        <h1><?php _e('IDguard Kundeservice', 'idguard'); ?></h1>
        
        <div class="idguard-support">
            <div style="text-align: center; margin-bottom: 2em;">
                <h2><?php _e('Vi er her for at hjælpe!', 'idguard'); ?></h2>
                <p><?php _e('Har du spørgsmål eller brug for hjælp med IDguard? Kontakt os via formularen nedenfor eller brug en af de hurtige kontaktmetoder.', 'idguard'); ?></p>
            </div>
            
            <div class="support-grid">
                <div class="support-form">
                    <h3><?php _e('Send os en besked', 'idguard'); ?></h3>
                    <form method="post" action="">
                        <?php wp_nonce_field('idguard_support', 'support_nonce'); ?>
                        
                        <div class="form-group">
                            <label for="support_name"><?php _e('Dit navn', 'idguard'); ?> *</label>
                            <input type="text" id="support_name" name="support_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="support_email"><?php _e('Din email', 'idguard'); ?> *</label>
                                                       <input type="email" id="support_email" name="support_email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="support_subject"><?php _e('Emne', 'idguard'); ?> *</label>
                            <select id="support_subject" name="support_subject" required>
                                <option value=""><?php _e('Vælg emne...', 'idguard'); ?></option>
                                <option value="Teknisk support"><?php _e('Teknisk support', 'idguard'); ?></option>
                                <option value="Opsætning hjælp"><?php _e('Hjælp til opsætning', 'idguard'); ?></option>
                                <option value="Billing spørgsmål"><?php _e('Fakturering/betaling', 'idguard'); ?></option>
                                <option value="Feature request"><?php _e('Forslag til nye funktioner', 'idguard'); ?></option>
                                <option value="Bug report"><?php _e('Rapporter fejl', 'idguard'); ?></option>
                                <option value="Andet"><?php _e('Andet spørgsmål', 'idguard'); ?></option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="support_message"><?php _e('Din besked', 'idguard'); ?> *</label>
                            <textarea id="support_message" name="support_message" placeholder="Beskriv dit spørgsmål eller problem så detaljeret som muligt..." required></textarea>
                        </div>
                        
                        <button type="submit" name="submit_support" class="button button-primary button-large" style="background: #004cb8; border-color: #004cb8;">
                            <?php _e('Send besked', 'idguard'); ?>
                        </button>
                    </form>
                </div>
                
                <div class="support-info">
                    <h3 style="color: white;"><?php _e('Kontakt information', 'idguard'); ?></h3>
                    
                    <div class="contact-method">
                        <span class="dashicons dashicons-email-alt"></span>
                        <div>
                            <strong><?php _e('Email support', 'idguard'); ?></strong><br>
                            kontakt@arpecompany.dk<br>
                            <small><?php _e('Svartid: 1-2 hverdage', 'idguard'); ?></small>
                        </div>
                    </div>
                    
                    <div class="contact-method">
                        <span class="dashicons dashicons-clock"></span>
                        <div>
                            <strong><?php _e('Support timer', 'idguard'); ?></strong><br>
                            <?php _e('Mandag-Fredag: 9:00-17:00', 'idguard'); ?><br>
                            <small><?php _e('Dansk tid (CET/CEST)', 'idguard'); ?></small>
                        </div>
                    </div>
                    
                    <div class="contact-method">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <div>
                            <strong><?php _e('Website', 'idguard'); ?></strong><br>
                            <a href="https://idguard.dk" target="_blank" style="color: white;">idguard.dk</a><br>
                            <small><?php _e('Dokumentation og guides', 'idguard'); ?></small>
                        </div>
                    </div>
                    
                    <hr style="border-color: rgba(255,255,255,0.3); margin: 2em 0;">
                    
                    <h4><?php _e('Plugin status', 'idguard'); ?></h4>
                    <p>
                        <strong><?php _e('Version:', 'idguard'); ?></strong> 2.1.1.63<br>
                        <strong><?php _e('WooCommerce:', 'idguard'); ?></strong>
                        <?php if (class_exists('WooCommerce')): ?>
                            <span class="status-indicator status-active"><?php _e('Aktiv', 'idguard'); ?></span>
                        <?php else: ?>
                            <span class="status-indicator status-inactive"><?php _e('Ikke fundet', 'idguard'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <h3><?php _e('Hurtige links', 'idguard'); ?></h3>
            <div class="quick-links">
                <a href="<?php echo admin_url('admin.php?page=idguard'); ?>" class="quick-link">
                    <div class="dashicons dashicons-admin-tools"></div>
                    <h4><?php _e('Indstillinger', 'idguard'); ?></h4>
                    <p><?php _e('Grundlæggende opsætning', 'idguard'); ?></p>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=idguard_popup'); ?>" class="quick-link">
                    <div class="dashicons dashicons-admin-appearance"></div>
                    <h4><?php _e('Design', 'idguard'); ?></h4>
                    <p><?php _e('Tilpas popup udseende', 'idguard'); ?></p>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=idguard_documentation'); ?>" class="quick-link">
                    <div class="dashicons dashicons-book-alt"></div>
                    <h4><?php _e('Dokumentation', 'idguard'); ?></h4>
                    <p><?php _e('Guides og FAQ', 'idguard'); ?></p>
                </a>
                
                <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="quick-link">
                    <div class="dashicons dashicons-products"></div>
                    <h4><?php _e('Produkter', 'idguard'); ?></h4>
                    <p><?php _e('Sæt aldersgrænser', 'idguard'); ?></p>
                </a>
            </div>
            
            <div style="background: #e8f4fd; border-left: 4px solid #004cb8; padding: 1em; margin-top: 2em; border-radius: 0 6px 6px 0;">
                <strong><?php _e('Tips til bedre support:', 'idguard'); ?></strong>
                <ul style="margin: 0.5em 0 0 1em;">
                    <li><?php _e('Beskriv problemet så detaljeret som muligt', 'idguard'); ?></li>
                    <li><?php _e('Inkluder skærmbilleder hvis relevant', 'idguard'); ?></li>
                    <li><?php _e('Angiv din WordPress og WooCommerce version', 'idguard'); ?></li>
                    <li><?php _e('Fortæl hvilke trin du allerede har prøvet', 'idguard'); ?></li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

// IDguard shortcode for use outside WooCommerce
function idguard_shortcode($atts) {
    $atts = shortcode_atts(array(
        'age' => '18',
        'title' => '',
        'message' => '',
        'button_text' => 'Verificer min alder',
        'redirect_url' => '',
        'class' => 'idguard-shortcode'
    ), $atts);
    
    // Validate age limit
    $allowed_ages = array('15', '16', '18', '21');
    if (!in_array($atts['age'], $allowed_ages)) {
        $atts['age'] = '18';
    }
    
    // Use default texts if not provided
    if (empty($atts['title'])) {
        $atts['title'] = get_option('idguard_popup_title', 'Aldersverifikation påkrævet');
    }
    if (empty($atts['message'])) {
        $atts['message'] = get_option('idguard_popup_message', 'Du skal være ' . $atts['age'] . ' år eller ældre for at fortsætte.');
    }
    
    // Generate unique ID for this shortcode instance
    $unique_id = 'idguard_' . uniqid();
    
    // Enqueue necessary scripts
    wp_enqueue_script('idguard-shortcode', plugins_url('idguard.js', __FILE__), array('jquery'), '1.0.0', true);
    
    // Get customization options
    $customization = array(
        'popupTitle' => $atts['title'],
        'popupMessage' => $atts['message'],
        'confirmButton' => $atts['button_text'],
        'cancelButton' => get_option('idguard_popup_cancel_button_text', 'Gå tilbage'),
        'popupTextColor' => get_option('idguard_popup_text_color', '#000000'),
        'popupBackgroundColor' => get_option('idguard_popup_background_color', '#ffffff'),
        'popupVerifyButtonColor' => get_option('idguard_popup_verify_button_color', '#004cb8'),
        'popupVerifyButtonTextColor' => get_option('idguard_popup_verify_button_text_color', '#ffffff'),
        'popupCancelButtonColor' => get_option('idguard_popup_cancel_button_color', '#d6d6d6'),
        'popupCancelButtonTextColor' => get_option('idguard_popup_cancel_button_text_color', '#000000'),
        'requiredAge' => $atts['age'],
        'redirectUrl' => $atts['redirect_url'],
        'isShortcode' => true
    );
    
    // Localize script for this specific shortcode
    wp_localize_script('idguard-shortcode', 'idguardShortcodeData_' . $unique_id, array(
        'customization' => $customization,
        'nonce' => wp_create_nonce('idguard_nonce')
    ));
    
    ob_start();
    ?>
    <button id="<?php echo esc_attr($unique_id); ?>" class="<?php echo esc_attr($atts['class']); ?> idguard-trigger-btn" 
            style="background: <?php echo esc_attr(get_option('idguard_popup_verify_button_color', '#004cb8')); ?>; 
                   color: <?php echo esc_attr(get_option('idguard_popup_verify_button_text_color', '#ffffff')); ?>;
                   border: none; padding: 0.7em 1.2em; border-radius: 6px; cursor: pointer; font-size: 1em;">
        <?php echo esc_html($atts['button_text']); ?>
    </button>
    
    <script>
    jQuery(document).ready(function($) {
        $('#<?php echo esc_js($unique_id); ?>').on('click', function(e) {
            e.preventDefault();
            
            var config = window.idguardShortcodeData_<?php echo esc_js($unique_id); ?>;
            if (!config) return;
            
            // Create IDguard popup
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999999; display: flex; align-items: center; justify-content: center; cursor: pointer; backdrop-filter: blur(2px);';
            
            var popup = document.createElement('div');
            popup.style.cssText = 'background: ' + config.customization.popupBackgroundColor + '; color: ' + config.customization.popupTextColor + '; padding: 2.5em 2em; border-radius: 16px; max-width: 420px; width: 90%; text-align: center; position: relative; box-shadow: 0 20px 40px rgba(0,0,0,0.15); cursor: default; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';
            
            var closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'position: absolute; top: 15px; right: 20px; background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1; opacity: 0.7; transition: opacity 0.2s;';
            closeBtn.onclick = function() { overlay.remove(); };
            
            var titleEl = document.createElement('h2');
            titleEl.textContent = config.customization.popupTitle;
            titleEl.style.cssText = 'margin: 0 0 1em 0; font-size: 1.4em; font-weight: 600; color: ' + config.customization.popupTextColor + '; line-height: 1.3;';
            
            var messageEl = document.createElement('p');
            messageEl.textContent = config.customization.popupMessage;
            messageEl.style.cssText = 'margin: 0 0 2em 0; font-size: 1em; line-height: 1.5; color: ' + config.customization.popupTextColor + '; opacity: 0.9;';
            
            var buttonContainer = document.createElement('div');
            buttonContainer.style.cssText = 'display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;';
            
            var confirmBtn = document.createElement('button');
            confirmBtn.textContent = config.customization.confirmButton;
            confirmBtn.style.cssText = 'background: ' + config.customization.popupVerifyButtonColor + '; color: ' + config.customization.popupVerifyButtonTextColor + '; border: none; padding: 12px 24px; border-radius: 8px; font-size: 1em; font-weight: 500; cursor: pointer; min-width: 140px; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
            confirmBtn.onclick = function() {
                // Trigger MitID verification (simplified for shortcode)
                overlay.remove();
                if (config.customization.redirectUrl) {
                    window.location.href = config.customization.redirectUrl;
                } else {
                    alert('Aldersverifikation gennemført! (Demo mode)');
                }
            };
            
            var cancelBtn = document.createElement('button');
            cancelBtn.textContent = config.customization.cancelButton;
            cancelBtn.style.cssText = 'background: ' + config.customization.popupCancelButtonColor + '; color: ' + config.customization.popupCancelButtonTextColor + '; border: none; padding: 12px 24px; border-radius: 8px; font-size: 1em; font-weight: 500; cursor: pointer; min-width: 140px; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
            cancelBtn.onclick = function() { overlay.remove(); };
            
            buttonContainer.appendChild(confirmBtn);
            buttonContainer.appendChild(cancelBtn);
            
            popup.appendChild(closeBtn);
            popup.appendChild(titleEl);
            popup.appendChild(messageEl);
            popup.appendChild(buttonContainer);
            
            overlay.appendChild(popup);
            document.body.appendChild(overlay);
            
            // Close on overlay click
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) overlay.remove();
            });
            
            // Close on escape key
            var escapeHandler = function(e) {
                if (e.key === 'Escape') {
                    overlay.remove();
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('idguard', 'idguard_shortcode');

// Check if current cart or product page has age-restricted items
function idguard_get_required_age_for_verification() {
    $age_verification_mode = get_option('idguard_age_verification_mode', 'off');
    
    if ($age_verification_mode === 'off') {
        return false;
    }
    
    $global_age_limit = get_option('idguard_global_age_limit', '');
    
    if ($age_verification_mode === 'global' && !empty($global_age_limit)) {
        return intval($global_age_limit);
    }
    
    if ($age_verification_mode === 'individual') {
        if (idguard_has_cart_items()) {
            $max_age_limit = 0;
            foreach (idguard_get_cart_items() as $cart_item) {
                $product_id = $cart_item['product_id'];
                $product_age_limit = get_post_meta($product_id, '_age_limit', true);
                
                // Also check category age limits
                $product = wc_get_product($product_id);
                if ($product) {
                    $category_ids = $product->get_category_ids();
                    foreach ($category_ids as $category_id) {
                        $category_age_limit = get_term_meta($category_id, '_age_limit', true);
                        if (!empty($category_age_limit) && intval($category_age_limit) > $max_age_limit) {
                            $max_age_limit = intval($category_age_limit);
                        }
                    }
                }
                
                if (!empty($product_age_limit) && intval($product_age_limit) > $max_age_limit) {
                    $max_age_limit = intval($product_age_limit);
                }
            }
            return $max_age_limit > 0 ? $max_age_limit : false;
        }
    }
    
    return false;
}

// Add age limit field to product edit page
function idguard_add_product_age_limit_field() {
    $current_value = get_post_meta(get_the_ID(), '_age_limit', true);
    ?>
    <style>
    .idguard-product-age-select {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e2e8f0;
        border-radius: 6px;
        padding: 0.6em 2em 0.6em 0.8em;
        font-size: 0.95em;
        font-weight: 500;
        color: #1a202c;
        min-width: 140px;
        cursor: pointer;
        transition: all 0.3s ease;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
        background-position: right 0.5rem center;
        background-repeat: no-repeat;
        background-size: 1.2em 1.2em;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }
    .idguard-product-age-select:hover {
        border-color: #004cb8;
        background: linear-gradient(135deg, #ffffff 0%, #f0f4ff 100%);
        box-shadow: 0 2px 6px rgba(0,76,184,0.1);
    }
    .idguard-product-age-select:focus {
        border-color: #004cb8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,76,184,0.15);
        background: white;
    }
    </style>
    <div class="options_group">
        <p class="form-field _age_limit_field">
            <label for="_age_limit"><?php _e('Aldersgrænse', 'idguard'); ?></label>
            <select id="_age_limit" name="_age_limit" class="idguard-product-age-select">
                <option value=""><?php _e('Ingen aldersgrænse', 'idguard'); ?></option>
                <option value="15" <?php selected($current_value, '15'); ?>>15+ år</option>
                <option value="16" <?php selected($current_value, '16'); ?>>16+ år</option>
                <option value="18" <?php selected($current_value, '18'); ?>>18+ år</option>
                <option value="21" <?php selected($current_value, '21'); ?>>21+ år</option>
            </select>
            <span class="description"><?php _e('Vælg den påkrævede minimumsalder for dette produkt. Kun 15, 16, 18 og 21 år er tilgængelige via MitID API.', 'idguard'); ?></span>
        </p>
    </div>
    <?php
}
add_action('woocommerce_product_options_general_product_data', 'idguard_add_product_age_limit_field');

// Save product age limit field
function idguard_save_product_age_limit_field($post_id) {
    // Security check: verify user has capability to edit products
    if (!current_user_can('edit_product', $post_id)) {
        return;
    }
    
    // Security check: verify nonce
    if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) {
        return;
    }
    
    // Check if this is an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (isset($_POST['_age_limit'])) {
        $age_limit = sanitize_text_field($_POST['_age_limit']);
        // Validate against allowed age presets
        $allowed_ages = array('15', '16', '18', '21');
        if (!empty($age_limit) && in_array($age_limit, $allowed_ages)) {
            update_post_meta($post_id, '_age_limit', $age_limit);
        } else {
            delete_post_meta($post_id, '_age_limit');
        }
    }
}
add_action('woocommerce_process_product_meta', 'idguard_save_product_age_limit_field');

// Add age limit field to category edit page
function idguard_add_category_age_limit_field($term) {
    $age_limit = get_term_meta($term->term_id, '_age_limit', true);
    ?>
    <style>
    .idguard-category-age-select {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e2e8f0;
        border-radius: 6px;
        padding: 0.6em 2em 0.6em 0.8em;
        font-size: 0.95em;
        font-weight: 500;
        color: #1a202c;
        min-width: 160px;
        cursor: pointer;
        transition: all 0.3s ease;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
        background-position: right 0.5rem center;
        background-repeat: no-repeat;
        background-size: 1.2em 1.2em;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }
    .idguard-category-age-select:hover {
        border-color: #004cb8;
        background: linear-gradient(135deg, #ffffff 0%, #f0f4ff 100%);
        box-shadow: 0 2px 6px rgba(0,76,184,0.1);
    }
    .idguard-category-age-select:focus {
        border-color: #004cb8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,76,184,0.15);
        background: white;
    }
    </style>
    <tr class="form-field">
        <th scope="row" valign="top">
            <label for="age_limit"><?php _e('Aldersgrænse', 'idguard'); ?></label>
        </th>
        <td>
            <select name="age_limit" id="age_limit" class="idguard-category-age-select">
                <option value=""><?php _e('Ingen aldersgrænse', 'idguard'); ?></option>
                <option value="15" <?php selected($age_limit, '15'); ?>>15+ år</option>
                <option value="16" <?php selected($age_limit, '16'); ?>>16+ år</option>
                <option value="18" <?php selected($age_limit, '18'); ?>>18+ år</option>
                <option value="21" <?php selected($age_limit, '21'); ?>>21+ år</option>
            </select>
            <p class="description"><?php _e('Vælg den påkrævede minimumsalder for produkter i denne kategori. Kun 15, 16, 18 og 21 år er tilgængelige via MitID API.', 'idguard'); ?></p>
        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'idguard_add_category_age_limit_field');

// Save category age limit field
function idguard_save_category_age_limit_field($term_id) {
    // Security check: verify user has capability to edit terms
    if (!current_user_can('manage_product_terms')) {
        return;
    }
    
    // Security check: verify nonce (WordPress handles this for category forms)
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update-tag_' . $term_id)) {
        return;
    }
    
    if (isset($_POST['age_limit'])) {
        $age_limit = sanitize_text_field($_POST['age_limit']);
        // Validate against allowed age presets
        $allowed_ages = array('15', '16', '18', '21');
        if (!empty($age_limit) && in_array($age_limit, $allowed_ages)) {
            update_term_meta($term_id, '_age_limit', $age_limit);
        } else {
            delete_term_meta($term_id, '_age_limit');
        }
    }
}
add_action('edited_product_cat', 'idguard_save_category_age_limit_field');

// Add column to products admin list
function idguard_add_product_columns($columns) {
    $columns['age_limit'] = __('Aldersgrænse', 'idguard');
    return $columns;
}
add_filter('manage_edit-product_columns', 'idguard_add_product_columns');

// Populate age limit column
function idguard_populate_product_columns($column, $post_id) {
    if ($column == 'age_limit') {
        $age_limit = get_post_meta($post_id, '_age_limit', true);
        echo $age_limit ? $age_limit . '+' : '—';
    }
}
add_action('manage_product_posts_custom_column', 'idguard_populate_product_columns', 10, 2);

// Add column to categories admin list
function idguard_add_category_columns($columns) {
    $columns['age_limit'] = __('Aldersgrænse', 'idguard');
    return $columns;
}
add_filter('manage_edit-product_cat_columns', 'idguard_add_category_columns');

// Populate category age limit column
function idguard_populate_category_columns($content, $column_name, $term_id) {
    if ($column_name == 'age_limit') {
        $age_limit = get_term_meta($term_id, '_age_limit', true);
        $content = $age_limit ? $age_limit . '+' : '—';
    }
    return $content;
}
add_filter('manage_product_cat_custom_column', 'idguard_populate_category_columns', 10, 3);

// Ajax handler for dismissing notices
function idguard_dismiss_notice() {
    // Security check: verify nonce
    check_ajax_referer('idguard_dismiss_notice', 'nonce');
    
    // Security check: verify user has capability
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient privileges');
    }
    
    if (isset($_POST['notice'])) {
        $notice = sanitize_text_field($_POST['notice']);
        // Validate notice name to prevent arbitrary option names
        if (in_array($notice, ['idguard_notice'])) {
            update_option('dismissed-' . $notice, true);
        }
    }
    wp_die();
}
add_action('wp_ajax_idguard_dismiss_notice', 'idguard_dismiss_notice');

// Add dismiss notice script
function idguard_dismiss_notice_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $(document).on('click', '.notice[data-notice] .notice-dismiss', function() {
            var notice = $(this).parent().attr('data-notice');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'idguard_dismiss_notice',
                    notice: notice,
                    nonce: '<?php echo wp_create_nonce('idguard_dismiss_notice'); ?>'
                }
            });
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'idguard_dismiss_notice_script');

// Activation hook
function idguard_activate() {
    set_transient('idguard_plugin_activated', true, 30);
}
register_activation_hook(__FILE__, 'idguard_activate');

// Check for WooCommerce dependency
function idguard_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>IDguard:</strong> ' . __('Dette plugin kræver WooCommerce for at fungere korrekt.', 'idguard');
            echo '</p></div>';
        });
        return false;
    }
    return true;
}
add_action('plugins_loaded', 'idguard_check_woocommerce');

// Check PHP and WordPress versions
function idguard_check_requirements() {
    if (version_compare(PHP_VERSION, IDGUARD_MIN_PHP_VER, '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            printf(__('IDguard kræver PHP %s eller nyere. Du kører version %s.', 'idguard'), IDGUARD_MIN_PHP_VER, PHP_VERSION);
            echo '</p></div>';
        });
        return false;
    }
    
    global $wp_version;
    if (version_compare($wp_version, IDGUARD_MIN_WP_VER, '<')) {
        add_action('admin_notices', function() use ($wp_version) {
            echo '<div class="notice notice-error"><p>';
            printf(__('IDguard kræver WordPress %s eller nyere. Du kører version %s.', 'idguard'), IDGUARD_MIN_WP_VER, $wp_version);
            echo '</p></div>';
        });
        return false;
    }
    
    return true;
}
add_action('plugins_loaded', 'idguard_check_requirements');

// Plugin deactivation hook
function idguard_deactivate() {
    // Clean up any temporary data
    delete_transient('idguard_plugin_activated');
}
register_deactivation_hook(__FILE__, 'idguard_deactivate');

// Helper function to safely check if WooCommerce cart exists and has items
function idguard_has_cart_items() {
    if (!function_exists('WC') || !WC()->cart) {
        return false;
    }
    
    return !WC()->cart->is_empty();
}

// Helper function to safely get cart items
function idguard_get_cart_items() {
    if (!function_exists('WC') || !WC()->cart) {
        return array();
    }
    
    return WC()->cart->get_cart();
}

// Helper function to get allowed age presets for IDguard API
function idguard_get_allowed_ages() {
    return array('15', '16', '18', '21');
}

// Helper function to validate age limit against presets
function idguard_validate_age_limit($age_limit) {
    $allowed_ages = idguard_get_allowed_ages();
    return in_array($age_limit, $allowed_ages) ? $age_limit : '';
}
