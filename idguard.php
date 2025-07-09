<?php
/**
 * Plugin Name: IDguard
 * Plugin URI: https://idguard.dk
 * Description: Foretag automatisk alderstjek med MitID ved betaling på WooCommerce-webshops
 * Version: 2.1.1.62
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
    $settings_link = '<a href="' . admin_url('admin.php?page=idguard') . '">' . idguard_dk_text('⛨ Konfigurer IDguard') . '</a>';
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
            <p><?php _e('🎉 Tak fordi du har valgt IDguard! Gennemgå venligst dine indstillinger for at sikre, at alt er sat korrekt op.', 'idguard'); ?></p>
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
        
        echo '<div class="notice notice-success"><p>' . __('Indstillinger gemt!', 'idguard') . '</p></div>';
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
    </style>
    
    <div class="wrap">
        <?php if (!get_option('idguard_onboarding_completed', false)): ?>
        <div class="idguard-welcome-banner">
            <p><strong>🎉 Velkommen til IDguard!</strong> Opsæt automatisk aldersverifikation på få minutter.</p>
        </div>
        <?php endif; ?>
        
        <div class="idguard-admin">
            <h1><span style="color:#004cb8;">⛨</span> <?php _e('IDguard Indstillinger', 'idguard'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('idguard_general_settings-options'); ?>
                
                <h2><?php _e('Aldersverifikations-metode', 'idguard'); ?></h2>
                <p><?php _e('Vælg hvordan IDguard skal håndtere aldersverifikation på din webshop.', 'idguard'); ?></p>
                    
                    <div class="idguard-mode-card <?php echo $current_mode === 'off' ? 'selected' : ''; ?>" data-mode="off">
                        <h3>🔴 Deaktiveret <span class="idguard-status status-disabled">Inaktiv</span></h3>
                        <p>Ingen aldersverifikation. Alle kunder kan gennemføre køb uden begrænsninger.</p>
                        <input type="radio" name="idguard_age_verification_mode" value="off" <?php checked($current_mode, 'off'); ?> style="margin-top: 1em;">
                    </div>
                    
                    <div class="idguard-mode-card <?php echo $current_mode === 'global' ? 'selected' : ''; ?>" data-mode="global">
                        <h3>🌐 Global aldersgrænse <span class="idguard-status status-enabled">Aktiv</span></h3>
                        <p>Alle produkter på webshop'en kræver samme aldersverifikation. Enkel opsætning.</p>
                        <input type="radio" name="idguard_age_verification_mode" value="global" <?php checked($current_mode, 'global'); ?> style="margin-top: 1em;">
                        
                        <div class="idguard-global-age <?php echo $current_mode === 'global' ? 'show' : ''; ?>">
                            <label><strong>Minimum alder:</strong></label>
                            <select name="idguard_global_age_limit" class="age-input" style="width: auto; min-width: 80px;">
                                <option value="15" <?php selected($current_global_age, '15'); ?>>15 år</option>
                                <option value="16" <?php selected($current_global_age, '16'); ?>>16 år</option>
                                <option value="18" <?php selected($current_global_age, '18'); ?>>18 år</option>
                                <option value="21" <?php selected($current_global_age, '21'); ?>>21 år</option>
                            </select>
                            <p style="font-size: 0.9em; color: #666; margin-top: 0.5em;">Kun 15, 16, 18 og 21 år er tilgængelige via MitID API.</p>
                        </div>
                    </div>
                    
                    <div class="idguard-mode-card <?php echo $current_mode === 'individual' ? 'selected' : ''; ?>" data-mode="individual">
                        <h3>🎯 Individuelle aldersgrænser <span class="idguard-status status-enabled">Avanceret</span></h3>
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
                    <h4>💡 Tip til opsætning</h4>
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
                <button onclick="idguardShowPopup()" class="idguard-preview-btn">👁️ Forhåndsvis popup</button>
                <p style="color:#666; font-size:0.9em; margin-top:0.5em;">Test hvordan popup'en vil se ud for dine kunder</p>
            </div>
        </div>
    </div>
    
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
        grid-template-columns: 1fr 400px;
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
        padding: 2em;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        position: sticky;
        top: 32px;
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
    .form-group input, .form-group textarea, .form-group select {
        width: 100%;
        padding: 0.75em;
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
        min-height: 100px;
    }
    .color-input-group {
        display: flex;
        align-items: center;
        gap: 0.5em;
    }
    .color-preview {
        width: 40px;
        height: 40px;
        border-radius: 6px;
        border: 2px solid #e2e8f0;
    }
    .popup-preview {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1.5em;
        background: #f8f9fa;
        text-align: center;
        margin-top: 1em;
    }
    .preview-popup-content {
        border-radius: 8px;
        padding: 2em 1.5em;
        max-width: 300px;
        margin: 0 auto;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .preview-button {
        padding: 0.75em 1.5em;
        border: none;
        border-radius: 6px;
        font-size: 1em;
        margin: 0.5em 0.25em;
        cursor: pointer;
        min-width: 120px;
        transition: all 0.3s ease;
    }
    .preview-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .section-divider {
        border: none;
        height: 2px;
        background: linear-gradient(90deg, #004cb8, transparent);
        margin: 2em 0;
    }
    .help-text {
        background: #e8f4fd;
        border-left: 4px solid #004cb8;
        padding: 1em;
        margin: 1em 0;
        border-radius: 0 6px 6px 0;
        font-size: 0.9em;
    }
    .custom-url-field {
        display: none;
        margin-top: 1em;
    }
    .custom-url-field.show {
        display: block;
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
        <h1><span style="color:#004cb8;">🎨</span> <?php _e('IDguard Popup Design & Tekster', 'idguard'); ?></h1>
        
        <div class="idguard-popup-admin">
            <div class="idguard-form-section">
                <form method="post" action="">
                    <?php wp_nonce_field('idguard_popup_settings-options'); ?>
                    
                    <h2><?php _e('Popup Tekster', 'idguard'); ?></h2>
                    
                    <div class="form-group">
                        <label for="popup_title"><?php _e('Popup Titel', 'idguard'); ?></label>
                        <input type="text" id="popup_title" name="idguard_popup_title" value="<?php echo esc_attr($title); ?>" onchange="updatePreview()">
                    </div>
                    
                    <div class="form-group">
                        <label for="popup_message"><?php _e('Popup Besked', 'idguard'); ?></label>
                        <textarea id="popup_message" name="idguard_popup_message" onchange="updatePreview()"><?php echo esc_textarea($message); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_button"><?php _e('Bekræft Knap Tekst', 'idguard'); ?></label>
                        <input type="text" id="confirm_button" name="idguard_popup_button_text" value="<?php echo esc_attr($confirm_text); ?>" onchange="updatePreview()">
                    </div>
                    
                    <div class="form-group">
                        <label for="cancel_button"><?php _e('Annuller Knap Tekst', 'idguard'); ?></label>
                        <input type="text" id="cancel_button" name="idguard_popup_cancel_button_text" value="<?php echo esc_attr($cancel_text); ?>" onchange="updatePreview()">
                    </div>
                    
                    <hr class="section-divider">
                    
                    <h2><?php _e('Popup Farver', 'idguard'); ?></h2>
                    
                    <div class="form-group">
                        <label for="text_color"><?php _e('Tekst Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="text_color" name="idguard_popup_text_color" value="<?php echo esc_attr($text_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($text_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="bg_color"><?php _e('Baggrund Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="bg_color" name="idguard_popup_background_color" value="<?php echo esc_attr($bg_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($bg_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="verify_btn_color"><?php _e('Bekræft Knap Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="verify_btn_color" name="idguard_popup_verify_button_color" value="<?php echo esc_attr($verify_btn_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($verify_btn_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="verify_btn_text_color"><?php _e('Bekræft Knap Tekst Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="verify_btn_text_color" name="idguard_popup_verify_button_text_color" value="<?php echo esc_attr($verify_btn_text_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($verify_btn_text_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="cancel_btn_color"><?php _e('Annuller Knap Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="cancel_btn_color" name="idguard_popup_cancel_button_color" value="<?php echo esc_attr($cancel_btn_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($cancel_btn_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="cancel_btn_text_color"><?php _e('Annuller Knap Tekst Farve', 'idguard'); ?></label>
                        <div class="color-input-group">
                            <input type="color" id="cancel_btn_text_color" name="idguard_popup_cancel_button_text_color" value="<?php echo esc_attr($cancel_btn_text_color); ?>" onchange="updatePreview()">
                            <input type="text" value="<?php echo esc_attr($cancel_btn_text_color); ?>" readonly style="width: 80px;">
                        </div>
                    </div>
                    
                    <hr class="section-divider">
                    
                    <h2><?php _e('Annullering Indstillinger', 'idguard'); ?></h2>
                    
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
                        <strong>💡 Tips til bedre brugeroplevelse:</strong>
                        <ul>
                            <li>Hold beskederne korte og tydelige</li>
                            <li>Brug farver der matcher dit brand</li>
                            <li>Test popup'en på forskellige enheder</li>
                        </ul>
                    </div>
                    
                    <?php submit_button(__('Gem Design Indstillinger', 'idguard'), 'primary large', 'submit', true, array('style' => 'font-size:1.2em;padding:0.7em 2em;background:#004cb8;border-radius:5px;border:none;margin-top:2em;')); ?>
                </form>
            </div>
            
            <div class="idguard-preview-section">
                <h3><?php _e('Live Forhåndsvisning', 'idguard'); ?></h3>
                <p style="color:#666; font-size:0.9em;"><?php _e('Se hvordan popup\'en vil se ud for dine kunder', 'idguard'); ?></p>
                
                <div class="popup-preview">
                    <div id="preview-popup" class="preview-popup-content">
                        <h3 id="preview-title"><?php echo esc_html($title); ?></h3>
                        <p id="preview-message"><?php echo esc_html($message); ?></p>
                        <button id="preview-confirm" class="preview-button"><?php echo esc_html($confirm_text); ?></button>
                        <button id="preview-cancel" class="preview-button"><?php echo esc_html($cancel_text); ?></button>
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
        document.getElementById('preview-confirm').textContent = confirmText;
        document.getElementById('preview-cancel').textContent = cancelText;
        
        // Update colors
        preview.style.backgroundColor = bgColor;
        preview.style.color = textColor;
        
        document.getElementById('preview-confirm').style.backgroundColor = verifyBtnColor;
        document.getElementById('preview-confirm').style.color = verifyBtnTextColor;
        
        document.getElementById('preview-cancel').style.backgroundColor = cancelBtnColor;
        document.getElementById('preview-cancel').style.color = cancelBtnTextColor;
    }
    
    // Initialize preview
    updatePreview();
    
    // Add preview popup function for admin
    window.idguardShowPopup = function() {
        // Create a simple admin preview popup
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999999; display: flex; align-items: center; justify-content: center; cursor: pointer;';
        
        var popup = document.createElement('div');
        popup.style.cssText = 'background: white; padding: 2em; border-radius: 8px; max-width: 400px; text-align: center; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.3); cursor: default;';
        
        popup.innerHTML = `
            <button onclick="this.parentElement.parentElement.remove()" style="position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 24px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
            <h3 style="margin-top: 0; color: #333;">🎭 Preview: IDguard Popup</h3>
            <p style="color: #666; margin: 1em 0;">Dette er hvordan popup'en vil se ud for dine kunder.</p>
            <p style="color: #999; font-size: 0.85em; margin: 0;">Bemærk: Dette er kun en forhåndsvisning. Den faktiske popup indlæser MitID integration.</p>
        `;
        
        overlay.appendChild(popup);
        document.body.appendChild(overlay);
        
        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.remove();
            }
        });
        
        // Close on escape key
        var escapeHandler = function(e) {
            if (e.key === 'Escape') {
                overlay.remove();
                document.removeEventListener('keydown', escapeHandler);
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
        <h1>📖 <?php _e('IDguard Dokumentation & Hjælp', 'idguard'); ?></h1>
        
        <div class="idguard-docs">
            <div class="alert-box alert-info">
                <strong>💡 Velkommen til IDguard dokumentationen!</strong><br>
                Her finder du guides, tips og svar på de mest stillede spørgsmål om automatisk aldersverifikation med MitID.
            </div>
            
            <div class="docs-grid">
                <div class="docs-card">
                    <h3>🚀 Kom i gang</h3>
                    <p>Grundlæggende opsætning af IDguard på din webshop.</p>
                    <a href="#getting-started" class="button">Læs guide</a>
                </div>
                
                <div class="docs-card">
                    <h3>⚙️ Konfiguration</h3>
                    <p>Detaljeret guide til indstillinger og tilpasning.</p>
                    <a href="#configuration" class="button">Se indstillinger</a>
                </div>
                
                <div class="docs-card">
                    <h3>🎨 Design tilpasning</h3>
                    <p>Tilpas popup-design til dit brand og dine farver.</p>
                    <a href="#design" class="button">Læs om design</a>
                </div>
                
                <div class="docs-card">
                    <h3>🔧 Fejlfinding</h3>
                    <p>Løsninger på almindelige problemer og fejl.</p>
                    <a href="#troubleshooting" class="button">Se fejlfinding</a>
                </div>
            </div>
            
            <h2 id="getting-started">🚀 Kom i gang med IDguard</h2>
            
            <p>IDguard automatiserer aldersverifikation med MitID i din WooCommerce webshop. Følg denne trin-for-trin guide for at komme i gang:</p>
            
            <ol class="step-list">
                <li>
                    <strong>Vælg verifikationsmetode</strong><br>
                    Gå til <em>IDguard → Indstillinger</em> og vælg mellem global aldersgrænse eller individuelle grænser pr. produkt/kategori.
                </li>
                <li>
                    <strong>Sæt aldersgrænser</strong><br>
                    Hvis du valgte "Global", sæt den ønskede aldersgrænse. Ved "Individuel" skal du sætte grænser på produkter og kategorier.
                </li>
                <li>
                    <strong>Tilpas popup design</strong><br>
                    Under <em>Design & Tekster</em> kan du tilpasse farver, tekster og omdirigering efter dit brand.
                </li>
                <li>
                    <strong>Test funktionaliteten</strong><br>
                    Brug "Forhåndsvis popup" funktionen til at teste før du aktiverer.
                </li>
                <li>
                    <strong>Gå live</strong><br>
                    Når alt er sat op, vil aldersverifikation automatisk træde i kraft ved checkout.
                </li>
            </ol>
            
            <div class="alert-box alert-success">
                <strong>✅ Pro tip:</strong> Start med global aldersgrænse for enkel opsætning, og skift til individuelle grænser hvis du har brug for mere kontrol.
            </div>
            
            <h2 id="configuration">⚙️ Konfiguration i detaljer</h2>
            
            <h3>Aldersverifikations metoder</h3>
            
            <div class="alert-box alert-info">
                <strong>⚠️ Vigtig information om aldersgrænser:</strong><br>
                IDguard understøtter kun følgende aldersgrænser via MitID API: <strong>15, 16, 18 og 21 år</strong>. Andre aldersgrænser er ikke mulige.
            </div>
            
            <h4>🔴 Deaktiveret</h4>
            <p>Ingen aldersverifikation. Alle kunder kan købe alle produkter uden begrænsninger.</p>
            
            <h4>🌐 Global aldersgrænse</h4>
            <p>Samme aldersgrænse for alle produkter på webshop'en. Simpel løsning for butikker hvor alle produkter kræver samme alder.</p>
            <ul>
                <li>Vælg en af de tilgængelige aldersgrænser: 15, 16, 18 eller 21 år</li>
                <li>Alle produkter i kurven udløser verifikation</li>
                <li>Hurtig opsætning</li>
            </ul>
            
            <h4>🎯 Individuelle aldersgrænser</h4>
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
            
            <h2 id="design">🎨 Design tilpasning</h2>
            
            <p>IDguard giver dig fuld kontrol over popup'ens udseende så den matcher dit brand perfekt.</p>
            
            <h3>Tekst tilpasning</h3>
            <ul>
                <li><strong>Popup titel:</strong> Hovedoverskrift i popup'en</li>
                <li><strong>Popup besked:</strong> Forklarende tekst til kunden</li>
                <li><strong>Bekræft knap:</strong> Tekst på knappen der starter MitID</li>
                <li><strong>Annuller knap:</strong> Tekst på knappen der afbryder</li>
            </ul>
            
            <h3>Farve tilpasning</h3>
            <ul>
                <li><strong>Tekst farve:</strong> Farve på al tekst i popup'en</li>
                <li><strong>Baggrund:</strong> Popup'ens baggrundfarve</li>
                <li><strong>Bekræft knap farver:</strong> Baggrund og tekst på "Fortsæt" knappen</li>
                <li><strong>Annuller knap farver:</strong> Baggrund og tekst på "Tilbage" knappen</li>
            </ul>
            
            <h3>Omdirigeringsindstillinger</h3>
            <p>Vælg hvor kunden sendes hen hvis de annullerer aldersverifikationen:</p>
            <ul>
                <li><strong>Indkøbskurv:</strong> Tilbage til kurv siden (anbefalet)</li>
                <li><strong>Forside:</strong> Til webshoppens forside</li>
                <li><strong>Brugerdefineret:</strong> Til en specifik side du vælger</li>
            </ul>
            
            <h2 id="troubleshooting">🔧 Fejlfinding</h2>
            
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
                    Popup'en matcher ikke mit design
                    <span>+</span>
                </div>
                <div class="faq-answer">
                    <p><strong>Tilpasning:</strong></p>
                    <ul>
                        <li>Gå til <em>IDguard → Design & Tekster</em></li>
                        <li>Brug farve-vælgerne til at matche dit brand</li>
                        <li>Tilpas tekster til dit tonefald</li>
                        <li>Brug live forhåndsvisningen til at se ændringer</li>
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
                <strong>⚠️ Vigtigt:</strong> IDguard håndterer kun den tekniske aldersverifikation. Det er webshoppens ansvar at sikre juridisk compliance med danske love om salg af aldersbegrænsede varer.
            </div>
            
            <h2>🔗 Nyttige links</h2>
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
        <h1>🛟 <?php _e('IDguard Kundeservice', 'idguard'); ?></h1>
        
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
                    <h3><?php _e('Kontakt information', 'idguard'); ?></h3>
                    
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
                        <strong><?php _e('Version:', 'idguard'); ?></strong> 2.1.1.62<br>
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
                <strong>💡 <?php _e('Tips til bedre support:', 'idguard'); ?></strong>
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
    <div class="options_group">
        <p class="form-field _age_limit_field">
            <label for="_age_limit"><?php _e('Aldersgrænse', 'idguard'); ?></label>
            <select id="_age_limit" name="_age_limit" class="select short">
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
    <tr class="form-field">
        <th scope="row" valign="top">
            <label for="age_limit"><?php _e('Aldersgrænse', 'idguard'); ?></label>
        </th>
        <td>
            <select name="age_limit" id="age_limit">
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
