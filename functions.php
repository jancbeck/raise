<?php if (!defined('ABSPATH')) exit;

/**
 * Initialize form and return form settings
 *
 * @param string $form Form name
 * @param string $mode sandbox/live
 * @return array
 */
function eas_init_donation_form($form, $mode)
{
    // Update settings
    eas_update_settings();

    // Load settings
    $formSettings = eas_load_settings($form);

    // Load logo
    $logo = get_option('logo', plugin_dir_url(__FILE__) . 'images/logo.png');
    
    // Make amount patterns
    $amountPatterns      = array();
    $currencies          = eas_get($formSettings['amount']['currency'], array());
    foreach ($currencies as $currency => $currencySettings) {
        $amountPatterns[strtoupper($currency)] = eas_get($currencySettings['pattern'], '%amount%');
    }

    // Get enabled payment providers
    $enabledProviders = eas_enabled_payment_providers($formSettings, $mode);

    // Get Stripe public keys
    $stripeKeys = in_array('stripe', $enabledProviders) ? eas_get_stripe_public_keys($formSettings, $mode) : array();

    // Get tax deduction labels
    $taxDeductionLabels = eas_load_tax_deduction_settings($form);

    // Get bank accounts and localize their labels
    $bankAccounts = array_map('eas_localize_array_keys', eas_get($formSettings['payment']['provider']['banktransfer']['accounts'], array()));

    // Localize script
    wp_localize_script('donation-plugin-form', 'wordpress_vars', array(
        'logo'                  => $logo,
        'ajax_endpoint'         => admin_url('admin-ajax.php'),
        'amount_patterns'       => $amountPatterns,
        'stripe_public_keys'    => $stripeKeys,
        'tax_deduction_labels'  => $taxDeductionLabels,
        'bank_accounts'         => $bankAccounts,
        'organization'          => $GLOBALS['easOrganization'],
        'currency2country'      => $GLOBALS['currency2country'],
        'donate_button_once'    => __("Donate %currency-amount%", "eas-donation-processor"),
        'donate_button_monthly' => __("Donate %currency-amount% per month", "eas-donation-processor"),
        'donation'              => __("Donation", "eas-donation-processor"),
    ));

    // Enqueue previously registered scripts and styles (to prevent them loading on every page load)
    wp_enqueue_script('donation-plugin-bootstrapjs');
    wp_enqueue_script('donation-plugin-jqueryformjs');
    if (in_array('stripe', $enabledProviders)) {
        wp_enqueue_script('donation-plugin-stripe');
    }
    if (in_array('paypal', $enabledProviders)) {
        wp_enqueue_script('donation-plugin-paypal');
    }
    wp_enqueue_script('donation-plugin-form');
    wp_enqueue_script('donation-combobox');

    return $formSettings;
}

/**
 * Load form settings
 *
 * @param string $form Form name
 * @return array
 * @throws \Exception
 */
function eas_load_settings($form)
{
    // Checked if loaded already
    if (isset($GLOBALS['easForms'][$form])) {
        return $GLOBALS['easForms'][$form];
    }

    // Load parameters
    $easSettings = json_decode(get_option('settings'), true);

    // Check if config plugin is around
    $externalSettings = array();
    if (function_exists('eas_donation_processor_config')) {
        if ($externalSettings = eas_donation_processor_config()) {
            // Merge
            $easSettings = eas_array_replace_recursive($externalSettings, $easSettings);
        } else {
            throw new \Exception("Syntax error in config plugin JSON");
        }
    }

    // Load organization in current language
    $organization = !empty($easSettings['organization']) ? (eas_get_localized_value($easSettings['organization']) ?: '') : '';

    // Resolve form inheritance
    $formSettings = eas_rec_load_settings($form, $easSettings['forms']);

    // Remove inherits property
    unset($formSettings['inherits']);

    // Add organization and form settings to GLOBALS
    $GLOBALS['easOrganization'] = $organization;
    $GLOBALS['easForms']        = array($form => $formSettings);

    return $formSettings;
}

/**
 * Internal: Resolve form inheritance
 *
 * @param string $form
 * @param array $formsSettings
 * @param array $childForms To avoid circular inheritance
 * @return array
 * @throws \Exception
 */
function eas_rec_load_settings($form, $formsSettings, $childForms = array())
{
    if (in_array($form, $childForms)) {
        throw new \Exception("Circular form definition. See Settings > Donation Plugin");
    }

    if (!isset($formsSettings[$form])) {
        throw new \Exception("No settings found for form '$form'. See Settings > Donation Plugin");
    }

    if (!($parentForm = eas_get($formsSettings[$form]['inherits']))) {
        return $formsSettings[$form];
    }

    // Recurse and merge
    $childForms[]       = $form;
    $parentFormSettings = eas_rec_load_settings($parentForm, $formsSettings, $childForms);
    return eas_array_replace_recursive($parentFormSettings, $formsSettings[$form]);
}

/**
 * Return list of enabled providers
 *
 * @param array  $formSettings
 * @param string $mode
 * @return array
 */
function eas_enabled_payment_providers($formSettings, $mode)
{
    // Get provider settings
    $providerSettings = eas_get($formSettings['payment']['provider'], array());

    // Extract default settings (always needed)
    $providers = array_keys(array_filter($providerSettings, function ($settings, $provider) use ($mode) {
        return strpos($provider, '_') === false &&
               is_array($settings) &&
               eas_payment_provider_settings_complete($provider, eas_get($settings[$mode], array()));
    }, ARRAY_FILTER_USE_BOTH));

    return $providers;
}

/**
 * Are properties complete?
 *
 * @param string $provider
 * @param array $properties
 * @param bool
 */
function eas_payment_provider_settings_complete($provider, array $properties)
{
    $requiredProperties = eas_get_payment_provider_properties($provider);
    return array_reduce($requiredProperties, function ($carry, $item) use ($properties) {
        return $carry && !empty($properties[$item]);
    }, true);
}

/**
 * Print paymnet provider HTML
 *
 * @param array  $formSettings
 * @param string $mode
 * @return string
 */
function eas_print_payment_providers($formSettings, $mode)
{
    // Get enabled providers
    $providers = eas_enabled_payment_providers($formSettings, $mode);
    $checked   = true;
    $result    = '';
    foreach ($providers as $provider) {
        switch ($provider) {
            case 'stripe':
                $value  = 'Stripe';
                $text   = '<span class="payment-method-name sr-only">' . __('credit card', 'eas-donation-processor') . '</span>';
                $images = array(
                    array(
                        'path' => plugins_url('images/visa.png', __FILE__),
                        'alt'  => 'Visa',
                    ),
                    array(
                        'path' => plugins_url('images/mastercard.png', __FILE__),
                        'alt'  => 'Mastercard',
                    ),
                    array(
                        'path' => plugins_url('images/americanexpress.png', __FILE__),
                        'alt'  => 'American Express',
                    ),
                );
                break;
            case 'paypal':
                $value  = 'PayPal';
                $text   = '<span class="payment-method-name sr-only">PayPal</span>';
                $images = array(
                    array(
                        'path' => plugins_url('images/paypal.png', __FILE__),
                        'alt'  => 'PayPal',
                    ),
                );
                break;
            case 'bitpay':
                $value  = 'BitPay';
                $text   = '<span class="payment-method-name sr-only">Bitcoin</span>';
                $images = array(
                    array(
                        'path'  => plugins_url('images/bitcoin.png', __FILE__),
                        'alt'   => 'Bitcoin',
                        'width' => 23,
                    ),
                );
                break;
            case 'skrill':
                $value  = 'Skrill';
                $text   = '<span class="payment-method-name sr-only">Skrill</span>';
                $images = array(
                    array(
                        'path'  => plugins_url('images/skrill.png', __FILE__),
                        'alt'   => 'Skrill',
                    ),
                );
                break;
            case 'gocardless':
                $value  = 'GoCardless';
                $text   = '<a href="#" onClick="jQuery(\'#payment-gocardless\').click(); return false" data-toggle="tooltip" data-container="body" data-placement="top" title="' . __('Available for Eurozone, UK, and Sweden', 'eas-donation-processor') . '" style="text-decoration: none; color: inherit;"><span class="payment-method-name">' . __('direct debit', 'eas-donation-processor') . '</span></a>';
                $images = array();
                break;
            case 'banktransfer':
                $value  = 'Bank Transfer';
                $text   = '<span class="payment-method-name">' . __('bank transfer', 'eas-donation-processor') . '</span>';
                $images = array();
                break;
            default:
                // Do nothing
        }

        // Print radio box
        $id          = str_replace(' ', '', strtolower($value));
        $checkedAttr = $checked ? 'checked' : '';
        $checked     = false;
        $result .= '<label for="payment-' . $id . '" class="radio-inline">';
        $result .= '<input type="radio" name="payment" value="' . $value . '" id="payment-' . $id . '" ' . $checkedAttr . '> ';
        foreach ($images as $image) {
            $width  = eas_get($image['width'], 38);
            $height = eas_get($image['height'], 23);
            $result .= '<img src="' . $image['path'] . '" alt="' . $image['alt'] . '" width="' . $width . '" height="' . $height . '"> ';
        }
        $result .= $text;
        $result .= '</label>' . "\n";
    }

    return $result;
}

/**
 * Get donation from $_POST
 *
 * @return array
 * @throws \Exception
 */
function eas_get_donation_from_post()
{
    // Trim the data
    $post = array_map('trim', $_POST);

    // Replace amount-other
    if (!empty($post['amount_other'])) {
        $post['amount'] = $post['amount_other'];
    }
    unset($post['amount_other']);

    // Convert amount to cents
    if (is_numeric($post['amount'])) {
        $post['amountInt'] = (int)($post['amount'] * 100);
        $post['amount']    = money_format('%i', $post['amountInt'] / 100);
    } else {
        throw new \Exception('Invalid amount.');
    }

    return array(
        'form'        => $post['form'],
        'mode'        => $post['mode'],
        'url'         => $_SERVER['HTTP_REFERER'],
        'language'    => $post['language'],
        'time'        => date('c'),
        'currency'    => $post['currency'],
        'amount'      => $post['amount'],
        'frequency'   => $post['frequency'],
        'type'        => $post['payment'],
        'purpose'     => eas_get($post['purpose'], ''),
        'email'       => $post['email'],
        'name'        => $post['name'],
        'address'     => eas_get($post['address'], ''),
        'zip'         => eas_get($post['zip'], ''),
        'city'        => eas_get($post['city'], ''),
        'country'     => eas_get($post['country'], ''),
        'comment'     => eas_get($post['comment'], ''),
        'account'     => eas_get($post['account'], ''),
        'anonymous'   => (bool) eas_get($post['anonymous'], false),
        'mailinglist' => (bool) eas_get($post['mailinglist'], false),
        'tax_receipt' => (bool) eas_get($post['tax_receipt'], false),
    );

    /*$donation           = new Donation($post);
    $donation->time     = date('c');
    $donation->url      = $_SERVER['HTTP_REFERER'];
    $donation->language = strtoupper($donation->language);

    return $donation;*/
}

/**
 * AJAX endpoint that creates redirect response (PayPal, Skrill, GoCardless, BitPay)
 *
 * @return string JSON response
 */
function eas_prepare_redirect()
{
    try {
        // Trim the data
        $post = array_map('trim', $_POST);

        // Replace amount_other
        if (!empty($post['amount_other'])) {
            $post['amount'] = $post['amount_other'];
        }
        unset($post['amount_other']);

        // Output
        switch ($post['payment']) {
            case "PayPal":
                $response = eas_prepare_paypal_donation($post);
                break;
            case "Skrill":
                $response = eas_prepare_skrill_donation($post);
                break;
            case "GoCardless":
                $response = eas_prepare_gocardless_donation($post);
                break;
            case "BitPay":
                $response = eas_prepare_bitpay_donation($post);
                break;
            default:
                throw new \Exception('Payment method ' . $post['payment'] . ' is invalid');
        }

        // Return response
        die(json_encode($response));
    } catch (\Exception $e) {
        die(json_encode(array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $e->getMessage() . "). Please contact us.",
        )));
    }
}

/**
 * AJAX endpoint that deals with submitted donation data (Bank Tarnsfer and Stripe)
 *
 * @return string JSON response
 */
function eas_process_donation()
{
    try {
        // Get donation
        $donation = eas_get_donation_from_post();

        // Output
        if ($donation['type'] == "Stripe") {
            // Make sure we have the Stripe token
            if (empty($_POST['stripeToken']) || empty($_POST['stripePublicKey'])) {
                throw new \Exception("No Stripe token sent");
            }

            // Handle payment
            eas_handle_stripe_payment($donation, $_POST['stripeToken'], $_POST['stripePublicKey']);

            // Prepare response
            $response = array('success' => true);
        } else if ($donation['type'] == "Bank Transfer") {
            // Check honey pot (confirm email)
            eas_check_honey_pot($_POST);

            // Handle payment
            $reference = eas_handle_banktransfer_payment($donation);

            // Prepare response
            $response = array(
                'success'   => true,
                'reference' => $reference,
            );
        } else {
            throw new \Exception('Payment method is invalid');
        }

        die(json_encode($response));
    } catch (\Exception $e) {
        die(json_encode(array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $e->getMessage() . "). Please contact us.",
        )));
    }
}

/**
 * Process Stripe payment
 *
 * @param array $donation Donation data from donation form
 * @param string $token
 * @param string $publicKey
 * @throws \Exception On error from Stripe API
 */
function eas_handle_stripe_payment($donation, $token, $publicKey)
{
    // Create the charge on Stripe's servers - this will charge the user's card
    try {
        // Get Stripe settings
        $formSettings = eas_load_settings($donation['form']);
        $settings     = eas_get_best_payment_provider_settings(
            $formSettings,
            'stripe',
            $donation['mode'],
            $donation['tax_receipt'],
            $donation['currency'],
            eas_get($donation['country'])
        );

        if ($settings['public_key'] != $publicKey) {
            throw new \Exception("Key mismatch");
        }

        // Load secret key
        \Stripe\Stripe::setApiKey($settings['secret_key']);

        // Make customer
        $customer = \Stripe\Customer::create(array(
            'source'      => $token,
            'email'       => $donation['email'],
            'description' => $donation['name'],
        ));

        // Make charge/subscription
        $amountInt = (int)($donation['amount'] * 100);
        if ($donation['frequency'] == 'monthly') {
            // Get plan
            $plan = eas_get_stripe_plan($amountInt, $donation['currency']);

            // Subscribe customer to plan
            $subscription = \Stripe\Subscription::create(array(
                'customer' => $customer->id,
                'plan'     => $plan,
                'metadata' => array(
                    'url'     => $_SERVER['HTTP_REFERER'],
                    'purpose' => $donation['purpose'],
                ),
            ));

            // Add vendor reference ID
            $donation['vendor_subscription_id'] = $subscription->id;
        } else {
            // Make one-time charge
            $charge = \Stripe\Charge::create(array(
                'customer'    => $customer->id,
                'amount'      => $amountInt, // !!! in cents !!!
                'currency'    => $donation['currency'],
                'description' => 'Donation from ' . $donation['name'],
                'metadata'    => array(
                    'url'     => $_SERVER['HTTP_REFERER'],
                    'purpose' => $donation['purpose'],
                ),
            ));

            // Add vendor transaction ID
            $donation['vendor_transaction_id'] = $charge->id;
        }

        // Add customer ID
        $donation['vendor_customer_id'] = $customer->id;

        // Do post donation actions
        eas_do_post_donation_actions($donation);
    } catch (\Stripe\Error\InvalidRequest $e) {
        // The card has been declined
        throw new \Exception($e->getMessage() . " " . $e->getStripeParam()); // . " : $form : $mode : $email : $amount : $currency");
    } catch (\Exception $e) {
        throw new \Exception($e->getMessage()); // . " : $form : $mode : $email : $amount : $currency");
    }
}

/**
 * Get monthly Stripe plan
 *
 * @param int $amount Plan amount in cents
 * @param int $currency Plan currency
 * @return array
 */
function eas_get_stripe_plan($amount, $currency)
{
    $planId = 'donation-month-' . $currency . '-' . money_format('%i', $amount / 100);

    try {
        // Try fetching an existing plan
        $plan = \Stripe\Plan::retrieve($planId);
    } catch (\Exception $e) {
        // Create a new plan
        $params = array(
            'amount'   => $amount,
            'interval' => 'month',
            'name'     => 'Monthly donation of ' . $currency . ' ' . money_format('%i', $amount / 100),
            'currency' => $currency,
            'id'       => $planId,
        );

        $plan = \Stripe\Plan::create($params);

        if (!$plan instanceof \Stripe\Plan) {
            throw new \Exception('Credit card API is down. Please try later.');
        }

        $plan->save();
    }

    return $plan->id;
}

/**
 * Get Donation from form data
 *
 * @param Eas\Donation $donation
 * @return Eas\Donation
 */
function eas_bind_form_data(Eas\Donation $donation)
{
    //TODO
}

/**
 * Process bank transfer payment (simply log it)
 *
 * @param array $donation Donation form data
 * @return string Reference number
 */
function eas_handle_banktransfer_payment(array $donation)
{
    // Generate reference number and add to donation
    $reference             = eas_get_banktransfer_reference($donation['form'], eas_get($donation['purpose']));
    $donation['reference'] = $reference;

    // Do post donation actions
    eas_do_post_donation_actions($donation);

    return $reference;
}

/**
 * Trigger webhooks (logging and mailing list)
 *
 * @param array $donation
 */
function eas_trigger_webhooks(array $donation)
{
    // Logging
    eas_trigger_logging_webhooks($donation);

    // Mailing list
    if ($donation['mailinglist'] == 'yes') {
        eas_trigger_mailinglist_webhooks($donation);
    }
}

/**
 * Send logging web hooks
 *
 * @param array $donation Donation data for logging
 */
function eas_trigger_logging_webhooks($donation)
{
    // Get form and mode
    $form = eas_get($donation['form'], '');
    $mode = eas_get($donation['mode'], '');

    // Trigger hooks for Zapier
    $formSettings = eas_load_settings($form);
    if (isset($formSettings['webhook']['logging'][$mode])) {
        $hooks = eas_csv_to_array($formSettings['webhook']['logging'][$mode]);
        foreach ($hooks as $hook) {
            //TODO The array construct here is HookPress legacy. Remove in next major release.
            eas_send_webhook($hook, array('donation' => $donation));
        }
    }
}

/**
 * Remove unncecessary field from webhook data
 *
 * @param array $donation
 * @return array
 */
function eas_clean_up_donation_data(array $donation)
{
    // Unset reqId and bank_account_formatted (not needed)
    unset($donation['reqId']);
    unset($donation['bank_account_formatted']);

    // Transform boolean values to yes/no string
    $donation = array_map(function($val) {
        return is_bool($val) ? ($val ? 'yes' : 'no') : $val;
    }, $donation);

    // Translate country code to English
    if (!empty($donation['country'])) {
        $donation['country_code'] = $donation['country'];
        $donation['country']      = eas_get_english_name_by_country_code($donation['country']);
    }

    return array_filter($donation);
}

/**
 * Send mailing_list web hooks
 *
 * @param array $donation Donation data
 */
function eas_trigger_mailinglist_webhooks($donation)
{
    // Get form and mode
    $form = eas_get($donation['form'], '');
    $mode = eas_get($donation['mode'], '');

    // Trigger hooks for Zapier
    $formSettings = eas_load_settings($form);
    if (isset($formSettings['webhook']['mailing_list'][$mode])) {
        // Get subscription data
        $subscription = array(
            'form'     => $donation['form'],
            'mode'     => $donation['mode'],
            'email'    => $donation['email'],
            'name'     => $donation['name'],
            'language' => $donation['language'],
        );

        // Iterate over hooks
        $hooks = eas_csv_to_array($formSettings['webhook']['mailing_list'][$mode]);
        foreach ($hooks as $hook) {
            //TODO The array construct here is HookPress legacy. Remove in next major release.
            eas_send_webhook($hook, array('subscription' => $subscription));
        }
    }
}

/**
 * Send webhook
 *
 * @param string $url Target URL
 * @param array  $params Arguments
 */
function eas_send_webhook($url, array $params)
{
    global $wp_version;

    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
        return;
    }

    $version   = eas_get_plugin_version();
    $userAgent = "EAS-Donation-Processor/{$version} (compatible; WordPress {$wp_version}; +https://ea-foundation.org/)";
    $args      = array(
        'user-agent' => $userAgent,
        'body'       => $params,
        'referer'    => get_bloginfo('url'),
    );
    
    wp_remote_post($url, $args);
}

/**
 * Get GoCardless client
 *
 * @param string $form Form name
 * @param string $mode Form mode (live/sandbox)
 * @param bool   $taxReceiptNeeded
 * @param string $currency
 * @param string $country
 * @return \GoCardlessPro\Client
 */
function eas_get_gocardless_client($form, $mode, $taxReceiptNeeded, $currency, $country)
{
    // Get access token
    $formSettings = eas_load_settings($form);
    $settings     = eas_get_best_payment_provider_settings(
        $formSettings,
        "gocardless",
        $mode,
        $taxReceiptNeeded,
        $currency,
        $country
    );

    return new \GoCardlessPro\Client([
        'access_token' => $settings['access_token'],
        'environment'  => $mode == 'live' ? \GoCardlessPro\Environment::LIVE : \GoCardlessPro\Environment::SANDBOX,
    ]);
}

/**
 * Get best payment settings for the donor
 *
 * @param array  $formSettings
 * @param string $provider
 * @param string $mode
 * @param bool   $taxReceiptNeeded
 * @param string $currency
 * @param string $country
 * @return array
 * @throws \Exception
 */
function eas_get_best_payment_provider_settings(
    $formSettings,
    $provider,
    $mode,
    $taxReceiptNeeded,
    $currency,
    $country
) {
    // Make things lowercase
    $provider = strtolower($provider);
    $currency = strtolower($currency);
    $country  = strtolower($country);

    // Extract settings of the form we're talking about
    $countryCompulsory = eas_get($formSettings['payment']['extra_fields']['country'], false);

    // Check all possible settings
    $providers = $formSettings['payment']['provider'];
    if (empty($providers[$provider][$mode])) {
        throw new \Exception("No default settings found for $provider in $mode mode");
    }
    $hasCountrySetting  = eas_payment_provider_settings_complete($provider, eas_get($providers[$provider . '_' . $country][$mode], array()));
    $hasCurrencySetting = eas_payment_provider_settings_complete($provider, eas_get($providers[$provider . '_' . $currency][$mode], array()));
    $hasDefaultSetting  = eas_payment_provider_settings_complete($provider, eas_get($providers[$provider][$mode], array()));

    // Check if there are settings for a country where the chosen currency is used.
    // This is only relevant if the donor does not need a donation receipt (always related
    // to specific country) and if there are no currency specific settings
    $hasCountryOfCurrencySetting = false;
    $countryOfCurrency           = '';
    if (!$countryCompulsory && !$taxReceiptNeeded && !$hasCurrencySetting) {
        $countries = array_map('strtolower', eas_get_countries_by_currency($currency));
        foreach ($countries as $coc) {
            if (isset($providers[$provider . '_' . $coc][$mode])) {
                // Make sure we have all the properties
                $hasCountryOfCurrencySetting = eas_payment_provider_settings_complete($provider, eas_get($providers[$provider . '_' . $coc][$mode], array()));

                // If so, stop
                if ($hasCountryOfCurrencySetting) {
                    $countryOfCurrency = $coc;
                    break;
                }
            }
        }
    }

    if ($hasCountrySetting && ($taxReceiptNeeded || $countryCompulsory)) {
        // Use country specific settings
        return $providers[$provider . '_' . $country][$mode];
    } else if ($hasCurrencySetting) {
        // Use currency specific settings
        return $providers[$provider . '_' . $currency][$mode];
    } else if ($hasCountryOfCurrencySetting) {
        // Use settings of a country where the chosen currency is used
        return $providers[$provider . '_' . $countryOfCurrency][$mode];
    } else if ($hasDefaultSetting) {
        // Use default settings
        return $providers[$provider][$mode];
    } else {
        $requiredProperties = eas_get_payment_provider_properties($provider);
        $advice             = $requiredProperties ? " Required properties: " . implode(', ', $requiredProperties) : "";

        throw new \Exception("No valid settings found for $provider." . $advice);
    }
}

/**
 * Get payment provider settings properties
 *
 * @param string $provider
 * @return array
 */
function eas_get_payment_provider_properties($provider)
{
    switch (strtolower($provider)) {
        case "stripe":
            return array("secret_key", "public_key");
        case "paypal":
            return array("client_id", "client_secret");
        case "gocardless":
            return array("access_token");
        case "bitpay":
            return array("pairing_code");
        case "skrill":
            return array("merchant_account");
        default:
            return array();
    }
}

/**
 * AJAX endpoint that returns the GoCardless setup URL. It stores
 * user input in session until user is forwarded back from GoCardless
 *
 * @param array $post
 * @return array
 */
function eas_prepare_gocardless_donation(array $post)
{
    try {
        // Make GoCardless redirect flow
        $returnUrl    = eas_get_ajax_endpoint() . '?action=gocardless_debit';
        $reqId        = uniqid(); // Secret request ID. Needed to prevent replay attack
        $monthly      = $post['frequency'] == 'monthly' ? ", " . __("monthly", "eas-donation-processor") : "";
        $client       = eas_get_gocardless_client(
            $post['form'],
            $post['mode'],
            eas_get($post['tax_receipt'], false),
            $post['currency'],
            $post['country']
        );
        $redirectFlow = $client->redirectFlows()->create([
            "params" => [
                "description"          => __("Donation", "eas-donation-processor") . " (" . $post['currency'] . " " . money_format('%i', $post['amount']) . $monthly . ")",
                "session_token"        => $reqId,
                "success_redirect_url" => $returnUrl,
            ]
        ]);

        // Save flow ID to session
        $_SESSION['eas-gocardless-flow-id'] = $redirectFlow->id;

        // Save rest to session
        eas_set_donation_data_to_session($post, $reqId);

        // Return redirect URL
        return array(
            'success' => true,
            'url'     => $redirectFlow->redirect_url,
        );
    } catch (\Exception $ex) {
        return array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $ex->getMessage() . "). Please contact us.",
        );
    }
}

/**
 * AJAX endpoint that debits donor with GoCardless.
 * The user is redirected here after successful signup.
 */
function eas_process_gocardless_donation()
{
    try {
        // Get donation from session
        $donation = eas_get_donation_from_session();

        // Reset request ID to prevent replay attacks
        eas_reset_request_id();

        // Get client
        $form       = $donation['form'];
        $mode       = $donation['mode'];
        $taxReceipt = $donation['tax_receipt'];
        $currency   = $donation['currency'];
        $country    = $donation['country'];
        $reqId      = $donation['reqId'];
        $client     = eas_get_gocardless_client($form, $mode, $taxReceipt, $currency, $country);

        if (!isset($_GET['redirect_flow_id']) || $_GET['redirect_flow_id'] != $_SESSION['eas-gocardless-flow-id']) {
            throw new \Exception('Invalid flow ID');
        }

        // Complete flow
        $redirectFlow = $client->redirectFlows()->complete(
            $_GET['redirect_flow_id'],
            ["params" => [
                "session_token" => $reqId
            ]]
        );

        // Get other parameters
        $language  = $donation['language'];
        $url       = $donation['url'];
        $amount    = $donation['amount'];
        $amountInt = floor($amount * 100);
        $name      = $donation['name'];
        $email     = $donation['email'];
        $frequency = $donation['frequency'];
        $purpose   = $donation['purpose'];

        $payment = [
            "params" => [
                "amount"   => $amountInt, // in cents!
                "currency" => $currency,
                "links" => [
                    "mandate" => $redirectFlow->links->mandate,
                ],
                "metadata" => [
                    "Form"     => $form,
                    "URL"      => $url,
                    "Purpose"  => $purpose,
                ]
            ],
            "headers" => [
                "Idempotency-Key" => $reqId
            ],
        ];

        // Add subscription fields if necessary and execute payment
        if ($frequency == 'monthly') {
            // Start paying in a week, unless it's the 29th, 30th, or 31st day of the month.
            // If that's the case, start on the first day of the following month.
            $startDate                          = new \DateTime('+7 days');
            $payment['params']['day_of_month']  = $startDate->format('d') <= 28 ? $startDate->format('d') : 1;
            $payment['params']['interval_unit'] = 'monthly';

            $client->subscriptions()->create($payment);
        } else {
            $client->payments()->create($payment);
        }

        // Add vendor customer ID to donation
        $donation['vendor_customer_id'] = $redirectFlow->links->customer;

        // Do post donation actions
        eas_do_post_donation_actions($donation);

        $script = "var mainWindow = (window == top) ? /* mobile */ opener : /* desktop */ parent; mainWindow.showConfirmation('gocardless'); mainWindow.hideModal();";
    } catch (\Exception $e) {
        $script = "var mainWindow = (window == top) ? /* mobile */ opener : /* desktop */ parent; alert('" . $e->getMessage() . "'); mainWindow.hideModal();";
    }

    // Die and send script to close flow
    die('<!doctype html>
         <html lang="en"><head><meta charset="utf-8"><title>Closing flow...</title></head>
         <body><script>' . $script . '</script></body></html>');
}

/**
 * Get BitPay key IDs
 *
 * @param string $pairingCode
 * @return array
 */
function eas_get_bitpay_key_ids($pairingCode)
{
    return array(
        'bitpay-private-key-' . $pairingCode,
        'bitpay-public-key-' . $pairingCode,
        'bitpay-token-' . $pairingCode,
    );
}

/**
 * Get BitPay object
 *
 * @param string $form
 * @param string $mode
 * @param bool   $taxReceipt
 * @param string $currency
 * @param string $country
 * @return \Bitpay\Bitpay
 */
function eas_get_bitpay_dependency_injector($form, $mode, $taxReceipt, $currency, $country)
{
    // Get BitPay pairing code
    $formSettings = eas_load_settings($form);
    $settings     = eas_get_best_payment_provider_settings(
        $formSettings,
        "bitpay",
        $mode,
        $taxReceipt,
        $currency,
        $country
    );
    $pairingCode = $settings['pairing_code'];

    // Get key IDs
    list($privateKeyId, $publicKeyId, $tokenId) = eas_get_bitpay_key_ids($pairingCode);

    // Get BitPay client
    $bitpay = new \Bitpay\Bitpay(array(
        'bitpay' => array(
            'network'              => $mode == 'live' ? 'livenet' : 'testnet',
            'public_key'           => $publicKeyId,
            'private_key'          => $privateKeyId,
            'key_storage'          => 'EAS\Bitpay\EncryptedWPOptionStorage',
            'key_storage_password' => $pairingCode, // Abuse pairing code for this
        )
    ));

    return $bitpay;
}

/**
 * Get BitPay token
 *
 * @param \Bitpay\Bitpay $bitpay
 * @param string $label
 * @return \Bitpay\Token
 */
function eas_generate_bitpay_token(\Bitpay\Bitpay $bitpay, $label = '')
{
    // Get BitPay pairing code as well as key/token IDs
    $pairingCode = $bitpay->getContainer()->getParameter('bitpay.key_storage_password');
    list($privateKeyId, $publicKeyId, $tokenId) = eas_get_bitpay_key_ids($pairingCode);
    
    // Generate keys
    $privateKey = \Bitpay\PrivateKey::create($privateKeyId)
        ->generate();
    $publicKey  = \Bitpay\PublicKey::create($publicKeyId)
        ->setPrivateKey($privateKey)
        ->generate();

    // Save keys (abuse pairing code as encryption password)
    $keyStorage = $bitpay->get('key_manager');
    $keyStorage->persist($privateKey);
    $keyStorage->persist($publicKey);

    // Get token
    // @var \Bitpay\SinKey
    $sin   = \Bitpay\SinKey::create()->setPublicKey($publicKey)->generate();
    $token = $bitpay->get('client')->createToken(array(
        'pairingCode' => $pairingCode,
        'label'       => $label,
        'id'          => (string) $sin,
    ));

    // Save token
    update_option($tokenId, $token->getToken());

    return $token;
}

/**
 * Get BitPay client
 *
 * @param string $form
 * @param string $mode
 * @param bool   $taxReceipt
 * @param string $currency
 * @param string $country
 * @return \Bitpay\Client\Client
 */
function eas_get_bitpay_client($form, $mode, $taxReceipt, $currency, $country)
{
    // Get BitPay dependency injector
    $bitpay = eas_get_bitpay_dependency_injector($form, $mode, $taxReceipt, $currency, $country);

    // Get BitPay pairing code as well as key/token IDs
    $pairingCode = $bitpay->getContainer()->getParameter('bitpay.key_storage_password');
    list($privateKeyId, $publicKeyId, $tokenId) = eas_get_bitpay_key_ids($pairingCode);

    // Generate token if first time
    if (!get_option($publicKeyId) || !get_option($privateKeyId) || !($tokenString = get_option($tokenId))) {
        $urlParts = parse_url(home_url());
        $label    = $urlParts['host'];
        $token    = eas_generate_bitpay_token($bitpay, $label);
    } else {
        $token = new \Bitpay\Token();
        $token->setToken($tokenString);
    }

    $client = $bitpay->get('client');
    $client->setToken($token);

    return $client;
}

/**
 * Returns the Skrill URL. It stores
 * user input in session until user is forwarded back from Skrill
 *
 * @param array $post
 * @return array
 */
function eas_prepare_skrill_donation(array $post)
{
    try {
        // Save request ID to session
        $reqId = uniqid(); // Secret request ID. Needed to prevent replay attack

        // Put user data in session
        eas_set_donation_data_to_session($post, $reqId);

        // Get Skrill URL
        $url = eas_get_skrill_url($reqId, $post);

        // Return URL
        return array(
            'success' => true,
            'url'     => $url,
        );
    } catch (\Exception $e) {
        return array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $e->getMessage() . "). Please contact us.",
        );
    }
}

/**
 * Get Skrill URL
 *
 * @param string $reqId
 * @param array  $post
 * @return string
 */
function eas_get_skrill_url($reqId, $post)
{
    // Get best Skrill account settings
    $formSettings = eas_load_settings($post['form']);
    $settings     = eas_get_best_payment_provider_settings(
        $formSettings,
        "skrill",
        $post['mode'],
        eas_get($post['tax_receipt'], false),
        $post['currency'],
        $post['country']
    );

    // Prepare parameter array
    $params = array(
        'pay_to_email'      => $settings['merchant_account'],
        'pay_from_email'    => $post['email'],
        'amount'            => $post['amount'],
        'currency'          => $post['currency'],
        'return_url'        => eas_get_ajax_endpoint() . '?action=skrill_log&req=' . $reqId,
        'return_url_target' => 3, // _self
        'logo_url'          => preg_replace("/^http:/i", "https:", get_option('logo', plugin_dir_url(__FILE__) . 'images/logo.png')),
        'language'          => strtoupper($post['language']),
        'transaction_id'    => $reqId,
        'payment_methods'   => "WLT", // Skrill comes first
        'prepare_only'      => 1, // Return URL instead of form HTML
    );

    // Add parameters for monthly donations
    if ($post['frequency'] == 'monthly') {
        $recStartDate = new \DateTime('+1 month');
        $params['rec_amount']     = $post['amount'];
        $params['rec_start_date'] = $recStartDate->format('d/m/Y');
        $params['rec_period']     = 1;
        $params['rec_cycle']      = 'month';
    }

    // Make options
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
        ),
    );

    //FIXME Remove this when XAMPP problem is fixed
    if ($post['mode'] == 'sandbox') {
        // Disable verify peer for local development
        $options['ssl'] = array('verify_peer' => false);
    }

    $context = stream_context_create($options);
    $sid     = file_get_contents($GLOBALS['SkrillApiEndpoint'], false, $context);

    return $GLOBALS['SkrillApiEndpoint'] . '/?sid=' . $sid;
}

/**
 * AJAX endpoint that returns the BitPay URL. It stores
 * user input in session until user is forwarded back from BitPay
 *
 * @param array $post
 * @return array
 */
function eas_prepare_bitpay_donation(array $post)
{
    try {
        $form       = $post['form'];
        $mode       = $post['mode'];
        $language   = $post['language'];
        $email      = $post['email'];
        $name       = $post['name'];
        $amount     = $post['amount'];
        $currency   = $post['currency'];
        $taxReceipt = eas_get($post['tax_receipt'], false);
        $country    = $post['country'];
        $frequency  = $post['frequency'];
        $reqId      = uniqid(); // Secret request ID. Needed to prevent replay attack
        $returnUrl  = eas_get_ajax_endpoint() . '?action=bitpay_log&req=' . $reqId;
        //$returnUrl       = eas_get_ajax_endpoint() . '?action=bitpay_confirm';

        // Get BitPay object and token
        $client = eas_get_bitpay_client($form, $mode, $taxReceipt, $currency, $country);

        // Make item
        $item = new \Bitpay\Item();
        $item
            ->setCode("$form.$mode.$frequency.$currency.$amount")
            ->setDescription("$name ($email)")
            ->setPrice(money_format('%i', $amount));

        // Prepare buyer
        $buyer = new \Bitpay\Buyer();
        $buyer->setEmail($email);

        // Prepare invoice
        $invoice = new \Bitpay\Invoice();
        $invoice
            ->setCurrency(new \Bitpay\Currency($currency))
            ->setItem($item)
            ->setBuyer($buyer)
            ->setRedirectUrl($returnUrl);
            //->setNotificationUrl($notificationUrl);

        // Create invoice
        try {
            $client->createInvoice($invoice);
        } catch (\Exception $e) {
            $request  = $client->getRequest();
            $response = $client->getResponse();
            $message  = (string) $request.PHP_EOL.PHP_EOL.PHP_EOL;
            $message .= (string) $response.PHP_EOL.PHP_EOL;
            throw new \Exception($message);
        }

        // Save invoice ID to session
        $_SESSION['eas-vendor-transaction-id']  = $invoice->getId();

        // Save user data to session
        eas_set_donation_data_to_session($post, $reqId);

        // Return pay key
        return array(
            'success' => true,
            'url'     => $invoice->getUrl(),
        );
    } catch (\Exception $e) {
        return array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $e->getMessage() . "). Please contact us.",
        );
    }
}

/**
 * Verify session and reset request ID
 *
 * @throws \Exception
 */
function eas_verify_session()
{
    if (!isset($_GET['req']) || $_GET['req'] != $_SESSION['eas-req-id']) {
        throw new \Exception('Invalid request');
    }

    // Reset request ID to prevent replay attacks
    eas_reset_request_id();
}

/**
 * Reset request ID from session (used for payment providers with redirect)
 */
function eas_reset_request_id()
{
    $_SESSION['eas-req-id'] = uniqid();
}

/**
 * AJAX endpoint for handling donation logging for Skrill.
 * User is forwarded here after successful Skrill transaction.
 * Takes user data from session and triggers the web hooks.
 *
 * @return string HTML with script that terminates the Skrill flow and shows the thank you step
 */
function eas_process_skrill_log()
{
    try {
        // Make sure it's the same user session
        eas_verify_session();

        // Get donation from session
        $donation = eas_get_donation_from_session();

        // Do post donation actions
        eas_do_post_donation_actions($donation);
    } catch (\Exception $e) {
        // No need to say anything. Just show confirmation.
    }

    die('<!doctype html>
         <html lang="en"><head><meta charset="utf-8"><title>Closing flow...</title></head>
         <body><script>parent.showConfirmation("skrill"); parent.hideModal();</script></body></html>');
}

/**
 * AJAX endpoint for handling donation logging for BitPay.
 * User is forwarded here after successful BitPay transaction.
 * Takes user data from session and triggers the web hooks.
 *
 * @return string HTML with script that terminates the BitPay flow and shows the thank you step
 */
function eas_process_bitpay_log()
{
    try {
        // Make sure it's the same user session
        eas_verify_session();

        // Get donation from session
        $donation   = eas_get_donation_from_session();
        $form       = $donation['form'];
        $mode       = $donation['mode'];
        $taxReceipt = $donation['tax_receipt'];
        $currency   = $donation['currency'];
        $country    = $donation['country'];

        // Add vendor transaction ID (BitPay invoice ID)
        $donation['vendor_transaction_id'] = $_SESSION['eas-vendor-transaction-id'];

        // Make sure the payment is paid
        $client      = eas_get_bitpay_client($form, $mode, $taxReceipt, $currency, $country);
        $invoice     = $client->getInvoice($_SESSION['eas-vendor-transaction-id']);
        $status      = $invoice->getStatus();
        $validStates = array(
            \Bitpay\Invoice::STATUS_PAID,
            \Bitpay\Invoice::STATUS_CONFIRMED,
            \Bitpay\Invoice::STATUS_COMPLETE,
        );
        if (!in_array($status, $validStates)) {
            throw new \Exception('Not paid');
        }

        // Do post donation actions
        eas_do_post_donation_actions($donation);
    } catch (\Exception $e) {
        // No need to say anything. Just show confirmation.
    }

    die('<!doctype html>
         <html lang="en"><head><meta charset="utf-8"><title>Closing flow...</title></head>
         <body><script>var mainWindow = (window == top) ? /* mobile */ opener : /* desktop */ parent; mainWindow.showConfirmation("bitpay"); mainWindow.hideModal();</script></body></html>');
}

/**
 * Get donation data from session
 *
 * @return Eas/Donation
 */
function eas_get_donation_from_session()
{
    //return unserialize($_SESSION['eas-donation']);

    return array(
        "time"        => date('c'), // new
        "form"        => $_SESSION['eas-form'],
        "mode"        => $_SESSION['eas-mode'],
        "language"    => $_SESSION['eas-language'],
        "url"         => $_SESSION['eas-url'],
        "reqId"       => $_SESSION['eas-req-id'],
        "email"       => $_SESSION['eas-email'],
        "name"        => $_SESSION['eas-name'],
        "currency"    => $_SESSION['eas-currency'],
        "country"     => $_SESSION['eas-country'],
        "amount"      => $_SESSION['eas-amount'],
        "frequency"   => $_SESSION['eas-frequency'],
        "tax_receipt" => $_SESSION['eas-tax-receipt'],
        "type"        => $_SESSION['eas-type'],
        "purpose"     => $_SESSION['eas-purpose'],
        "address"     => $_SESSION['eas-address'],
        "zip"         => $_SESSION['eas-zip'],
        "city"        => $_SESSION['eas-city'],
        "mailinglist" => $_SESSION['eas-mailinglist'],
        "comment"     => $_SESSION['eas-comment'],
        "account"     => $_SESSION['eas-account'],
        "anonymous"   => $_SESSION['eas-anonymous'],
    );
}

/**
 * Set donation data to session
 *
 * @param array  $post  Form post
 * @param string $reqId Request ID (against replay attack)
 */
function eas_set_donation_data_to_session(array $post, $reqId = null)
{
    // Required fields
    $_SESSION['eas-form']        = $post['form'];
    $_SESSION['eas-mode']        = $post['mode'];
    $_SESSION['eas-language']    = $post['language'];
    $_SESSION['eas-url']         = $_SERVER['HTTP_REFERER'];
    $_SESSION['eas-req-id']      = $reqId;
    $_SESSION['eas-email']       = $post['email'];
    $_SESSION['eas-name']        = $post['name'];
    $_SESSION['eas-currency']    = $post['currency'];
    $_SESSION['eas-country']     = $post['country'];
    $_SESSION['eas-amount']      = money_format('%i', $post['amount']);
    $_SESSION['eas-frequency']   = $post['frequency'];
    $_SESSION['eas-type']        = $post['payment'];

    // Optional fields
    $_SESSION['eas-purpose']     = eas_get($post['purpose'], '');
    $_SESSION['eas-address']     = eas_get($post['address'], '');
    $_SESSION['eas-zip']         = eas_get($post['zip'], '');
    $_SESSION['eas-city']        = eas_get($post['city'], '');
    $_SESSION['eas-comment']     = eas_get($post['comment'], '');
    $_SESSION['eas-account']     = eas_get($post['account'], '');
    $_SESSION['eas-tax-receipt'] = (bool) eas_get($post['tax_receipt'], false);
    $_SESSION['eas-mailinglist'] = (bool) eas_get($post['mailinglist'], false);
    $_SESSION['eas-anonymous']   = (bool) eas_get($post['anonymous'], false);
}

/**
 * Make PayPal payment (= one-time payment)
 *
 * @param array $post
 * @return PayPal\Api\Payment
 */
function eas_create_paypal_payment(array $post)
{
    // Make payer
    $payer = new \PayPal\Api\Payer();
    $payer->setPaymentMethod("paypal");

    // Make amount
    $amount = new \PayPal\Api\Amount();
    $amount->setCurrency($post['currency'])
        ->setTotal($post['amount']);

    // Make transaction
    $transaction = new \PayPal\Api\Transaction();
    $transaction->setAmount($amount)
        ->setDescription($post['name'] . ' (' . $post['email'] . ')')
        ->setInvoiceNumber(uniqid());

    // Make redirect URLs
    $returnUrl    = eas_get_ajax_endpoint() . '?action=paypal_execute';
    $redirectUrls = new \PayPal\Api\RedirectUrls();
    $redirectUrls->setReturnUrl($returnUrl)
        ->setCancelUrl($returnUrl);

    // Make payment
    $payment = new \PayPal\Api\Payment();
    $payment->setIntent("sale")
        ->setPayer($payer)
        ->setTransactions(array($transaction))
        ->setRedirectUrls($redirectUrls);

    // Get API context end create payment
    $apiContext = eas_get_paypal_api_context(
        $post['form'],
        $post['mode'],
        eas_get($post['tax_receipt'], false),
        $post['currency'],
        $post['country']
    );

    return $payment->create($apiContext);
}

/**
 * Make PayPal billing agreement (= recurring payment)
 *
 * @param array $post
 * @return \PayPal\Api\Agreement
 */
function eas_create_paypal_billing_agreement(array $post)
{
    // Make new plan
    $plan = new \PayPal\Api\Plan();
    $plan->setName('Monthly Donation')
        ->setDescription('Monthly donation of ' . $post['currency'] . ' ' . $post['amount'])
        ->setType('INFINITE');

    // Make payment definition
    $paymentDefinition = new \PayPal\Api\PaymentDefinition();
    $paymentDefinition->setName('Regular Payments')
        ->setType('REGULAR')
        ->setFrequency('Month')
        ->setFrequencyInterval('1')
        ->setCycles('0')
        ->setAmount(new \PayPal\Api\Currency(array('value' => $post['amount'], 'currency' => $post['currency'])));

    // Make merchant preferences
    $returnUrl           = eas_get_ajax_endpoint() . '?action=paypal_execute';
    $merchantPreferences = new \PayPal\Api\MerchantPreferences();
    $merchantPreferences->setReturnUrl($returnUrl)
        ->setCancelUrl($returnUrl)
        ->setAutoBillAmount("yes")
        ->setInitialFailAmountAction("CONTINUE")
        ->setMaxFailAttempts("0");

    // Put things together and create
    $apiContext = eas_get_paypal_api_context(
        $post['form'],
        $post['mode'],
        eas_get($post['tax_receipt'], false),
        $post['currency'],
        $post['country']
    );
    $plan->setPaymentDefinitions(array($paymentDefinition))
        ->setMerchantPreferences($merchantPreferences)
        ->create($apiContext);

    // Activate plan
    $patch = new \PayPal\Api\Patch();
    $value = new \PayPal\Common\PayPalModel('{
       "state":"ACTIVE"
     }');
    $patch->setOp('replace')
        ->setPath('/')
        ->setValue($value);
    $patchRequest = new PayPal\Api\PatchRequest();
    $patchRequest->addPatch($patch);
    $plan->update($patchRequest, $apiContext);

    // Make payer
    $payer = new \PayPal\Api\Payer();
    $payer->setPaymentMethod('paypal');

    // Make a fresh plan
    $planID = $plan->getId();
    $plan   = new \PayPal\Api\Plan();
    $plan->setId($planID);

    // Make agreement
    $agreement = new \PayPal\Api\Agreement();
    $startDate = new \DateTime('+1 day'); // Activation can take up to 24 hours
    $agreement->setName(__("Monthly Donation", "eas-donation-processor") . ': ' . $post['currency'] . ' ' . $post['amount'])
        ->setDescription(__("Monthly Donation", "eas-donation-processor") . ': ' . $post['currency'] . ' ' . $post['amount'])
        ->setStartDate($startDate->format('c'))
        ->setPlan($plan)
        ->setPayer($payer);

    return $agreement->create($apiContext);
}

/**
 * Returns Paypal pay key for donation. It stores
 * user input in session until user is forwarded back from Paypal
 *
 * @param array $post
 * @return array
 */
function eas_prepare_paypal_donation(array $post)
{
    try {
        if ($post['frequency'] == 'monthly') {
            $billingAgreement = eas_create_paypal_billing_agreement($post);

            // Save doantion to session
            eas_set_donation_data_to_session($post);

            // Parse approval link
            $approvalLinkParts = parse_url($billingAgreement->getApprovalLink());
            parse_str($approvalLinkParts['query'], $query);

            return array(
                'success' => true,
                'token'   => $query['token'],
            );
        } else {
            $payment = eas_create_paypal_payment($post);

            // Save doantion to session
            eas_set_donation_data_to_session($post);

            return array(
                'success'   => true,
                'paymentID' => $payment->getId(),
            );
        }
    } catch (\PayPal\Exception\PayPalConnectionException $ex) {
        return array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $ex->getData() . "). Please contact us.",
        );
    } catch (\Exception $ex) {
        return array(
            'success' => false,
            'error'   => "An error occured and your donation could not be processed (" .  $ex->getMessage() . "). Please contact us.",
        );
    }
}

/**
 * AJAX endpoint for executing and logging PayPal donations.
 * Takes user data from session and triggers the web hooks.
 *
 * @return string HTML with script that terminates the PayPal flow and shows the thank you step
 */
function eas_execute_paypal_donation()
{
    try {
        // Get donation from session
        $donation = eas_get_donation_from_session();

        // Get API context
        $apiContext = eas_get_paypal_api_context(
            $donation['form'],
            $donation['mode'],
            $donation['tax_receipt'],
            $donation['currency'],
            $donation['country']
        );

        if (!empty($_POST['paymentID']) && !empty($_POST['payerID'])) {
            // Execute payment (one-time)
            $paymentId = $_POST['paymentID'];
            $payment   = \PayPal\Api\Payment::get($paymentId, $apiContext);
            $execution = new \PayPal\Api\PaymentExecution();
            $execution->setPayerId($_POST['payerID']);
            $payment->execute($execution, $apiContext);
        } else if (!empty($_POST['token'])) {
            // Execute billing agreement (monthly)
            $agreement = new \PayPal\Api\Agreement();
            $agreement->execute($_POST['token'], $apiContext);
        } else {
            throw new \Exception("An error occured. Payment aborted.");
        }

        // Do post donation actions
        eas_do_post_donation_actions($donation);

        // Send response
        die(json_encode(array('success' => true)));
    } catch (\Exception $ex) {
        die(json_encode(array(
            'success' => false,
            'error'   => $ex->getMessage(),
        )));
    }
}

/**
 * Save donation log (custom post) if enabled
 *
 * @param array $donation
 */
function eas_save_donation_log_post(array $donation)
{
    // Check if max defined
    $formSettings = eas_load_settings($donation['form']);
    if (empty($formSettings['log']['max'])) {
        // Logs disabled
        return;
    }

    $logMax    = (int) $formSettings['log']['max'];
    $form      = $donation['form'];
    $name      = $donation['name'];
    $currency  = $donation['currency'];
    $amount    = $donation['amount'];
    $frequency = $donation['frequency'];

    // Save donation as a custom post
    $newPost = array(
        "post_title"  => "$name donated $currency $amount ($frequency) on $form",
        "post_type"   => "eas_donation_log",
        "post_status" => "private",
    );
    $postId = wp_insert_post($newPost);

    // Add custom fields
    foreach ($donation as $key => $value) {
        add_post_meta($postId, $key, $value);
    }

    // Delete old post from queue
    $args = array(
        'post_type'  => 'eas_donation_log',
        'meta_key'   => 'form',
        'meta_value' => $form,
        'offset'     => $logMax,
        'orderby'    => 'ID',
        'order'      => 'DESC',
    );
    $query = new WP_Query($args);
    while ($query->have_posts()) {
        $query->the_post();
        wp_delete_post(get_the_ID());
    }
    wp_reset_postdata();
}

/**
 * Save custom posts (fundraiser donation post, donation log post)
 *
 * @param array $donation
 */
function eas_save_custom_posts(array $donation)
{
    // Fundraiser donation post (if it's the case)
    eas_save_matching_challenge_donation_post($donation);

    // Donation log post (if enabled)
    eas_save_donation_log_post($donation);
}

/**
 * Save matching challenge donation (custom post) if a matching challenge campaign is linked to the form
 *
 * @param array $donation
 */
function eas_save_matching_challenge_donation_post(array $donation)
{
    $form      = $donation['form'];
    $name      = $donation['anonymous'] == 'yes' ? 'Anonymous' : $donation['name'];
    $currency  = $donation['currency'];
    $amount    = $donation['amount'];
    $frequency = $donation['frequency'];
    $comment   = $donation['comment'];

    $formSettings = eas_load_settings($form);

    if (empty($formSettings['campaign'])) {
        // No fundraiser campaign set
        return;
    }

    $matchingCampaign = $formSettings['campaign'];

    // Save donation as a custom post
    $newPost = array(
        "post_title"  => "$name contributed $currency $amount ($frequency) to fundraiser campaign (ID = $matchingCampaign)",
        "post_type"   => "eas_donation",
        "post_status" => "private",
    );
    $postId = wp_insert_post($newPost);

    // Add custom fields
    add_post_meta($postId, 'name', $name);
    add_post_meta($postId, 'currency', $currency);
    add_post_meta($postId, 'amount', preg_replace('#\.00$#', '', $amount));
    add_post_meta($postId, 'frequency', $frequency);
    add_post_meta($postId, 'campaign', $matchingCampaign);
    add_post_meta($postId, 'comment', $comment);
}

/**
 * Filter for changing sender email address
 *
 * @param string $original_email_address
 * @return string
 */
function eas_get_email_address($original_email_address)
{
    return !empty($GLOBALS['easEmailAddress']) ? $GLOBALS['easEmailAddress'] : $original_email_address;
}

/**
 * Filter for changing email sender
 *
 * @param string $original_email_sender
 * @return string
 */
function eas_get_email_sender($original_email_sender)
{
    return !empty($GLOBALS['easEmailSender']) ? $GLOBALS['easEmailSender'] : $original_email_sender;
}

/**
 * Filter for changing email content type
 *
 * @param string $original_content_type
 * @return string
 */
function eas_get_email_content_type($original_content_type)
{
    return $GLOBALS['easEmailContentType'];
}

/**
 * Send notification email to admin (if email set)
 *
 * @param array  $donation
 */
function eas_send_notification_email(array $donation)
{
    $form = eas_get($donation['form'], '');

    // Return if admin email not set
    $formSettings = eas_load_settings($form);
    if (empty($formSettings['finish']['notification_email'])) {
        return;
    }

    $emails = $formSettings['finish']['notification_email'];

    // Run email filters if array
    if (is_array($emails)) {
        $matchingEmails = array();

        // Loop over emails and keep only those who have no condition mismatches
        foreach ($emails as $email => $conditions) {
            if (!is_array($conditions)) {
                continue;
            }

            foreach ($conditions as $field => $requiredValue) {
                if (!isset($donation[$field]) || strtolower($donation[$field]) != strtolower($requiredValue)) {
                    continue 2;
                }
            }

            $matchingEmails[] = $email;
        }

        if (count($matchingEmails) > 0) {
            $emails = implode(', ', $matchingEmails);
        } else {
            // No matching emails. Nothing to do.
            return;
        }
    }

    // Trim amount
    if (!empty($donation['amount'])) {
        $donation['amount'] = preg_replace('#\.00$#', '', $donation['amount']);
    }

    // Prepare email
    $freq    = !empty($donation['frequency']) && $donation['frequency'] == 'monthly' ? ' (monthly)' : '';
    $subject = $form
               . ' : ' . eas_get($donation['currency'], '') . ' ' . eas_get($donation['amount'], '') . $freq
               . ' : ' . eas_get($donation['name'], '');
    $text    = '';
    foreach ($donation as $key => $value) {
        $text .= $key . ' : ' . $value . "\n";
    }

    // Send email
    wp_mail($emails, $subject, $text);
}

/**
 * Send email mit thank you message
 *
 * @param array  $donation Donation
 * @param string $form     Form name
 */
function eas_send_confirmation_email(array $donation)
{
    $form         = eas_get($donation['form'], '');
    $formSettings = eas_load_settings($form);

    // Only send email if we have settings (might not be the case if we're dealing with script kiddies)
    if (isset($formSettings['finish']['email'])) {
        $language      = eas_get($donation['language']);
        $emailSettings = eas_get_localized_value($formSettings['finish']['email'], $language);

        // Add tax dedcution labels to donation
        $donation += eas_get_tax_deduction_settings_by_donation($donation);

        // Get email subject and text and pass it through twig
        $twig    = eas_get_twig($form, $language);
        $subject = $twig->render('finish.email.subject', $donation);
        $text    = $twig->render('finish.email.text', $donation);

        // Repalce %bank_account_formatted% in success_text with macro
        if (!empty($donation['bank_account'])) {
            $bankAccount = eas_get($emailSettings['html'], false) ? $twig->render('bank_account_formatted_html', $donation)
                                                                  : $twig->render('bank_account_formatted_text', $donation);
            $text = str_replace('%bank_account_formatted%', $bankAccount, $text);
        }

        // Handle legacy name variable in email text
        $text = str_replace('%name%', $donation['name'], $text);

        // The filters below need to access the email settings
        $GLOBALS['easEmailSender']      = eas_get($emailSettings['sender']);
        $GLOBALS['easEmailAddress']     = eas_get($emailSettings['address']);
        $GLOBALS['easEmailContentType'] = eas_get($emailSettings['html'], false) ? 'text/html' : 'text/plain';

        // Add email hooks
        add_filter('wp_mail_from', 'eas_get_email_address', EAS_PRIORITY, 1);
        add_filter('wp_mail_from_name', 'eas_get_email_sender', EAS_PRIORITY, 1);
        add_filter('wp_mail_content_type', 'eas_get_email_content_type', EAS_PRIORITY, 1);

        // Send email
        wp_mail($donation['email'], $subject, $text);

        // Remove email hooks
        remove_filter('wp_mail_from', 'eas_get_email_address', EAS_PRIORITY);
        remove_filter('wp_mail_from_name', 'eas_get_email_sender', EAS_PRIORITY);
        remove_filter('wp_mail_content_type', 'eas_get_email_content_type', EAS_PRIORITY);
    }
}

/**
 * Auxiliary function for checking if array has string keys
 *
 * @param array $array The array in question
 * @return bool
 */
function eas_has_string_keys(array $array) {
    return count(array_filter(array_keys($array), 'is_string')) > 0;
}

/**
 * Get user country from freegeoip.net, e.g. as ['code' => 'CH', 'name' => 'Switzerland']
 *
 * @param string $userIp
 * @return array
 */
function eas_get_user_country($userIp = null)
{
    if (!$userIp) {
        $userIp = $_SERVER['REMOTE_ADDR'];
    }

    try {
        if (!empty($userIp)) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "http://freegeoip.net/json/" . $userIp);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($output, true);

            if (empty($response['country_name']) || empty($response['country_code'])) {
                throw new \Exception('Invalid response');
            }

            return array(
                'code' => $response['country_code'],
                'name' => $response['country_name'],
            );
        } else {
            return array();
        }
    } catch (\Exception $ex) {
        return array();
    }
}

/**
 * Get user currency
 *
 * @param string $countryCode E.g. 'CH'
 * @return string|null
 */
function eas_get_user_currency($countryCode = null)
{
    if (!$countryCode) {
        $userCountry = eas_get_user_country();
        if (!$userCountry) {
            return null;
        }
        $countryCode = $userCountry['code'];
    }

    $mapping = $GLOBALS['country2currency'];

    return eas_get($mapping[$countryCode]);
}

/**
 * Get list of countries. Keys is country code, value a numeric
 * array in which the first element is the translated country
 * name and the second element the English country name.
 *
 * E.g. for a visitor on the German website you get
 * [
 *   "DE" => [0 => "Deutschland", 1 => "Germany"],
 *   "CH" => [0 => "Schweiz", 1 => "Switzerland"],
 *   ...
 * ]
 *
 * @param array|string[] Country list gets filtered, e.g. array('CH') will only return Switzerland
 * @return array
 */
function eas_get_sorted_country_list($countryCodeFilters = array())
{
    $countries = array(
        "AF" => __("Afghanistan", "eas-donation-processor"),
        "AX" => __("Åland Islands", "eas-donation-processor"),
        "AL" => __("Albania", "eas-donation-processor"),
        "DZ" => __("Algeria", "eas-donation-processor"),
        "AS" => __("American Samoa", "eas-donation-processor"),
        "AD" => __("Andorra", "eas-donation-processor"),
        "AO" => __("Angola", "eas-donation-processor"),
        "AI" => __("Anguilla", "eas-donation-processor"),
        "AQ" => __("Antarctica", "eas-donation-processor"),
        "AG" => __("Antigua and Barbuda", "eas-donation-processor"),
        "AR" => __("Argentina", "eas-donation-processor"),
        "AM" => __("Armenia", "eas-donation-processor"),
        "AW" => __("Aruba", "eas-donation-processor"),
        "AU" => __("Australia", "eas-donation-processor"),
        "AT" => __("Austria", "eas-donation-processor"),
        "AZ" => __("Azerbaijan", "eas-donation-processor"),
        "BS" => __("Bahamas", "eas-donation-processor"),
        "BH" => __("Bahrain", "eas-donation-processor"),
        "BD" => __("Bangladesh", "eas-donation-processor"),
        "BB" => __("Barbados", "eas-donation-processor"),
        "BY" => __("Belarus", "eas-donation-processor"),
        "BE" => __("Belgium", "eas-donation-processor"),
        "BZ" => __("Belize", "eas-donation-processor"),
        "BJ" => __("Benin", "eas-donation-processor"),
        "BM" => __("Bermuda", "eas-donation-processor"),
        "BT" => __("Bhutan", "eas-donation-processor"),
        "BO" => __("Bolivia, Plurinational State of", "eas-donation-processor"),
        "BQ" => __("Bonaire, Sint Eustatius and Saba", "eas-donation-processor"),
        "BA" => __("Bosnia and Herzegovina", "eas-donation-processor"),
        "BW" => __("Botswana", "eas-donation-processor"),
        "BV" => __("Bouvet Island", "eas-donation-processor"),
        "BR" => __("Brazil", "eas-donation-processor"),
        "IO" => __("British Indian Ocean Territory", "eas-donation-processor"),
        "BN" => __("Brunei Darussalam", "eas-donation-processor"),
        "BG" => __("Bulgaria", "eas-donation-processor"),
        "BF" => __("Burkina Faso", "eas-donation-processor"),
        "BI" => __("Burundi", "eas-donation-processor"),
        "KH" => __("Cambodia", "eas-donation-processor"),
        "CM" => __("Cameroon", "eas-donation-processor"),
        "CA" => __("Canada", "eas-donation-processor"),
        "CV" => __("Cape Verde", "eas-donation-processor"),
        "KY" => __("Cayman Islands", "eas-donation-processor"),
        "CF" => __("Central African Republic", "eas-donation-processor"),
        "TD" => __("Chad", "eas-donation-processor"),
        "CL" => __("Chile", "eas-donation-processor"),
        "CN" => __("China", "eas-donation-processor"),
        "CX" => __("Christmas Island", "eas-donation-processor"),
        "CC" => __("Cocos (Keeling) Islands", "eas-donation-processor"),
        "CO" => __("Colombia", "eas-donation-processor"),
        "KM" => __("Comoros", "eas-donation-processor"),
        "CG" => __("Congo, Republic of", "eas-donation-processor"),
        "CD" => __("Congo, Democratic Republic of the", "eas-donation-processor"),
        "CK" => __("Cook Islands", "eas-donation-processor"),
        "CR" => __("Costa Rica", "eas-donation-processor"),
        "CI" => __("Côte d'Ivoire", "eas-donation-processor"),
        "HR" => __("Croatia", "eas-donation-processor"),
        "CU" => __("Cuba", "eas-donation-processor"),
        "CW" => __("Curaçao", "eas-donation-processor"),
        "CY" => __("Cyprus", "eas-donation-processor"),
        "CZ" => __("Czech Republic", "eas-donation-processor"),
        "DK" => __("Denmark", "eas-donation-processor"),
        "DJ" => __("Djibouti", "eas-donation-processor"),
        "DM" => __("Dominica", "eas-donation-processor"),
        "DO" => __("Dominican Republic", "eas-donation-processor"),
        "EC" => __("Ecuador", "eas-donation-processor"),
        "EG" => __("Egypt", "eas-donation-processor"),
        "SV" => __("El Salvador", "eas-donation-processor"),
        "GQ" => __("Equatorial Guinea", "eas-donation-processor"),
        "ER" => __("Eritrea", "eas-donation-processor"),
        "EE" => __("Estonia", "eas-donation-processor"),
        "ET" => __("Ethiopia", "eas-donation-processor"),
        "FK" => __("Falkland Islands (Malvinas)", "eas-donation-processor"),
        "FO" => __("Faroe Islands", "eas-donation-processor"),
        "FJ" => __("Fiji", "eas-donation-processor"),
        "FI" => __("Finland", "eas-donation-processor"),
        "FR" => __("France", "eas-donation-processor"),
        "GF" => __("French Guiana", "eas-donation-processor"),
        "PF" => __("French Polynesia", "eas-donation-processor"),
        "TF" => __("French Southern Territories", "eas-donation-processor"),
        "GA" => __("Gabon", "eas-donation-processor"),
        "GM" => __("Gambia", "eas-donation-processor"),
        "GE" => __("Georgia", "eas-donation-processor"),
        "DE" => __("Germany", "eas-donation-processor"),
        "GH" => __("Ghana", "eas-donation-processor"),
        "GI" => __("Gibraltar", "eas-donation-processor"),
        "GR" => __("Greece", "eas-donation-processor"),
        "GL" => __("Greenland", "eas-donation-processor"),
        "GD" => __("Grenada", "eas-donation-processor"),
        "GP" => __("Guadeloupe", "eas-donation-processor"),
        "GU" => __("Guam", "eas-donation-processor"),
        "GT" => __("Guatemala", "eas-donation-processor"),
        "GG" => __("Guernsey", "eas-donation-processor"),
        "GN" => __("Guinea", "eas-donation-processor"),
        "GW" => __("Guinea-Bissau", "eas-donation-processor"),
        "GY" => __("Guyana", "eas-donation-processor"),
        "HT" => __("Haiti", "eas-donation-processor"),
        "HM" => __("Heard Island and McDonald Islands", "eas-donation-processor"),
        "VA" => __("Holy See (Vatican City State)", "eas-donation-processor"),
        "HN" => __("Honduras", "eas-donation-processor"),
        "HK" => __("Hong Kong", "eas-donation-processor"),
        "HU" => __("Hungary", "eas-donation-processor"),
        "IS" => __("Iceland", "eas-donation-processor"),
        "IN" => __("India", "eas-donation-processor"),
        "ID" => __("Indonesia", "eas-donation-processor"),
        "IR" => __("Iran, Islamic Republic of", "eas-donation-processor"),
        "IQ" => __("Iraq", "eas-donation-processor"),
        "IE" => __("Ireland", "eas-donation-processor"),
        "IM" => __("Isle of Man", "eas-donation-processor"),
        "IL" => __("Israel", "eas-donation-processor"),
        "IT" => __("Italy", "eas-donation-processor"),
        "JM" => __("Jamaica", "eas-donation-processor"),
        "JP" => __("Japan", "eas-donation-processor"),
        "JE" => __("Jersey", "eas-donation-processor"),
        "JO" => __("Jordan", "eas-donation-processor"),
        "KZ" => __("Kazakhstan", "eas-donation-processor"),
        "KE" => __("Kenya", "eas-donation-processor"),
        "KI" => __("Kiribati", "eas-donation-processor"),
        "KP" => __("Korea, Democratic People's Republic of", "eas-donation-processor"),
        "KR" => __("Korea, Republic of", "eas-donation-processor"),
        "KW" => __("Kuwait", "eas-donation-processor"),
        "KG" => __("Kyrgyzstan", "eas-donation-processor"),
        "LA" => __("Lao People's Democratic Republic", "eas-donation-processor"),
        "LV" => __("Latvia", "eas-donation-processor"),
        "LB" => __("Lebanon", "eas-donation-processor"),
        "LS" => __("Lesotho", "eas-donation-processor"),
        "LR" => __("Liberia", "eas-donation-processor"),
        "LY" => __("Libya", "eas-donation-processor"),
        "LI" => __("Liechtenstein", "eas-donation-processor"),
        "LT" => __("Lithuania", "eas-donation-processor"),
        "LU" => __("Luxembourg", "eas-donation-processor"),
        "MO" => __("Macao", "eas-donation-processor"),
        "MK" => __("Macedonia, Former Yugoslav Republic of", "eas-donation-processor"),
        "MG" => __("Madagascar", "eas-donation-processor"),
        "MW" => __("Malawi", "eas-donation-processor"),
        "MY" => __("Malaysia", "eas-donation-processor"),
        "MV" => __("Maldives", "eas-donation-processor"),
        "ML" => __("Mali", "eas-donation-processor"),
        "MT" => __("Malta", "eas-donation-processor"),
        "MH" => __("Marshall Islands", "eas-donation-processor"),
        "MQ" => __("Martinique", "eas-donation-processor"),
        "MR" => __("Mauritania", "eas-donation-processor"),
        "MU" => __("Mauritius", "eas-donation-processor"),
        "YT" => __("Mayotte", "eas-donation-processor"),
        "MX" => __("Mexico", "eas-donation-processor"),
        "FM" => __("Micronesia, Federated States of", "eas-donation-processor"),
        "MD" => __("Moldova, Republic of", "eas-donation-processor"),
        "MC" => __("Monaco", "eas-donation-processor"),
        "MN" => __("Mongolia", "eas-donation-processor"),
        "ME" => __("Montenegro", "eas-donation-processor"),
        "MS" => __("Montserrat", "eas-donation-processor"),
        "MA" => __("Morocco", "eas-donation-processor"),
        "MZ" => __("Mozambique", "eas-donation-processor"),
        "MM" => __("Myanmar", "eas-donation-processor"),
        "NA" => __("Namibia", "eas-donation-processor"),
        "NR" => __("Nauru", "eas-donation-processor"),
        "NP" => __("Nepal", "eas-donation-processor"),
        "NL" => __("Netherlands", "eas-donation-processor"),
        "NC" => __("New Caledonia", "eas-donation-processor"),
        "NZ" => __("New Zealand", "eas-donation-processor"),
        "NI" => __("Nicaragua", "eas-donation-processor"),
        "NE" => __("Niger", "eas-donation-processor"),
        "NG" => __("Nigeria", "eas-donation-processor"),
        "NU" => __("Niue", "eas-donation-processor"),
        "NF" => __("Norfolk Island", "eas-donation-processor"),
        "MP" => __("Northern Mariana Islands", "eas-donation-processor"),
        "NO" => __("Norway", "eas-donation-processor"),
        "OM" => __("Oman", "eas-donation-processor"),
        "PK" => __("Pakistan", "eas-donation-processor"),
        "PW" => __("Palau", "eas-donation-processor"),
        "PS" => __("Palestinian Territory, Occupied", "eas-donation-processor"),
        "PA" => __("Panama", "eas-donation-processor"),
        "PG" => __("Papua New Guinea", "eas-donation-processor"),
        "PY" => __("Paraguay", "eas-donation-processor"),
        "PE" => __("Peru", "eas-donation-processor"),
        "PH" => __("Philippines", "eas-donation-processor"),
        "PN" => __("Pitcairn", "eas-donation-processor"),
        "PL" => __("Poland", "eas-donation-processor"),
        "PT" => __("Portugal", "eas-donation-processor"),
        "PR" => __("Puerto Rico", "eas-donation-processor"),
        "QA" => __("Qatar", "eas-donation-processor"),
        "RE" => __("Réunion", "eas-donation-processor"),
        "RO" => __("Romania", "eas-donation-processor"),
        "RU" => __("Russian Federation", "eas-donation-processor"),
        "RW" => __("Rwanda", "eas-donation-processor"),
        "SH" => __("Saint Helena, Ascension and Tristan da Cunha", "eas-donation-processor"),
        "KN" => __("Saint Kitts and Nevis", "eas-donation-processor"),
        "LC" => __("Saint Lucia", "eas-donation-processor"),
        "PM" => __("Saint Pierre and Miquelon", "eas-donation-processor"),
        "VC" => __("Saint Vincent and the Grenadines", "eas-donation-processor"),
        "WS" => __("Samoa", "eas-donation-processor"),
        "SM" => __("San Marino", "eas-donation-processor"),
        "ST" => __("Sao Tome and Principe", "eas-donation-processor"),
        "SA" => __("Saudi Arabia", "eas-donation-processor"),
        "SN" => __("Senegal", "eas-donation-processor"),
        "RS" => __("Serbia", "eas-donation-processor"),
        "SC" => __("Seychelles", "eas-donation-processor"),
        "SL" => __("Sierra Leone", "eas-donation-processor"),
        "SG" => __("Singapore", "eas-donation-processor"),
        "SK" => __("Slovakia", "eas-donation-processor"),
        "SI" => __("Slovenia", "eas-donation-processor"),
        "SB" => __("Solomon Islands", "eas-donation-processor"),
        "SO" => __("Somalia", "eas-donation-processor"),
        "ZA" => __("South Africa", "eas-donation-processor"),
        "GS" => __("South Georgia and the South Sandwich Islands", "eas-donation-processor"),
        "SS" => __("South Sudan", "eas-donation-processor"),
        "ES" => __("Spain", "eas-donation-processor"),
        "LK" => __("Sri Lanka", "eas-donation-processor"),
        "SD" => __("Sudan", "eas-donation-processor"),
        "SR" => __("Suriname", "eas-donation-processor"),
        "SJ" => __("Svalbard and Jan Mayen", "eas-donation-processor"),
        "SZ" => __("Swaziland", "eas-donation-processor"),
        "SE" => __("Sweden", "eas-donation-processor"),
        "CH" => __("Switzerland", "eas-donation-processor"),
        "SY" => __("Syrian Arab Republic", "eas-donation-processor"),
        "TW" => __("Taiwan, Province of China", "eas-donation-processor"),
        "TJ" => __("Tajikistan", "eas-donation-processor"),
        "TZ" => __("Tanzania, United Republic of", "eas-donation-processor"),
        "TH" => __("Thailand", "eas-donation-processor"),
        "TL" => __("Timor-Leste", "eas-donation-processor"),
        "TG" => __("Togo", "eas-donation-processor"),
        "TK" => __("Tokelau", "eas-donation-processor"),
        "TO" => __("Tonga", "eas-donation-processor"),
        "TT" => __("Trinidad and Tobago", "eas-donation-processor"),
        "TN" => __("Tunisia", "eas-donation-processor"),
        "TR" => __("Turkey", "eas-donation-processor"),
        "TM" => __("Turkmenistan", "eas-donation-processor"),
        "TC" => __("Turks and Caicos Islands", "eas-donation-processor"),
        "TV" => __("Tuvalu", "eas-donation-processor"),
        "UG" => __("Uganda", "eas-donation-processor"),
        "UA" => __("Ukraine", "eas-donation-processor"),
        "AE" => __("United Arab Emirates", "eas-donation-processor"),
        "GB" => __("United Kingdom", "eas-donation-processor"),
        "US" => __("United States", "eas-donation-processor"),
        "UM" => __("United States Minor Outlying Islands", "eas-donation-processor"),
        "UY" => __("Uruguay", "eas-donation-processor"),
        "UZ" => __("Uzbekistan", "eas-donation-processor"),
        "VU" => __("Vanuatu", "eas-donation-processor"),
        "VE" => __("Venezuela, Bolivarian Republic of", "eas-donation-processor"),
        "VN" => __("Viet Nam", "eas-donation-processor"),
        "VG" => __("Virgin Islands, British", "eas-donation-processor"),
        "VI" => __("Virgin Islands, U.S.", "eas-donation-processor"),
        "WF" => __("Wallis and Futuna", "eas-donation-processor"),
        "EH" => __("Western Sahara", "eas-donation-processor"),
        "YE" => __("Yemen", "eas-donation-processor"),
        "ZM" => __("Zambia", "eas-donation-processor"),
        "ZW" => __("Zimbabwe", "eas-donation-processor"),
    );

    $countriesEn = $GLOBALS['code2country'];

    // Sort by value
    asort($countries);

    // Merge
    $result = array_merge_recursive($countries, $countriesEn);

    // Filter
    if ($countryCodeFilters) {
        $resultSubset = array();
        foreach ($countryCodeFilters as $countryCodeFilter) {
            if (isset($result[$countryCodeFilter])) {
                $resultSubset[$countryCodeFilter] = $result[$countryCodeFilter];
            }
        }
        $result = $resultSubset;
    }

    return $result;
}

/**
 * Get English country name
 *
 * @param string $countryCode E.g. "CH" or "US"
 * @return string E.g. "Switzerland" or "United States"
 */
function eas_get_english_name_by_country_code($countryCode)
{
    $countryCode = strtoupper($countryCode);
    return eas_get($GLOBALS['code2country'][$countryCode], $countryCode);
}

/**
 * Get array with country codes where currency is used
 *
 * @param string $currency E.g. "CHF"
 * @return array E.g. array("LI", "CH")
 */
function eas_get_countries_by_currency($currency)
{
    $mapping = $GLOBALS['currency2country'];

    return eas_get($mapping[strtoupper($currency)], array());
}

/**
 * Get Stripe public keys for the form
 *
 * E.g.
 * [
 *     'default' => ['sandbox' => 'default_sandbox_key', 'live' => 'default_live_key'],
 *     'ch'      => ['sandbox' => 'ch_sandbox_key',  'live' => 'ch_live_key'],
 *     'gb'      => ['sandbox' => 'gb_sandbox_key',  'live' => 'gb_live_key'],
 *     'de'      => ['sandbox' => 'de_sandbox_key',  'live' => 'de_live_key'],
 *     'chf'     => ['sandbox' => 'chf_sandbox_key', 'live' => 'chf_live_key'],
 *     'eur'     => ['sandbox' => 'eur_sandbox_key', 'live' => 'eur_live_key'],
 *     'usd'     => ['sandbox' => 'usd_sandbox_key', 'live' => 'usd_live_key']
 * ]
 *
 * @param array $formSettings
 * @param string $mode sandbox/live
 * @return array
 */
function eas_get_stripe_public_keys(array $formSettings, $mode)
{
    // Get all enabled Stripe accounts with a public key for the given mode
    $stripeAccounts = array_filter(
        eas_get($formSettings['payment']['provider'], array()),
        function ($val, $key) use ($mode) {
            return preg_match('#^stripe#', $key) && !empty($val[$mode]['public_key']) && !empty($val[$mode]['secret_key']);
        },
        ARRAY_FILTER_USE_BOTH
    );

    // Get rid of `stripe_` and rename `stripe` to `default`
    $keys = array_map(function($key) {
        return $key == 'stripe' ? 'default' : substr($key, 7);
    }, array_keys($stripeAccounts));

    // Only leave public key
    $vals = array_map(function($val) use ($mode) {
        return $val[$mode]['public_key'];
    }, array_values($stripeAccounts));

    return array_combine($keys, $vals);
}

/**
 * Get best localized value for settings that can be either a string
 * or an array with a value per locale
 *
 * @param string|array $setting
 * @param string       $language en|de|...
 * @return string|array|null
 */
function eas_get_localized_value($setting, $language = null)
{
    if (is_string($setting)) {
        return $setting;
    }

    if (is_array($setting) && count($setting) > 0) {
        // Chosse the best translation
        if (empty($language)) {
            $segments = explode('_', get_locale(), 2);
            $language = reset($segments);
        }
        return eas_get($setting[$language], reset($setting));
    } else {
        return null;
    }
}

/**
 * Retruns value if exists, otherwise default
 *
 * @param mixed $var
 * @param mixed $default
 * @return mixed
 */
function eas_get(&$var, $default = null) {
    return isset($var) ? $var : $default;
}

/**
 * Get Ajax endpoint
 *
 * @return string
 */
function eas_get_ajax_endpoint()
{
    return admin_url('admin-ajax.php');
}

/**
 * Takes a CSV string (or array) and returns an array
 *
 * @param string|array $var
 * @return array
 */
function eas_csv_to_array($var)
{
    if (is_array($var)) {
        return $var;
    }

    return array_map('trim', explode(',', $var));
}

/**
 * Check honey pot (email-confirm). This value must be empty.
 *
 * @param array $post
 */
function eas_check_honey_pot($post)
{
    if (!empty($post['email-confirm'])) {
        throw new \Exception('bot');
    }
}

/**
 * Get twig singleton for form emails
 *
 * @param string $form     Form name
 * @param string $language de|en|...
 * @return Twig_Environment
 */
function eas_get_twig($form, $language = null)
{
    if (isset($GLOBALS['eas-twig'])) {
        return $GLOBALS['eas-twig'];
    }

    // Load macros
    $macros = file_get_contents(plugins_url('eas-donation-processor/email_macros.html'));

    // Get settings
    $formSettings      = eas_load_settings($form);
    $confirmationEmail = eas_get_localized_value($formSettings['finish']['email'], $language);
    $isHtml            = eas_get($confirmationEmail['html'], false);
    $twigSettings      = array(
        'finish.email.subject'        => $confirmationEmail['subject'],
        'finish.email.text'           => $macros . ($isHtml ? nl2br($confirmationEmail['text']) : $confirmationEmail['text']),
        'bank_account_formatted_html' => $macros . "{{ _self.dump(bank_account, 'html') }}",
        'bank_account_formatted_text' => $macros . "{{ _self.dump(bank_account, 'text') }}",
    );

    // Instantiate twig
    $loader = new Twig_Loader_Array($twigSettings);
    $twig   = new Twig_Environment($loader, array(
        'autoescape' => $isHtml ? 'html' : false,
    ));

    // Save twig globally
    $GLOBALS['eas-twig'] = $twig;

    return $twig;
}

/**
 * Send out emails
 *
 * @param array  $donation Donation
 */
function eas_send_emails(array $donation)
{
    // Send confirmation email
    eas_send_confirmation_email($donation);

    // Send notification email
    eas_send_notification_email($donation);
}

/**
 * Monoloinguify language labels on level
 *
 * @param array $labels
 * @param int   $depth
 * @return array
 */
function eas_monolinguify(array $labels, $depth = 0)
{
    if (!$depth--) {
        foreach (array_keys($labels) as $key) {
            if (is_array($labels[$key])) {
                $labels[$key] = eas_get_localized_value($labels[$key]);
            }
        }
    } else {
        foreach (array_keys($labels) as $key) {
            if (is_array($labels[$key])) {
                $labels[$key] = eas_monolinguify($labels[$key], $depth);
            }
        }
    }

    return $labels;
}

/**
 * AJAX call for serving tax deduction settings to an *external* instance
 *
 * @return WP_REST_Response
 * @see eas_load_tax_deduction_settings
 * @see eas_get_tax_deduction_settings_by_donation
 */
function eas_serve_tax_deduction_settings()
{
    try {
        $form         = eas_get($_GET['form'], '');
        $formSettings = eas_load_settings($form);
        $response     = new WP_REST_Response(array(
            'success'       => true,
            'tax_deduction' => $formSettings['payment']['labels']['tax_deduction'],
        ));
        $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    } catch (\Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $e->getMessage(),
        ));
    }
}

/**
 * Load tax deduction settings for donation
 *
 * @param array $donation
 * @return array
 * @see eas_serve_tax_deduction_settings
 * @see eas_load_tax_deduction_settings
 */
function eas_get_tax_deduction_settings_by_donation(array $donation)
{
    $settings = array();

    if ($taxDeductionSettings = eas_load_tax_deduction_settings($donation['form'])) {
        $countries = !empty($donation['country']) ? ['default', strtolower($donation['country'])]                    : ['default'];
        $types     = !empty($donation['type'])    ? ['default', str_replace(" ", "", strtolower($donation['type']))] : ['default']; // Payment provider
        $purposes  = !empty($donation['purpose']) ? ['default', $donation['purpose']]                                : ['default'];

        // Find best labels, more specific settings override more general settings
        foreach ($countries as $country) {
            foreach ($types as $type) {
                foreach ($purposes as $purpose) {
                    if (isset($taxDeductionSettings[$country][$type][$purpose])) {
                        $settings = array_merge($settings, $taxDeductionSettings[$country][$type][$purpose]);
                    }
                }
            }
        }

        // Monlinguify settings
        $settings = eas_monolinguify($settings);

        // Get %bank_account_formatted% and insert reference number (if present)
        $form         = eas_get($donation['form'], '');
        $formSettings = eas_load_settings($form);
        if ($donation['type'] == 'Bank Transfer' &&
            $account = eas_localize_array_keys(eas_get($formSettings['payment']['provider']['banktransfer']['accounts'][$donation['account']], array()))
        ) {
            // Insert %reference_number%
            if ($reference = eas_get($donation['reference'])) {
                $settings['bank_account'] = array_map(function ($val) use ($reference) {
                    return str_replace('%reference_number%', $reference, $val);
                }, $account);
            } else {
                $settings['bank_account'] = $account;
            }
        }
    }

    return $settings;
}

/**
 * Load tax deduction settings
 *
 * @param string $form Form name
 * @return array|null
 * @see eas_serve_tax_deduction_settings
 * @see eas_get_tax_deduction_settings_by_donation
 */
function eas_load_tax_deduction_settings($form)
{
    // Get local settings
    $formSettings         = eas_load_settings($form);
    $taxDeductionSettings = eas_get($formSettings['payment']['labels']['tax_deduction'], array());

    // Load remote settings if necessary
    if ('consume' == get_option('tax-deduction-expose') && $remoteUrl = get_option('tax-deduction-remote-url')) {
        $now           = new \DateTime();
        $lastRefreshed = new \DateTime(get_option('tax-deduction-last-refreshed', '1970-01-01'));
        $timeInterval  = $lastRefreshed->diff($now);
        $cacheTtl      = get_option('tax-deduction-cache-ttl', 0);

        try {
            // Get cached remote settings
            $remoteSettings = json_decode(get_option('tax-deduction-remote-settings', array()), true);

            if (!$remoteSettings || $timeInterval->days * 24 + $timeInterval->h > $cacheTtl) {
                $remoteUrl     .= '?form=' . get_option('tax-deduction-remote-form-name', '');
                $remoteContents = json_decode(file_get_contents($remoteUrl), true);
                if ($remoteContents['success'] && is_array($remoteContents['tax_deduction'])) {
                    // Save new settings
                    update_option('tax-deduction-remote-settings', json_encode($remoteContents['tax_deduction'], JSON_PRETTY_PRINT));
                    update_option('tax-deduction-last-refreshed', $now->format(\DateTime::ISO8601));

                    $remoteSettings = $remoteContents['tax_deduction'];
                }
            }
        } catch (\Exception $e) {
            // Serve old settings
            throw new \Exception($e->getMessage());
            $remoteSettings = is_array($remoteSettings) ? $remoteSettings : array();
        }

        // Merge remote and local settings. Local settings override remote settings.
        $taxDeductionSettings = array_replace_recursive($remoteSettings, $taxDeductionSettings);
    }

    return $taxDeductionSettings ? eas_monolinguify($taxDeductionSettings, 3) : null;
}

/**
 * Get bank transfer token
 *
 * @param string $form        Form name
 * @param string $prefix      Constitutes a separate block
 * @param int    $length      Total length, without prefix and hyphens
 * @param int    $blockLength Blocks are separated by a hyphen
 * @param string $separator   Separates blocks
 * @return string
 */
function eas_get_banktransfer_reference($form, $prefix = '', $length = 8, $blockLength = 4, $separator = '-')
{
    $codeAlphabet = "ABCDEFGHJKLMNPQRTWXYZ"; // without I, O, V, U, S
    $codeAlphabet.= "0123456789";
    $max          = strlen($codeAlphabet);
    $token        = "";

    // Generate token
    for ($i = 0; $i < $length; $i++) {
        $token .= $codeAlphabet[rand(0, $max-1)];
    }

    // Chunk split token string
    $tokenArray = str_split($token, $blockLength);

    // Add prefix to token array
    if (!empty($prefix)) {
        // Load settings
        $formSettings = eas_load_settings($form);

        // Check if reference number prefix is defined
        if (
            ($predefinedPrefix = eas_get($formSettings['payment']['reference_number_prefix'][$prefix])) ||
            ($predefinedPrefix = eas_get($formSettings['payment']['reference_number_prefix']['default']))
        ) {
            $prefix = $predefinedPrefix;
        }

        array_unshift($tokenArray, strtoupper($prefix));
    }

    return join($separator, $tokenArray);
}

/**
 * Get PayPal API context
 *
 * @param string $form
 * @param string $mode
 * @param bool   $taxReceipt
 * @param string $currency
 * @param string $country
 * @return \PayPal\Rest\ApiContext
 * @throws \Exception
 */
function eas_get_paypal_api_context($form, $mode, $taxReceipt, $currency, $country)
{
    // Get best settings
    $formSettings = eas_load_settings($form);
    $settings     = eas_get_best_payment_provider_settings(
        $formSettings,
        "paypal",
        $mode,
        $taxReceipt,
        $currency,
        $country
    );

    $apiContext = new \PayPal\Rest\ApiContext(
        new \PayPal\Auth\OAuthTokenCredential(
            $settings['client_id'],
            $settings['client_secret']
        )
    );

    if ($mode == 'live') {
        $apiContext->setConfig(array('mode' => 'live'));
    }

    return $apiContext;
}

/**
 * Localize array keys
 *
 * @param array $array
 * @return array
 */
function eas_localize_array_keys(array $array)
{
    $localizedKeys = array_map(function($key) {
        return __($key, "eas-donation-processor");
    }, array_keys($array));

    return array_combine($localizedKeys, array_values($array));
}

/**
 * Clean up donation data, save local posts, send webhooks, send emails
 *
 * @param array $donation
 */
function eas_do_post_donation_actions($donation)
{
    // Clean up donation data
    $cleanDonation = eas_clean_up_donation_data($donation);

    // Save custom posts (if enabled)
    eas_save_custom_posts($cleanDonation);

    // Trigger web hooks
    eas_trigger_webhooks($cleanDonation);

    // Send emails
    eas_send_emails($cleanDonation);
}

/**
 * Merge settings recursively (except numeric arrays)
 *
 * @param array $array
 * @param array $array1
 * @return array
 */
function eas_array_replace_recursive($array, $array1)
{
    $recurse = function($array, $array1) use (&$recurse)
    {
        foreach ($array1 as $key => $value)
        {
            // Create new key in $array, if it is empty or not an array
            if (!isset($array[$key]) || (isset($array[$key]) && !is_array($array[$key]))) {
                $array[$key] = array();
            }

            // Overwrite the value in the base array
            if (is_array($value) && eas_has_string_keys($value)) {
                $value = $recurse($array[$key], $value);
            }
            $array[$key] = $value;
        }

        return $array;
    };

    // Handle the arguments, merge one by one
    $args  = func_get_args();
    $array = $args[0];
    if (!is_array($array)) {
        return $array;
    }
    for ($i = 1; $i < count($args); $i++) {
        if (is_array($args[$i])) {
            $array = $recurse($array, $args[$i]);
        }
    }

    return $array;
  }






