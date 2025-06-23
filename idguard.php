<?php
/**
 * Plugin Name: IDguard
 * Plugin URI: https://idguard.dk
 * Description: Foretag automatisk alderstjek med MitID ved betaling på WooCommerce-webshops
 * Version: 2.1.1
 * Author: IDguard
 * Author URI: https://idguard.dk
 * Text Domain: idguard
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
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
    $settings_link = '<a href="' . admin_url('admin.php?page=idguard') . '">' . __('⛨ Configure IDguard', 'idguard') . '</a>';
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
        echo '<p><strong>' . __('ID Token') . ':</strong> ' . esc_html($id_token) . '</p>';
    }
}

// Function to display the admin notice
function idguard_admin_notice() {
    if ( ! get_option('dismissed-idguard_notice', false) ) {
        ?>
        <div class="notice notice-success is-dismissible" data-notice="idguard_notice">
            <p><?php _e('🎉 Thank you for choosing IDguard! Please take a moment to review your settings to ensure everything is perfectly configured to your liking.', 'idguard'); ?></p>
            <p>
                <a href="<?php echo admin_url('options-general.php?page=idguard'); ?>" class="button button-primary">
                    <?php _e('Configure IDguard', 'idguard'); ?>
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
                    }
                });
            });
        });
    </script>
    <?php
});

// AJAX handler to store the state of dismissible notices
add_action('wp_ajax_dismissed_notice_handler', 'ajax_notice_handler');

function ajax_notice_handler() {
    $type = $_POST['type'];
    update_option('dismissed-' . $type, true);
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
    // Get the checkout URL from WooCommerce
    $checkout_url = wc_get_checkout_url();
	$cart_url = wc_get_cart_url();

    // Use plugins_url() to construct the correct path to the JavaScript file
    $script_url = plugins_url('idguard.js', __FILE__);

    // Register the script
    wp_enqueue_script('idguard-script', $script_url, [], '1.1.0', true);

    // Generate a nonce
    $nonce = wp_create_nonce('idguard_nonce');

    // Retrieve popup customization options
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
	
    // Pass PHP data to the JavaScript file
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

// Add a menu for the plugin
function idguard_add_admin_menu() {
    add_menu_page(
        __('General', 'idguard'), // Page title
        __('IDguard', 'idguard'), // Menu title
        'manage_options', // Capability
        'idguard', // Menu slug
        'idguard_settings_page', // Callback function for our settings page
        'dashicons-shield', // Icon
        100 // Position
    );
    
    add_submenu_page(
        'idguard',             // Parent slug
        'Popup',              // Page title
        'Popup',              // Menu title
        'manage_options',      // Capability
        'idguard_popup',       // Menu slug
        'idguard_popup_page'   // Callback function for our submenu Popup page
    );
    
    add_submenu_page(
        'idguard',             // Parent slug
        'Documentation',      // Page title
        'Documentation',      // Menu title
        'manage_options',      // Capability
        'idguard_documentation',// Menu slug
        'idguard_documentation_page' // Callback function for our submenu Documentation page
    );
    
    add_submenu_page(
        'idguard',             // Parent slug
        'Support',            // Page title
        'Support',            // Menu title
        'manage_options',      // Capability
        'idguard_support',     // Menu slug
        'idguard_support_page' // Callback function for our submenu Support page
    );
}
add_action('admin_menu', 'idguard_add_admin_menu');

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

    // Get existing texts
    $popup_title = get_option('idguard_popup_title', 'Din ordre indeholder aldersbegrænsede varer');
    $popup_message = get_option('idguard_popup_message', 'Den danske lovgivning kræver at vi kontrollerer din alder med MitID inden du kan købe aldersbegrænsede varer.');
    $popup_button_text = get_option('idguard_popup_button_text', 'Fortsæt købet');
    $popup_cancel_button_text = get_option('idguard_popup_cancel_button_text', 'Gå tilbage');
    ?>
    <div class="wrap">
        <h1><?php _e('IDguard Popup Settings', 'idguard'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('idguard_popup_settings');
            do_settings_sections('idguard_popup_settings');
            ?>

            <h2><?php _e('Popup Appearance', 'idguard'); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Text Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_text_color" value="<?php echo esc_attr($popup_text_color); ?>" class="my-color-field" data-default-color="#000000" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Background Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_background_color" value="<?php echo esc_attr($popup_background_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Verify Button Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_verify_button_color" value="<?php echo esc_attr($popup_verify_button_color); ?>" class="my-color-field" data-default-color="#004cb8" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Verify Button Text Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_verify_button_text_color" value="<?php echo esc_attr($popup_verify_button_text_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Cancel Button Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_cancel_button_color" value="<?php echo esc_attr($popup_cancel_button_color); ?>" class="my-color-field" data-default-color="#f44336" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Cancel Button Text Color', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_cancel_button_text_color" value="<?php echo esc_attr($popup_cancel_button_text_color); ?>" class="my-color-field" data-default-color="#ffffff" /></td>
                </tr>
            </table>

            <h2><?php _e('Popup Text Settings', 'idguard'); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Popup Title', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_title" value="<?php echo esc_attr($popup_title); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Popup Message', 'idguard'); ?></th>
                    <td><textarea name="idguard_popup_message" rows="5" cols="50"><?php echo esc_textarea($popup_message); ?></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Verify Button Text', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_button_text" value="<?php echo esc_attr($popup_button_text); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Cancel Button Text', 'idguard'); ?></th>
                    <td><input type="text" name="idguard_popup_cancel_button_text" value="<?php echo esc_attr($popup_cancel_button_text); ?>" /></td>
                </tr>
            </table>
			
			<h2><?php _e('Cancel Button Redirect Settings', 'idguard'); ?></h2>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e('Redirect to', 'idguard'); ?></th>
					<td>
						<select name="idguard_cancel_redirect_option" id="idguard_cancel_redirect_option">
							<option value="home" <?php selected($cancel_redirect_option, 'home'); ?>><?php _e('Homepage', 'idguard'); ?></option>
							<option value="cart" <?php selected($cancel_redirect_option, 'cart'); ?>><?php _e('Cart', 'idguard'); ?></option>
							<option value="custom" <?php selected($cancel_redirect_option, 'custom'); ?>><?php _e('Custom URL', 'idguard'); ?></option>
						</select>
					</td>
				</tr>
				<tr valign="top" id="custom_url_row" style="<?php echo ($cancel_redirect_option === 'custom') ? '' : 'display: none;'; ?>">
					<th scope="row"><?php _e('Custom URL', 'idguard'); ?></th>
					<td>
						<input type="text" name="idguard_custom_cancel_url" value="<?php echo esc_attr($custom_cancel_url); ?>" />
						<p class="description"><?php _e('Enter a relative URL (e.g., /shop or /contact) or an absolute URL (e.g., https://example.com/shop)', 'idguard'); ?></p>
					</td>
				</tr>
			</table>

			<script>
			jQuery(document).ready(function($) {
				$('#idguard_cancel_redirect_option').change(function() {
					if ($(this).val() === 'custom') {
						$('#custom_url_row').show();
					} else {
						$('#custom_url_row').hide();
					}
				});
			});
			</script>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function idguard_documentation_page() {
    echo '<div class="idguard-documentation">';
    echo '<h1>' . __('Documentation', 'idguard') . '</h1>';
    
    // Introduction
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Introduction', 'idguard') . '</h2>';
    echo '<p>' . __('Welcome to the IDguard WooCommerce Plugin documentation! This plugin streamlines the age verification process for your WooCommerce store, ensuring compliance with legal requirements while providing a seamless shopping experience for your customers.', 'idguard') . '</p>';
    echo '</section>';
    
    // Installation
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Installation', 'idguard') . '</h2>';
    echo '<h3>' . __('Requirements', 'idguard') . '</h3>';
    echo '<ul class="idguard-requirements">
            <li>' . __('WordPress version 5.0 or higher', 'idguard') . '</li>
            <li>' . __('WooCommerce version 4.0 or higher', 'idguard') . '</li>
          </ul>';
    echo '</section>';
    
    // Age Verification Process
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Age Verification Process', 'idguard') . '</h2>';
    echo '<p>' . __('When a customer attempts to purchase age-restricted products, the following process occurs:', 'idguard') . '</p>';
    echo '<ol class="idguard-process">
            <li>' . __('The age verification popup is triggered.', 'idguard') . '</li>
            <li>' . __('Users are required to confirm their age via MitID.', 'idguard') . '</li>
            <li>' . __('Based on the age MitID sends back, they can proceed with their purchase.', 'idguard') . '</li>
          </ol>';
    echo '</section>';
    
    // Styling Options
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Styling Options', 'idguard') . '</h2>';
    echo '<p>' . __('Customize the appearance of the age verification popup to match your store’s branding through the settings.', 'idguard') . '</p>';
    echo '</section>';
    
    // Troubleshooting
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Troubleshooting', 'idguard') . '</h2>';
    echo '<p>' . __('If you encounter issues, contact support.', 'idguard') . '</p>';
    echo '</section>';
    
    // Support
    echo '<section class="idguard-section">';
    echo '<h2>' . __('Support', 'idguard') . '</h2>';
    echo '<p>' . __('For further assistance, please contact us via <a href="mailto:kontakt@idguard.dk">kontakt@idguard.dk</a>.', 'idguard') . '</p>';
    echo '</section>';
    
    echo '</div>'; // End of idguard-documentation
}

// Added CSS styles in the admin area for better presentation
add_action('admin_head', function() {
    echo '<style>
        .idguard-documentation { font-family: Arial, sans-serif; padding: 20px; }
        .idguard-section { margin-bottom: 20px; border: 1px solid #ccc; border-radius: 5px; padding: 10px; }
        .idguard-requirements, .idguard-settings { margin-left: 20px; }
        .idguard-process { margin-left: 20px; list-style-type: decimal; }
        h1 { color: #333; }
        h2 { color: #555; }
        h3 { color: #777; }
        p { line-height: 1.6; }
        a { color: #0073aa; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>';
});

function idguard_support_page() {
    ?>
    <style>
        .support-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
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
    </style>

    <div class="wrap">
        <h1><?php _e('Support', 'idguard'); ?></h1>
        <div class="support-section">
            <div class="support-title"><?php _e('Need Help?', 'idguard'); ?></div>
            <p><?php _e('We are here to help you! Please check the following resources for assistance:', 'idguard'); ?></p>
            <a href="<?php echo admin_url('admin.php?page=idguard_documentation'); ?>" class="support-link">
                <?php _e('📖 Documentation', 'idguard'); ?>
            </a>
            <a href="<?php echo 'https://idguard.dk'; ?>" class="support-link">
                <?php _e('❓ Frequently Asked Questions (FAQ)', 'idguard'); ?>
            </a>
            <a href="mailto:kontakt@arpecompany.dk" class="support-link" target="_blank">
                <?php _e('📬 Contact Us (kontakt@arpecompany.dk)', 'idguard'); ?>
            </a>
        </div>
    </div>
    <?php
}

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
        'label' => __('Age Limit', 'idguard'),
        'options' => array(
            '' => __('No age limit set', 'idguard'),
            '15' => '15+',
            '16' => '16+',
            '18' => '18+',
            '21' => '21+',
        ),
        'desc_tip' => true,
        'description' => __('Select the age limit for this product.', 'idguard'),
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
            'label' => __('Age Limit', 'idguard'),
            'options' => array(
                '' => __('No age limit set', 'idguard'),
                '15' => '15+',
                '16' => '16+',
                '18' => '18+',
                '21' => '21+',
            ),
            'desc_tip' => true,
            'description' => __('Select the age limit for this category.', 'idguard'),
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
        <th scope="row" valign="top"><label for="idguard_age_limit_category"><?php _e('Age Limit', 'idguard'); ?></label></th>
        <td>
            <?php
            woocommerce_wp_select(array(
                'id' => 'idguard_age_limit_category',
                'label' => '', // Retained to avoid undefined array key error
                'options' => array(
                    '' => __('No age limit set', 'idguard'),
                    '15' => '15+',
                    '16' => '16+',
                    '18' => '18+',
                    '21' => '21+',
                ),
                'selected' => true,
                'value' => $age_limit,
                'desc_tip' => true,
                'description' => __('Select the age limit for this category?', 'idguard'), // Added a question mark here
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
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }
        .instruction-box:hover {
            background-color: #f9f9f9;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1);
        }
        .instruction-box.selected {
            background-color: #e6f7ff;
            border-color: #0091ea;
            box-shadow: 0px 4px 8px rgba(0, 145, 234, 0.2);
        }
        .instruction-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .age-limit {
            display: none; /* Hide, just initially */
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

                    // Show or hide age limit selection based on global selection
                    if (box.dataset.value === 'global') {
                        document.querySelector('.age-limit').style.display = 'block';
                    } else {
                        document.querySelector('.age-limit').style.display = 'none';
                    }
                });
            });

            const currentSetting = hiddenInput.value;
            document.querySelector(`.instruction-box[data-value="${currentSetting}"]`)?.classList.add('selected');

            // Initialize age limit visibility
            if (currentSetting === 'global') {
                document.querySelector('.age-limit').style.display = 'block';
            }
        });
    })();
    </script>

    <div class="wrap">
        <h1><?php _e('IDguard Settings', 'idguard'); ?></h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('idguard_general_settings');
                do_settings_sections('idguard_general_settings');
            ?>
            <h2><?php _e('Select Age Verification Mode', 'idguard'); ?></h2>
            <div class="instruction-box" data-value="off">
                <div class="instruction-title"><?php _e('Off', 'idguard'); ?></div>
                <div><?php _e('No age verification will be required.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="global">
                <div class="instruction-title"><?php _e('Global', 'idguard'); ?></div>
                <div><?php _e('Age verification will be required for all products.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="category">
                <div class="instruction-title"><?php _e('Category Specific', 'idguard'); ?></div>
                <div><?php _e('Age verification will be required for products in specific categories.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="product">
                <div class="instruction-title"><?php _e('Product Specific', 'idguard'); ?></div>
                <div><?php _e('Age verification will be required for specific products only.', 'idguard'); ?></div>
            </div>
            <div class="instruction-box" data-value="category_and_product">
                <div class="instruction-title"><?php _e('Category and Product Specific', 'idguard'); ?></div>
                <div><?php _e('Age verification will be required based on categories and/or individual products. Categories have the highest priority, if activated.', 'idguard'); ?></div>
            </div>

            <input type="hidden" name="idguard_age_verification_mode" id="idguard_age_verification_mode" value="<?php echo esc_attr(get_option('idguard_age_verification_mode', 'off')); ?>">

            <div class="age-limit">
                <h3><?php _e('Select Age Limit for Global Setting', 'idguard'); ?></h3>
                <select name="idguard_global_age_limit" id="idguard_global_age_limit">
                    <option value="15" <?php selected(get_option('idguard_global_age_limit'), '15'); ?>>15+</option>
                    <option value="16" <?php selected(get_option('idguard_global_age_limit'), '16'); ?>>16+</option>
                    <option value="18" <?php selected(get_option('idguard_global_age_limit'), '18'); ?>>18+</option>
                    <option value="21" <?php selected(get_option('idguard_global_age_limit'), '21'); ?>>21+</option>
                </select>
                <p><?php _e('This age limit will apply globally when age verification is enabled.', 'idguard'); ?></p>
            </div>

            <?php submit_button(); ?>
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
