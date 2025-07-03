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
        if (function_exists('WC') && WC()->cart) {
            $max_age_limit = 0;
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $cart_item['product_id'];
                $product_age_limit = get_post_meta($product_id, '_age_limit', true);
                
                // Also check category age limits
                $product = wc_get_product($product_id);
                $category_ids = $product->get_category_ids();
                foreach ($category_ids as $category_id) {
                    $category_age_limit = get_term_meta($category_id, '_age_limit', true);
                    if (!empty($category_age_limit) && intval($category_age_limit) > $max_age_limit) {
                        $max_age_limit = intval($category_age_limit);
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
    woocommerce_wp_text_input(array(
        'id' => '_age_limit',
        'label' => __('Aldersgrænse', 'idguard'),
        'description' => __('Minimum alder for at købe dette produkt (f.eks. 18)', 'idguard'),
        'desc_tip' => true,
        'type' => 'number',
        'custom_attributes' => array(
            'min' => '0',
            'max' => '99',
            'step' => '1'
        )
    ));
}
add_action('woocommerce_product_options_general_product_data', 'idguard_add_product_age_limit_field');

// Save product age limit field
function idguard_save_product_age_limit_field($post_id) {
    $age_limit = $_POST['_age_limit'];
    if (!empty($age_limit)) {
        update_post_meta($post_id, '_age_limit', sanitize_text_field($age_limit));
    } else {
        delete_post_meta($post_id, '_age_limit');
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
            <input type="number" name="age_limit" id="age_limit" value="<?php echo esc_attr($age_limit); ?>" min="0" max="99" step="1" />
            <p class="description"><?php _e('Minimum alder for at købe produkter i denne kategori (f.eks. 18)', 'idguard'); ?></p>
        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'idguard_add_category_age_limit_field');

// Save category age limit field
function idguard_save_category_age_limit_field($term_id) {
    if (isset($_POST['age_limit'])) {
        $age_limit = sanitize_text_field($_POST['age_limit']);
        if (!empty($age_limit)) {
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
    if (isset($_POST['notice'])) {
        update_option('dismissed-' . sanitize_text_field($_POST['notice']), true);
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
                    notice: notice
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
    // Clear domain authorization cache
    $domain = parse_url(home_url(), PHP_URL_HOST);
    delete_transient('idguard_domain_auth_' . md5($domain));
}
register_deactivation_hook(__FILE__, 'idguard_deactivate');
