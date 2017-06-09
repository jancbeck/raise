<?php
/**
 * Plugin Name: EAS Donation Processor
 * Plugin URI: https://github.com/ea-foundation/eas-donation-processor
 * Description: Process donations
 * Version: 0.6.1
 * Author: Naoki Peter
 * Author URI: http://0x1.ch
 * License: proprietary
 */

defined('ABSPATH') or die('No script kiddies please!');

// Set priority constant for email filters
define('EAS_PRIORITY', 12838790321);

// Asset version
define('EAS_ASSET_VERSION', '0.20');

// Load other files
require_once 'vendor/autoload.php';
require_once "_globals.php";
require_once "_options.php";
require_once "bitpay/EncryptedWPOptionStorage.php";
require_once "functions.php";
require_once "updates.php";
require_once "form.php";

// Check for new version of plugin
require 'plugin-update-checker/plugin-update-checker.php';
$className = PucFactory::getLatestClassVersion('PucGitHubChecker');
$myUpdateChecker = new $className(
    'https://github.com/ea-foundation/eas-donation-processor',
    __FILE__,
    'master'
);
$myUpdateChecker->setAccessToken('93a8387a061d14040a5932e12ef31d90a1be419a'); // read only

// Add short code for donation form
add_shortcode('donationForm','getDonationForm');

// Start session (needed for PayPal)
add_action('init', 'eas_start_session', 1);
function eas_start_session()
{
    if (!session_id()) {
        session_start();
    }
    if (!preg_match('/admin-ajax\.php/', $_SERVER['REQUEST_URI'])) {
        $_SESSION['eas-plugin-url'] = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    }
}

// Process donation (Bank Transfer and Stripe)
add_action("wp_ajax_nopriv_eas_donate", "eas_process_donation");
add_action("wp_ajax_eas_donate", "eas_process_donation");
function eas_process_donation()
{
    processDonation();
}

// Prepare redirect (PayPal, Skrill, GoCardless, BitPay)
add_action("wp_ajax_nopriv_eas_redirect", "eas_prepare_donation");
add_action("wp_ajax_eas_redirect", "eas_prepare_donation");
function eas_prepare_donation()
{
    prepareRedirect();
}

// Log Paypal transaction. User is redirected here after successful donation
add_action("wp_ajax_nopriv_log", "eas_process_paypal_log");
add_action("wp_ajax_log", "eas_process_paypal_log");
function eas_process_paypal_log()
{
    processPaypalLog();
}

// Process GoCardless donation
add_action("wp_ajax_nopriv_gocardless_debit", "eas_process_gocardless_debit");
add_action("wp_ajax_gocardless_debit", "eas_process_gocardless_debit");
function eas_process_gocardless_debit()
{
    processGoCardlessDonation();
}

// Log BitPay donation
add_action("wp_ajax_nopriv_bitpay_log", "eas_process_bitpay_log");
add_action("wp_ajax_bitpay_log", "eas_process_bitpay_log");
function eas_process_bitpay_log()
{
    processBitPayLog();
}

// Log Skrill donation
add_action("wp_ajax_nopriv_skrill_log", "eas_process_skrill_log");
add_action("wp_ajax_skrill_log", "eas_process_skrill_log");
function eas_process_skrill_log()
{
    processSkrillLog();
}

// Add translations
add_action('plugins_loaded', 'eas_load_textdomain');
function eas_load_textdomain()
{
    load_plugin_textdomain('eas-donation-processor', false, dirname(plugin_basename(__FILE__)) . '/lang/');
}

// Add JSON settings editor
add_action('admin_enqueue_scripts', 'eas_json_settings_editor');
function eas_json_settings_editor()
{
    wp_register_script('donation-admin', plugins_url('eas-donation-processor/js/admin.js'), array(), EAS_ASSET_VERSION);
    wp_enqueue_script('donation-admin');
    wp_register_script('donation-json-settings-editor', plugins_url('eas-donation-processor/js/jsoneditor.min.js'), array(), EAS_ASSET_VERSION);
    wp_enqueue_script('donation-json-settings-editor');
    wp_register_style('donation-json-settings-editor-css', plugins_url('eas-donation-processor/js/jsoneditor.min.css'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-json-settings-editor-css');
    wp_register_style('donation-admin-css', plugins_url('eas-donation-processor/css/admin.css'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-admin-css');
    wp_enqueue_media();
}

/*
 * Additional Styles 
 */
add_action('wp_enqueue_scripts', 'register_donation_styles');
function register_donation_styles()
{
    wp_register_style('bootstrap', '//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css');
    wp_enqueue_style('bootstrap');
    wp_register_style('donation-plugin-css', plugins_url('eas-donation-processor/css/form.css'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-plugin-css');
    wp_register_style('donation-combobox-css', plugins_url('eas-donation-processor/css/bootstrap-combobox.css'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-combobox-css');
    wp_register_style('donation-plugin-flags', plugins_url('eas-donation-processor/css/flags-few.css'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-plugin-flags');
    wp_register_style('donation-button-css', plugins_url('eas-donation-processor/css/button.css.php'), array(), EAS_ASSET_VERSION);
    wp_enqueue_style('donation-button-css');
}

/*
 * Additional Scripts  
 */
add_action('wp_enqueue_scripts', 'register_donation_scripts');
function register_donation_scripts()
{
    wp_register_script('donation-plugin-bootstrapjs', '//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js', array('jquery'));
    wp_register_script('donation-plugin-jqueryformjs', '//malsup.github.io/jquery.form.js', array('jquery'));
    wp_register_script('donation-plugin-stripe', '//checkout.stripe.com/checkout.js');
    wp_register_script('donation-plugin-paypal', '//www.paypalobjects.com/js/external/dg.js');
    wp_register_script('donation-combobox', plugins_url('eas-donation-processor/js/bootstrap-combobox.js'), array(), EAS_ASSET_VERSION);
    wp_register_script('donation-plugin-form', plugins_url('eas-donation-processor/js/form.js'), array('jquery', 'donation-plugin-stripe'), EAS_ASSET_VERSION);
}

// Register fundraiser post type
add_action('init', 'create_campaign_post_type');
function create_campaign_post_type()
{
    register_post_type('eas_fundraiser',
        array(
            'labels' => array(
                'name'          => __("Fundraisers", "eas-donation-processor"),
                'singular_name' => __("Fundraiser", "eas-donation-processor"),
                'add_new_item'  => __("Add New Fundraiser", "eas-donation-processor"),
                'edit_item'     => __("Edit Fundraiser", "eas-donation-processor"),
                'new_item'      => __("New Fundraiser", "eas-donation-processor"),
            ),
            'supports'            => array('title', 'author'),
            'public'              => true,
            'has_archive'         => true,
            'menu_icon'           => 'dashicons-lightbulb',
            'exclude_from_search' => 'true',
        )
    );
}

// Register matching campaign donation post type
add_action('init', 'create_doantion_post_type');
function create_doantion_post_type()
{
    register_post_type( 'eas_donation',
        array(
            'labels' => array(
                'name'          => __("Fundraiser Donations", "eas-donation-processor"),
                'singular_name' => __("Fundraiser Donation", "eas-donation-processor"),
                'add_new_item'  => __("Add New Donation", "eas-donation-processor"),
                'edit_item'     => __("Edit Donation", "eas-donation-processor"),
                'new_item'      => __("New Donation", "eas-donation-processor"),
            ),
            'supports'            => array('title', 'custom-fields'),
            'public'              => true,
            'has_archive'         => true,
            'menu_icon'           => 'dashicons-heart',
            'exclude_from_search' => 'true',
        )
    );
}

// Add settings link to plugins page
function plugin_add_settings_link($links)
{
    $settings_link = '<a href="options-general.php?page=eas-donation-settings">' . __( 'Settings' ) . '</a>';
    array_push( $links, $settings_link );
    return $links;
}
$plugin = plugin_basename(__FILE__ );
add_filter( "plugin_action_links_$plugin", 'plugin_add_settings_link' );

add_action('admin_footer', function() { 
    /*
    if possible try not to queue this all over the admin by adding your settings GET page val into next
    if( empty( $_GET['page'] ) || "my-settings-page" !== $_GET['page'] ) { return; }
    */
?>
<script>
    jQuery(document).ready(function($){
        var customUploader;
        var logo = $('.stripe-logo');
        var target  = $('.wrap input[name="logo"]');

        logo.click(function(e) {
            e.preventDefault();
            //If the uploader object has already been created, reopen the dialog
            if (customUploader) {
                customUploader.open();
                return;
            }

            //Extend the wp.media object
            customUploader = wp.media.frames.file_frame = wp.media({
                title: 'Choose Image',
                button: {
                    text: 'Choose Image'
                },
                multiple: false
            });

            //When a file is selected, grab the URL and set it as the text field's value
            customUploader.on('select', function() {
                attachment = customUploader.state().get('selection').first().toJSON();
                target.val(attachment.url);
                logo.css('backgroundImage', "url('" + attachment.url + "')");
            });

            //Open the uploader dialog
            customUploader.open();
        });      
    });
</script>
<?php
});

/**
 * Returns current plugin version
 *
 * @return string Plugin version
 */
function getPluginVersion() {
    if (!empty($GLOBALS['easPluginVersion'])) {
        return $GLOBALS['easPluginVersion'];
    }

    if (!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    // Set plugin version
    $pluginData                  = get_plugin_data(__FILE__, false, false);
    $GLOBALS['easPluginVersion'] = $pluginData['Version'];

    return $GLOBALS['easPluginVersion'];
}

/**
 * Register a tax deduction REST endpoint
 */
add_action('rest_api_init', function() {
    register_rest_route('eas-donation-processor/v1', '/tax-deduction/(?P<secret>\w+)', array(
        'methods'  => 'GET',
        'callback' => 'serveTaxDeductionSettings',
        'permission_callback' => function ($request) {
            // Check expose status
            if ('expose' != get_option('tax-deduction-expose')) {
                return new WP_Error('rest_forbidden', 'Tax deduction sharing is disabled', array('status' => 403));
            }

            // Check secret
            if ($request['secret'] != get_option('tax-deduction-secret')) {
                return new WP_Error('rest_bad_request', 'Invalid secret', array('status' => 400));
            }

            // Check form exists
            loadSettings();
            $form = get($_GET['form'], 'default');
            if (!isset($GLOBALS['easForms'][$form]['payment.labels']['tax_deduction'])) {
                return new WP_Error('rest_not_found', 'Form not found', array('status' => 404));
            }

            return true;
        }
    ));
});












