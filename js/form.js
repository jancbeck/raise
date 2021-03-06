/**
 * Settings
 */
var raiseMode                  = raiseDonationConfig.mode;
var userCountry                = raiseDonationConfig.userCountry;
var selectedCurrency           = raiseDonationConfig.selectedCurrency;
var countryCompulsory          = raiseDonationConfig.countryCompulsory;
var currencies                 = wordpress_vars.amount_patterns;
var currencyMinimums           = wordpress_vars.amount_minimums;
var stripeHandlers             = null;
var totalItems                 = 0;
var taxReceiptNeeded           = false;
var slideTransitionInAction    = false;
var otherAmountPlaceholder     = null;
var currentStripeKey           = '';
var frequency                  = 'once';
var monthlySupport             = ['payment-stripe', 'payment-paypal', 'payment-banktransfer', 'payment-gocardless', 'payment-skrill'];
var goCardlessSupport          = ['EUR', 'GBP', 'SEK'];
var raisePopup                 = null;
var gcPollTimer                = null;
var taxDeductionSuccessText    = null;
var taxDeductionDisabled       = true;
var interactionEventDispatched = false;
var checkoutEventDispatched    = false;


// Preload Stripe image
var stripeImage = new Image();
stripeImage.src = wordpress_vars.logo;

// Define Object keys for old browsers
if (!Object.keys) {
    Object.keys = function (obj) {
        var keys = [],
            k;
        for (k in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, k)) {
                keys.push(k);
            }
        }
        return keys;
    };
}

/**
 * Setup form when DOM ready
 */
jQuery(function($) {
    // Make sure cookies are enabled
    if (!navigator.cookieEnabled) {
        $('<div class="alert alert-danger" role="alert"><span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span><span class="sr-only">Error:</span> ' + wordpress_vars.cookie_warning + '</div>')
            .insertBefore(".btstrp");
    }

    // Dispatch raise_loaded_donation_form event
    raiseTriggerFormLoadedEvent();

    // Stripe setup
    loadStripeHandler();

    // Reload payment provider for current currency
    reloadPaymentProvidersForCurrentCurrency();

    // Reload dropdowns (can be broken depending on theme)
    $('.dropdown-toggle').dropdown();

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip({ container: 'body' }); 

    // Country combobox setup
    $('.combobox', '#wizard').combobox({
        matcher: function (item) {
            return item.toLowerCase().indexOf(this.query.toLowerCase()) == 0;
        },
        appendId: '-auto'
    });

    totalItems = $('#wizard .item').length;
   
    // Some variables that we need
    var drawer = $("#drawer");

    // Page count
    $('button.unconfirm').click(function(event) {
        if (slideTransitionInAction) {
            return false;
        }

        var currentItem = $('#wizard div.active').index();

        if (currentItem  < 1) {
            return false;
        }

        // Go to previous step
        carouselPrev();
    });

    // Prevent interaction during carousel slide
    $('#donation-carousel').on('slide.bs.carousel', function () {
        slideTransitionInAction = true;
    });
    $('#donation-carousel').on('slid.bs.carousel', function () {
        slideTransitionInAction = false;
    });

    // Prevent non-ajax form submission
    $("#donationForm").submit(function(event) {
        event.preventDefault();
    });

    // Unlock form when Raise popup modal is hidden
    $("div.raise-modal").on('hide.bs.modal', function () {
        // No need to unlock form if donation complete
        if (jQuery('button.confirm:last span.glyphicon-ok', '#wizard').length == 0) {
            lockLastStep(false);
        }

        // Close popup (if still open)
        if ($(this).hasClass('raise-popup-modal') && raisePopup && !raisePopup.closed) {
            raisePopup.close();
        }
    });

    // Lock form when Raise popup modal is shown and reset modal contents
    $("div.raise-modal").on('show.bs.modal', function () {
        // Reset modal
        if ($(this).hasClass('raise-popup-modal')) {
            $(this).find('.modal-body .raise_popup_closed').removeClass('hidden');
            $(this).find('.modal-body .raise_popup_open').addClass('hidden');
        }
    });

    // Validation logic is done inside the onBeforeSeek callback
    $('button.confirm').click(function(event) {
        event.preventDefault();
        if (slideTransitionInAction) {
            return false;
        }

        var currentItem = $('div.active', '#wizard').index() + 1;

        // Check contents
        if (currentItem <= totalItems) {
            // Get all fields inside the page, except honey pot (#donor-email-confirm)
            var inputs = $('div.item.active :input', '#wizard').not('#donor-email-confirm');

            // Remove errors
            inputs.siblings('span.raise-error').remove();
            inputs.parent().parent().removeClass('has-error');

            // Get all required fields inside the page, except honey pot (#donor-email-confirm)
            var reqInputs = $('div.item.active .required :input:not(:radio):not(:button)', '#wizard').not('#donor-email-confirm');
            // ... which are empty or invalid
            var errors  = {};
            var empty   = reqInputs.filter(function() {
                return $(this).val().replace(/\s*/g, '') == '';
            });

            // Check invalid input
            var invalid = inputs.filter(function() {
                if ($(this).attr('id') == 'amount-other' && $(this).val() && $(this).val() < currencyMinimums[selectedCurrency]) {
                    errors['amount-other'] = wordpress_vars.error_messages['below_minimum_amount'];
                    return true;
                }

                if ($(this).attr('id') == 'donor-email' && !isValidEmail($(this).val().trim())) {
                    errors['donor-email'] = wordpress_vars.error_messages['invalid_email'];
                    return true;
                }

                return false;
            });

            // Check amount < minimum from misconfiguration
            if (!$('#amount-other', '#wizard').val()) {
                var amount = getDonationAmount();
                if (amount < currencyMinimums[selectedCurrency]) {
                    errors['amount'] = wordpress_vars.error_messages['below_minimum_amount'];
                    invalid.push('amount');
                }
            }

            // Unchecked radio groups (bootstrap drop downs). Add button instead.
            var emptyRadios = $('div.item.active .required:has(:radio):not(:has(:radio:checked))', '#wizard');
            if (emptyRadios.find('button').length) {
                empty = $.merge(empty, emptyRadios.find('button'));
            }

            // If there are empty fields, then
            if (empty.length + invalid.length) {
                // Slide down the drawer
                drawer
                    .text(getErrorMessage(errors))
                    .slideDown();

                // Add a error CSS for empty and invalid fields
                empty = $.unique($.merge(empty, invalid));
                empty.each(function(index) {
                    // Don't add X icon to combobox. It looks bad
                    if ($(this).attr('type') != 'hidden' && $(this).attr('id') != 'donor-country-auto') {
                        if ($(this).attr('id') != 'donor-country') {
                            $(this).parent().append('<span class="raise-error glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span>');
                        }
                        $(this).parent().parent().addClass('has-error');
                        $(this).attr('aria-describedby', 'inputError2Status' + index)
                        $(this).parent().append('<span id="inputError2Status' + index + '" class="raise-error sr-only">(error)</span>');
                    }
                });

                // Cancel seeking of the scrollable by returning false
                return false;
            } else {
                // Everything OK, hide the drawer
                drawer.slideUp();
            }
        }

        // Post data and quit on last page
        if (currentItem >= (totalItems - 1)) {
            // Process form
            var provider = null;
            switch ($('input[name=payment_provider]:checked', '#wizard').attr('id')) {
                case 'payment-stripe':
                    provider = 'stripe';
                    handleStripeDonation();
                    break;
                case 'payment-paypal':
                    provider = 'paypal';
                    handlePayPalDonation();
                    break;
                case 'payment-gocardless':
                    provider = 'gocardless';
                    handlePopupDonation('GoCardless');
                    break;
                case 'payment-bitpay':
                    provider = 'bitpay';
                    handlePopupDonation('BitPay');
                    break;
                case 'payment-skrill':
                    provider = 'skrill';
                    handleIFrameDonation('Skrill');
                    break;
                case 'payment-banktransfer':
                    provider = 'banktransfer';
                    handleBankTransferDonation();
                    break;
                default:
                    // Exit
            }

            // Dispatch raise_initiated_donation event
            if (!checkoutEventDispatched) {
                var ev = new CustomEvent('raise_initiated_donation', { detail: {
                    form: jQuery('#raise-form-name').val(),
                    currency: getDonationCurrencyIsoCode(),
                    amount: getDonationAmount(),
                    payment_provider: provider,
                    purpose: jQuery('input[name=purpose]:checked', '#wizard').val(),
                    account: jQuery('#raise-form-account').val()
                }});
                window.dispatchEvent(ev);
                checkoutEventDispatched = true;
            }

            // Done, wait for callback functions
            return false;
        }

        // Dispatch raise_interacted_with_donation_form event
        if (!interactionEventDispatched) {
            var ev = new CustomEvent('raise_interacted_with_donation_form', { detail: {
                form: jQuery('#raise-form-name').val(),
                currency: getDonationCurrencyIsoCode(),
                amount: getDonationAmount()
            }});
            window.dispatchEvent(ev);
            interactionEventDispatched = true;
        }

        // If we're not at the end, do the following
        if (currentItem == (totalItems - 2)) {
            // On penultimate page load tax deduction labels
            updateTaxDeductionLabels();

            // ... and replace "confirm" with "donate X CHF"
            setTimeout(function() { showLastItem(currentItem) }, 200);
        } else {
            // Otherwise go to next slide
            carouselNext();
        }
    });

    // Expand Recaptcha banner
    $('div.g-recaptcha-overlay', '#wizard').click(function() {
        if($(this).hasClass('expanded')) {
            $(this)
                .removeClass('expanded')
                .css('left', '0')
                .siblings().animate({ width: 70 }, 200);
        } else { 
            $(this)
                .addClass('expanded')
                .css('left', '186px')
                .siblings().animate({ width: 256 }, 200);
        }
    });

    // Click on other amount
    $('input#amount-other').focus(function() {
        if ($(this).hasClass("active")) {
            return;
        }
        $('ul#amounts label').removeClass("active");
        $('ul#amounts input:radio').prop('checked', false);
        if (otherAmountPlaceholder == null) {
            otherAmountPlaceholder = $(this).attr('placeholder');
        }
        $(this).attr('placeholder', '');
        $(this).addClass("active").parent().addClass('required');
        $(this).siblings('span.input-group-addon').addClass('active');
        $(this).siblings('label').addClass('active');
        enableConfirmButton(0);
    }).blur(function() {
        $(this).attr('placeholder', otherAmountPlaceholder);
    }).siblings('span.input-group-addon').click(function() {
        $(this).siblings('input').focus();
    });

    // Other amount formatting 1: Only 0-9 and '.' are valid symbols
    $('input#amount-other').change(function() {
        var value = $(this).val().replace(/[^\d\.]/gm,'');
        $(this).val(value);
    });

    // Other amount formatting 2: Only 0-9 and '.' are valid symbols
    $('input#amount-other').keypress(function(event) {
        var keyCode = event.which;
        if (!(48 <= keyCode && keyCode <= 57) && keyCode != 190 && keyCode != 46 && keyCode != 13) {
            // Only accept numbers, dot, backspace, and enter
            return false;
        }

        // Validate input (workaround for Safari)
        if (keyCode == 13) {
            $('button.confirm:first').click();
            return false;
        }
    });

    // Click on frequency labels
    $('ul#frequency label').click(function() {
        // Make new label active
        $(this).parent().parent().find('label').removeClass('active');
        $(this).addClass('active');
        frequency = $(this).siblings('input').val();

        // Hide payment options that do not support monthly
        var paymentOptions = $('#payment-method-providers label');
        if (frequency == 'monthly') {
            var toHide = 'amount-once';
            var toShow = 'amount-monthly';
            var checked = false;
            paymentOptions.each(function(index) {
                if (monthlySupport.indexOf($(this).attr('for')) == -1) {
                    $(this).addClass('hidden');
                    $(this).find('input').prop('checked', false);
                } else {
                    // Check first possible option
                    if (!checked) {
                        checked = true;
                        $(this).find('input').prop('checked', true);
                    }
                }
            });
        } else {
            var toHide = 'amount-monthly';
            var toShow = 'amount-once';

            // Make all options visible again
            paymentOptions.each(function(index) {
                $(this).removeClass('hidden');
            });

            // Except the ones that don't support the current currency
            reloadPaymentProvidersForCurrentCurrency();
        }

        // Switch buttons if necessary
        var buttonsToShow = $('ul#amounts li.' + toShow);
        if (buttonsToShow.length > 0) {
            // Hide buttons
            $('ul#amounts li.' + toHide)
                .addClass('hidden')
                .find('input')
                .prop('checked', false)
                .prop('disabled', true);

            // Remove active labels
            $('ul#amounts label').removeClass('active');
            
            // Show buttons
            buttonsToShow
                .removeClass('hidden')
                .find('input')
                .prop('disabled', false);

            // Diable next button unless custom field is selected
            if (!$('input#amount-other').hasClass('active')) {
                disableConfirmButton(0);
            }
        }
    });

    // Click on amount label (buttons)
    $('ul#amounts label').click(function() {
        if (slideTransitionInAction) {
            return false;
        }

        // See if already checked
        if ($('input[id=' + $(this).attr('for') +']', '#wizard').prop('checked')) {
            return false;
        }

        // Remove active css class from all items
        $('ul#amounts label').removeClass("active");

        // Handle other input
        var otherInput = $('input#amount-other');
        otherInput.siblings('span.raise-error').remove();
        otherInput
            .val('')
            .removeClass('active')
            .siblings('span.input-group-addon').removeClass('active')
            .parent().removeClass('required')
            .parent().removeClass('has-error')

        // Mark this as active
        $(this).addClass("active");

        // Automatically go to next slide
        enableConfirmButton(0);
        setTimeout(function() { $('button.confirm:first').click() }, 10);
    });

    // Currency stuff
    $('#donation-currency input[name=currency]').change(function() {
        var selectedCurrencyInput = $(this).filter(':checked');
        selectedCurrency          = selectedCurrencyInput.val();

        // Remove old currency
        $('.cur', '#wizard').text('');
        
        // Update and close dropdown
        $('#selected-currency-flag')
            .removeClass()
            .addClass(selectedCurrencyInput.siblings('img').prop('class'))
            .prop('alt', selectedCurrencyInput.siblings('img').prop('alt'));
        $('#selected-currency').text(selectedCurrency);
        $(this).parent().parent().parent().parent().removeClass('open');

        // Set new currency on buttons and on custom input field
        var currencyString = currencies[selectedCurrency];
        $('ul#amounts>li>label').text(
            function(i, val) {
                return currencyString.replace('%amount%', $(this).prev('input').attr('value')); 
            }
        );
        $('ul#amounts span.input-group-addon').text($.trim(currencyString.replace('%amount%', '')));

        // Set new lower bound to other amount field
        var minAmount = currencyMinimums[selectedCurrency];
        jQuery('input#amount-other', '#wizard').prop('min', minAmount);

        // Reload Stripe handler
        loadStripeHandler();

        // Hide GoCardless option if currency is not supported
        reloadPaymentProvidersForCurrentCurrency();
    });

    $('div#payment-method-providers input[type=radio][name=payment_provider]').change(function() {
        // Update tax deduction labels
        updateTaxDeductionLabels();
    });

    // Purpose dropdown stuff
    $('#donation-purpose input[type=radio]').change(function() {
        $('#selected-purpose').text($(this).siblings('span').text());

        // Update tax deduction text
        updateTaxDeductionLabels();
    });

    // Country dropdown stuff
    $('select#donor-country').change(function() {
        // Reload Stripe handler
        var option      = $(this).find('option:selected');
        var countryCode = option.val();

        if (!!countryCode) {
            userCountry = countryCode;

            // Make sure it's displyed correctly (autocomplete may mess with it)
            $('input#donor-country-auto').val(option.text());
            $('input[name=country]', '#wizard').val(countryCode);

            // Update tax deduction labels
            updateTaxDeductionLabels();

            // Reload stripe handlers, trigger later (Chrome bug)
            setTimeout(loadStripeHandler, 10);
        }
    });

    // Tax receipt toggle
    $('input#tax-receipt').change(function() {
        taxReceiptNeeded = $(this).is(':checked');

        // Toggle donor form display and required class
        if (taxReceiptNeeded) {
            $('div#donor-extra-info')
                .slideDown()
                .find('div.optionally-required').addClass('required');
        } else {
            $('div#donor-extra-info')
                .slideUp()
                .find('div.optionally-required').removeClass('required');
        }

        // Reload Stripe settings
        loadStripeHandler();
    });
}); // End jQuery(function($) {})

/**
 * PayPal checkout.js
 */
if (typeof paypal !== 'undefined') {
    paypal.Button.render({
        env: raiseMode == 'sandbox' ? 'sandbox' : 'production',
        commit: true,
        style: {
            label: 'checkout',  // checkout | credit | pay
            size:  'small',     // small | medium | responsive
            shape: 'pill',      // pill | rect
            color: 'blue'       // gold | blue | silver
        },
        // payment() is called when the button is clicked
        payment: function() {
            // Close modal
            jQuery('#PayPalModal').modal('hide');

            // Send form
            return new paypal.Promise(function(resolve, reject) {
                jQuery('form#donationForm').ajaxSubmit({
                    success: function(responseText) {
                        var response = JSON.parse(responseText);
                        if (!('success' in response) || !response['success']) {
                            var message = 'error' in response ? response['error'] : responseText;
                            alert(message);
                            return;
                        }

                        // Resolve payment / billing agreement
                        var token = 'paymentID' in response ? response.paymentID : response.token;
                        resolve(token);
                    },
                    error: function(err) {
                        // Should only happen on internal server error
                        reject(err);

                        // Unlock last step
                        lockLastStep(false);
                    }
                });
            });
        },
        // onAuthorize() is called when the buyer approves the payment
        onAuthorize: function(data) {
            // Show spinner on form
            showSpinnerOnLastButton();

            // Lock last step
            lockLastStep(true);

            // Prepare parameters
            var params = { action: "paypal_execute" };
            if ('paymentID' in data && 'payerID' in data) {
                params.paymentID = data.paymentID;
                params.payerID   = data.payerID;
            } else if ('paymentToken' in data) {
                params.token = data.paymentToken;
            } else {
                alert('An error occured. Donation aborted.');
                lockLastStep(false);
                return;
            }

            // Execute payment / billing agreement
            jQuery.post(wordpress_vars.ajax_endpoint, params)
                .done(function(responseText) {
                    var response = JSON.parse(responseText);
                    if (!('success' in response) || !response['success']) {
                        var message = 'error' in response ? response['error'] : responseText;
                        lockLastStep(false);
                        alert(message);
                        return;
                    }

                    // Everything worked. Show confirmation.
                    showConfirmation('paypal');
                })
                .fail(function(err)  {
                    alert('An error occured: ' + err);
                    lockLastStep(false);
                });
        },
        onCancel: function(data) {
            lockLastStep(false);
        }

    }, '#PayPalPopupButton');
}

/**
 * Auxiliary functions
 */

function isValidEmail(email) {
    var regex = /^([a-zA-Z0-9_.+-])+\@(([a-zA-Z0-9-])+\.)+([a-zA-Z0-9]{2,10})+$/;
    return regex.test(email);
}

function enableConfirmButton(n)
{
    jQuery('button.confirm:eq(' + n + ')').prop('disabled', false);
}

function disableConfirmButton(n)
{
    jQuery('button.confirm:eq(' + n + ')').prop('disabled', true);
}

function getLastButtonText()
{
    var amount           = getDonationAmount();
    var currencyCode     = getDonationCurrencyIsoCode();
    var currencyAmount   = currencies[currencyCode].replace('%amount%', amount);
    var buttonFinalText  = frequency == 'monthly' ? wordpress_vars.donate_button_monthly : wordpress_vars.donate_button_once;
    return buttonFinalText.replace('%currency-amount%', currencyAmount);
}

function showLastItem(currentItem)
{
    // Change text of last confirm button
    jQuery('button.confirm:last', '#wizard').text(getLastButtonText());

    // Go to next slide
    carouselNext();
}

function getDonationAmount()
{
    var amount = jQuery('input[name=amount]:radio:checked', '#wizard').val();
    if (amount) {
        return amount.replace('.00', '');
    } else {
        amount = parseInt(jQuery('input#amount-other', '#wizard').val() * 100) / 100;
        return (amount % 1 == 0) ? amount : amount.toFixed(2);
    }
}

function getDonationCurrencyIsoCode()
{
    return selectedCurrency;
}

/**
 * Handle Stripe donation
 */
function handleStripeDonation()
{
    // Change action input
    jQuery('form#donationForm input[name=action]').val('raise_donate');

    // Open handler
    stripeHandler.open({
        name: wordpress_vars.organization,
        description: wordpress_vars.donation,
        amount: getDonationAmount() * 100,
        currency: getDonationCurrencyIsoCode(),
        email: getDonorInfo('email')
    });
}

function handlePopupDonation(provider)
{
    // Show spinner right away
    showSpinnerOnLastButton();

    // Change action input
    jQuery('form#donationForm input[name=action]').val('raise_redirect');

    // Get sign up URL
    jQuery('form#donationForm').ajaxSubmit({
        success: function(responseText, statusText, xhr, form) {
            try {
                var response = JSON.parse(responseText);
                if (!('success' in response) || !response['success']) {
                    var message = 'error' in response ? response['error'] : responseText;
                    alert(message);
                }

                // Open URL in modal
                jQuery('#' + provider +'PopupButton')
                    .unbind()
                    .click(function() {
                        // Open popup
                        openRaisePopup(response['url'], provider);

                        // Show "continue donation in secure" message on modal
                        jQuery('#' + provider + 'Modal .modal-body .raise_popup_closed').addClass('hidden');
                        jQuery('#' + provider + 'Modal .modal-body .raise_popup_open').removeClass('hidden');

                        // Start poll timer
                        gcPollTimer = window.setInterval(function() {
                            if (raisePopup.closed) {
                                window.clearInterval(gcPollTimer);
                                jQuery('#' + provider + 'Modal').modal('hide');
                            }
                        }, 200);
                    });

                // Show modal
                jQuery('#' + provider + 'Modal').modal('show');
            } catch (err) {
                // Something went wrong, show on confirmation page
                alert(err.message);

                // Enable buttons
                lockLastStep(false);
            }
        },
        error: function(responseText) {
            // Should only happen on internal server error
            try {
                var response = JSON.parse(responseText);
                alert(response.error);
            } catch (err) {
                alert(responseText);
            }
        }
    });

    lockLastStep(true);
}

function handleIFrameDonation(provider)
{
    // Show spinner right away
    showSpinnerOnLastButton();

    // Change action input
    jQuery('form#donationForm input[name=action]').val('raise_redirect');

    // Get sign up URL
    jQuery('form#donationForm').ajaxSubmit({
        success: function(responseText, statusText, xhr, form) {
            try {
                var response = JSON.parse(responseText);
                if (!('success' in response) || !response['success']) {
                    var message = 'error' in response ? response['error'] : responseText;
                    throw new Error(message);
                }

                // Open URL in modal
                jQuery('#' + provider + 'Modal .modal-body').html('<iframe src="' + response.url + '"></iframe>');

                // Show modal
                jQuery('#' + provider + 'Modal').modal('show');
            } catch (err) {
                // Something went wrong, show on confirmation page
                alert(err.message);

                // Enable buttons
                lockLastStep(false);
            }
        },
        error: function(responseText) {
            // Should only happen on internal server error
            try {
                var response = JSON.parse(responseText);
                alert(response.error);
            } catch (err) {
                alert(responseText);
            }
        }
    });

    lockLastStep(true);
}

function hideModal()
{
    jQuery('.raise-modal').modal('hide');
}

function openRaisePopup(url, title)
{
    raisePopup = popupCenter(url, title, 420, 560);
    return false;
}

function popupCenter(url, title, w, h) {
    // Fixes dual-screen position                         Most browsers      Firefox
    var dualScreenLeft = window.screenLeft != undefined ? window.screenLeft : screen.left;
    var dualScreenTop  = window.screenTop  != undefined ? window.screenTop  : screen.top;

    var width  = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
    var height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

    var left      = ((width / 2) - (w / 2)) + dualScreenLeft;
    var top       = ((height / 2) - (h / 2)) + dualScreenTop;
    var newWindow = window.open(url, title, 'scrollbars=yes, width=' + w + ', height=' + h + ', top=' + top + ', left=' + left);

    // Puts focus on the newWindow
    if (window.focus) {
        newWindow.focus();
    }

    return newWindow;
}

function handleBankTransferDonation()
{
    // Show spinner
    showSpinnerOnLastButton();

    // Change action input
    jQuery('form#donationForm input[name=action]').val('raise_donate');

    // Clear confirmation email (honey pot)
    jQuery('#donor-email-confirm').val('');

    if (jQuery('div.g-recaptcha', '#wizard').length) {
        // Get captcha, then send form
        grecaptcha.execute();
    } else {
        // Send form
        sendBanktransferDonation();
    }
}

function sendBanktransferDonation()
{
    // Send form
    jQuery('form#donationForm').ajaxSubmit({
        success: function(responseText, statusText, xhr, form) {
            try {
                var response = JSON.parse(responseText);
                if (!('success' in response) || !response['success']) {
                    var message = 'error' in response ? response['error'] : responseText;
                    throw new Error(message);
                }

                // Update tax deduction success text with reference
                if (taxDeductionSuccessText) {
                    taxDeductionSuccessText = taxDeductionSuccessText.replace('%reference_number%', response['reference']);
                    jQuery('div#shortcode-content').html(taxDeductionSuccessText);
                }

                // Everything worked! Display short code content on confirmation page
                // Change glyphicon from "spinner" to "OK" and go to confirmation page
                showConfirmation('banktransfer');
            } catch (ex) {
                // Something went wrong, show on confirmation page
                alert(ex.message);

                // Enable buttons
                lockLastStep(false);
            }
        }
    });

    // Disable submit button, back button, and payment options
    lockLastStep(true);
}

function handlePayPalDonation()
{
    // Change action input
    jQuery('form#donationForm input[name=action]').val('raise_redirect');

    // Open modal
    jQuery('#PayPalModal').modal('show');
}

function showSpinnerOnLastButton()
{
    jQuery('button.confirm:last', '#wizard')
        .html('<span class="glyphicon glyphicon-refresh glyphicon-refresh-animate" aria-hidden="true"></span>')
        .removeClass('donation-continue');
}

function lockLastStep(locked)
{
    jQuery('#donation-submit').prop('disabled', locked);
    jQuery('#donation-go-back').prop('disabled', locked);
    jQuery('div.donor-info input', '#payment-method-item').prop('disabled', locked);
    jQuery('div.donor-info textarea', '#payment-method-item').prop('disabled', locked);
    jQuery('div.donor-info button', '#payment-method-item').prop('disabled', locked);
    jQuery('input', '#payment-method-providers').prop('disabled', locked);
    jQuery('div.checkbox input', '#payment-method-item').prop('disabled', locked);

    if (!locked) {
        // Make sure tax deduction stays disabled when not possible
        jQuery('#tax-receipt').prop('disabled', taxDeductionDisabled);

        // Restore submit button
        jQuery('button.confirm:last', '#wizard')
            .html(getLastButtonText())
            .addClass('donation-continue');
    }
}

function showConfirmation(paymentProvider)
{
    // Hide all payment provider related divs on confirmation page except the ones from paymentProvider
    jQuery('#payment-method-providers input[name=payment_provider]').each(function(index) {
        var provider = jQuery(this).val().toLowerCase().replace(/\s/g, "");
        if (paymentProvider != provider) {
            jQuery('#shortcode-content .raise-' + provider).hide();
        }
    });

    // Hide all irrelevant country-specific info
    var countryDivs = jQuery('#shortcode-content .raise-country');
    if (userCountry != '') {
        // Hide other countries
        var userCountryCss = '.raise-country-' + userCountry.toLowerCase();
        countryDivs.not(userCountryCss).hide();

        // Show raise-country-other if no divs specific for user country were found
        if (countryDivs.filter(userCountryCss).length == 0) {
            countryDivs.filter('.raise-country-other').show();
        }
    } else {
        // Show raise-country-other
        countryDivs.not('.raise-country-other').hide();
    }

    // Hide spinner
    jQuery('button.confirm:last', '#wizard').html('<span class="glyphicon glyphicon-ok" aria-hidden="true"></span>');
    
    // Move to confirmation page after 1 second
    setTimeout(carouselNext, 1000);

    // Dispatch raise_completed_donation event
    var ev = new CustomEvent('raise_completed_donation', { detail: {
        form: jQuery('#raise-form-name').val(),
        currency: getDonationCurrencyIsoCode(),
        amount: getDonationAmount(),
        payment_provider: paymentProvider,
        purpose: jQuery('input[name=purpose]:checked', '#wizard').val(),
        account: jQuery('#raise-form-account').val()
    }});
    window.dispatchEvent(ev);

    // Update fundraiser widgets if present on the same page
    if (typeof updateFundraiser === 'function') {
        updateFundraiser();
    }
}

function loadStripeHandler()
{
    // Get best matching key
    var stripeSettings = wordpress_vars.stripe_public_keys;
    if (Object.keys(stripeSettings).length == 0) {
        // No Stripe settings for this form
        return;
    }

    // Lock form
    lockLastStep(true);

    // Check all possible settings
    var hasCountrySetting  = userCountry.toLowerCase() in stripeSettings;
    var hasCurrencySetting = selectedCurrency.toLowerCase() in stripeSettings;
    var hasDefaultSetting  = 'default' in stripeSettings;
    
    // Check if there are settings for a country where the chosen currency is used.
    // This is only relevant if the donor does not need a donation receipt (always related 
    // to specific country) and if there are no currency specific settings
    var hasCountryOfCurrencySetting = false;
    var countryOfCurrency           = '';
    if (!countryCompulsory && !taxReceiptNeeded && !hasCurrencySetting) {
        var countries = getCountriesByCurrency(selectedCurrency);
        for (var i = 0; i < countries.length; i++) {
            if (countries[i].toLowerCase() in stripeSettings) {
                hasCountryOfCurrencySetting = true;
                countryOfCurrency = countries[i];
                break;
            }
        }
    }

    if (hasCountrySetting && (taxReceiptNeeded || countryCompulsory)) {
        // Use country specific key
        var newStripeKey = stripeSettings[userCountry.toLowerCase()];
    } else if (hasCurrencySetting) {
        // Use currency specific key
        var newStripeKey = stripeSettings[selectedCurrency.toLowerCase()];
    } else if (hasCountryOfCurrencySetting) {
        // Use key of a country where the chosen currency is used
        var newStripeKey = stripeSettings[countryOfCurrency.toLowerCase()];
    } else if (hasDefaultSetting) {
        // Use default key
        var newStripeKey = stripeSettings['default'];
    } else {
        throw new Error('No Stripe settings found');
    }

    // Check if the key changed
    if (currentStripeKey == newStripeKey) {
        // Unlock form and exit
        lockLastStep(false);
        return;
    }

    // Create new Stripe handler
    stripeHandler = StripeCheckout.configure({
        key: newStripeKey,
        image: wordpress_vars.logo,
        color: '#255A8E',
        locale: 'auto',
        token: function(token) {
            var tokenInput = jQuery('<input type="hidden" name="stripeToken">').val(token.id);
            var keyInput   = jQuery('<input type="hidden" name="stripePublicKey">').val(newStripeKey);

            // Show spinner
            showSpinnerOnLastButton();

            // Send form
            jQuery('form#donationForm').append(tokenInput).append(keyInput).ajaxSubmit({
                success: function(responseText, statusText, xhr, form) {
                    try {
                        var response = JSON.parse(responseText);
                        if (!('success' in response) || !response['success']) {
                            var message = 'error' in response ? response['error'] : responseText;
                            throw new Error(message);
                        }

                        // Everything worked! Change glyphicon from "spinner" to "OK" and go to confirmation page
                        showConfirmation('stripe');
                    } catch (err) {
                        // Something went wrong, show on confirmation page
                        alert(err.message);

                        // Enable buttons
                        lockLastStep(false);
                    }
                }
            });

            // Disable submit button, back button, and payment options
            lockLastStep(true);

            return false;
        }
    });

    // Update currentStripeKey
    currentStripeKey = newStripeKey;

    // Unlock last step
    lockLastStep(false);
}

function carouselNext()
{
    var nextItem = jQuery('#wizard div.active').index() + 1;

    if (nextItem  > totalItems) {
        return false;
    }

    // Move carousel
    jQuery('#donation-carousel').carousel('next');
    
    // Update progress bar
    updateProgressBar(nextItem);
}


function carouselPrev()
{
    var prevItem = jQuery('#wizard div.active').index() - 1;

    if (prevItem  < 0) {
        return false;
    }

    // Move carousel
    jQuery('#donation-carousel').carousel('prev');
    
    // Update progress bar
    updateProgressBar(prevItem);
}

function updateProgressBar(currentItem)
{
    var listItems = jQuery("#progress li");
    listItems.removeClass("active completed");
    listItems.filter(function(index) { return index < currentItem }).addClass("completed");
    listItems.eq(currentItem).addClass("active");

    // Make previous steps clickable, unless we're done
    listItems.unbind('click').removeClass('clickable');
    if (currentItem < totalItems - 1) {
        listItems.slice(0, currentItem).each(function(index) {
            jQuery(this)
                .addClass('clickable')
                .click(function() {
                    // Move carousel
                    jQuery('#donation-carousel').carousel(index);

                    // Update progress bar
                    updateProgressBar(index);
                });
        });
    }
}

function getDonorInfo(name)
{
    return jQuery('input#donor-' + name).val();
}

/**
 * Check if all nested array keys exist. Corresponds to PHP isset()
 */
function checkNestedArray(obj /*, level1, level2, ... levelN*/) {
    var args = Array.prototype.slice.call(arguments, 1);
    
    for (var i = 0; i < args.length; i++) {
        if (!obj || !obj.hasOwnProperty(args[i])) {
            return false;
        }
        obj = obj[args[i]];
    }

    return true;
}

/**
 * Get array with country codes where currency is used
 *
 * E.g. "CHF" returns ["CH", "LI"]
 */
function getCountriesByCurrency(currency)
{
    var mapping = wordpress_vars.currency2country;

    if (currency in mapping) {
        return mapping[currency];
    } else {
        return [];
    }
}

/**
 * Show/hide payment providers
 */
function reloadPaymentProvidersForCurrentCurrency()
{
    // GoCardless
    var gcLabel = jQuery('#payment-method-providers label[for=payment-gocardless]');
    if (goCardlessSupport.indexOf(selectedCurrency) == -1) {
        gcLabel.addClass('hidden');
        gcLabel.find('input').prop('checked', false);
    } else {
        gcLabel.removeClass('hidden');
    }

    // Pre-select first provider
    jQuery('#payment-method-providers label:not(.hidden):first input').prop('checked', true);
}

/**
 * Get tax deduction labels (nested array: country > payment provider > purpose/charity)
 */
function updateTaxDeductionLabels()
{
    var labels = wordpress_vars.tax_deduction_labels;

    // Only proceed if defined
    if (!labels) {
        return;
    }

    var paymentMethod = jQuery('input[name=payment_provider]:checked', '#wizard');
    if (paymentMethod.length) {
        var paymentMethodId   = paymentMethod.attr('id').substr(8); // Strip `payment-` prefix
        var paymentMethodName = paymentMethod.parent().find('span.payment-method-name').text();
    } else {
        var paymentMethodId   = null;
        var paymentMethodName = null;
    }
    var purpose = jQuery('input[name=purpose]:checked', '#wizard');
    if (purpose.length) {
        var purposeId   = purpose.val();
        var purposeName = purpose.parent().text();
    } else {
        var purposeId   = null;
        var purposeName = null;
    }

    // Labels to check
    var countryCodes   = !userCountry     ? ['default'] : ['default', userCountry.toLowerCase()];
    var paymentMethods = !paymentMethodId ? ['default'] : ['default', paymentMethodId.toLowerCase()];
    var purposes       = !purposeId       ? ['default'] : ['default', purposeId];
    var result         = {};

    // Find best labels, more specific settings override more general settings
    for (var i = 0; i < countryCodes.length; i++) {
        for (var j = 0; j < paymentMethods.length; j++) {
            for (var k = 0; k < purposes.length; k++) {
                if (checkNestedArray(labels, countryCodes[i], paymentMethods[j], purposes[k])) {
                    jQuery.extend(result, labels[countryCodes[i]][paymentMethods[j]][purposes[k]]);
                }
            }
        }
    }

    // Update deductible
    var taxReceipt = jQuery('input#tax-receipt');
    if (result.hasOwnProperty('deductible')) {
        taxDeductionDisabled = !result.deductible;

        // Collapse address details if open
        if (taxDeductionDisabled && taxReceipt.is(':checked')) {
            taxReceipt.click();
        }
        taxReceipt.prop('disabled', taxDeductionDisabled);
    }

    // Update receipt text
    if ('receipt_text' in result) {
        taxReceipt.parent().parent().parent().parent().show();
        result.receipt_text = replaceTaxDeductionPlaceholders(result.receipt_text, userCountry, paymentMethodName, purposeName);
        jQuery('span#tax-receipt-text').html(result.receipt_text);
    } else {
        // Hide checkbox
        taxReceipt.prop('checked', false);
        taxReceipt.parent().parent().parent().parent().hide();
    }

    // Update account
    var accountData = {};
    if ('account' in result) {
        jQuery('#raise-form-account').val(result.account);

        // Add account data
        if (result.account in wordpress_vars.bank_accounts && typeof wordpress_vars.bank_accounts[result.account] === 'object') {
            accountData = wordpress_vars.bank_accounts[result.account];
        }
    } else {
        jQuery('#raise-form-account').val('');
    }

    // Update success text with nl2br
    if ('success_text' in result) {
        taxDeductionSuccessText = nl2br(replaceTaxDeductionPlaceholders(result.success_text, userCountry, paymentMethodName, purposeName, accountData));
        jQuery('div#shortcode-content').html(taxDeductionSuccessText);
    }

    // Update provider_hover_text
    var providerLabels = jQuery('#payment-method-providers > label').removeAttr('title');
    if ('provider_hover_text' in result && typeof result.provider_hover_text === 'object') {
        var titles = result.provider_hover_text;
        for (var provider in titles) {
            providerLabels.filter('[for=payment-' + provider + ']').prop('title', titles[provider]);
        }
    }
}

/**
 * Add placeholders
 */
function replaceTaxDeductionPlaceholders(label, country, paymentMethod, purpose, accountData)
{
    // Replace %country%
    if (!!country) {
        label = label.replace('%country%', jQuery('select#donor-country option[value=' + country.toUpperCase() + ']').text());
    }

    // Replace %payment_method%
    if (!!paymentMethod) {
        label = label.replace('%payment_method%', paymentMethod);
    }

    // Replace %purpose%
    if (!!purpose) {
        label = label.replace('%purpose%', purpose);
    }

    // Replace %bank_account_formatted%
    if (!jQuery.isEmptyObject(accountData)) {
        var accountDataString = Object.keys(accountData).map(function(key, index) {
            return '<strong>' + key + '</strong>: ' + accountData[key];
        }).join("\n");
        label = label.replace('%bank_account_formatted%', accountDataString);
    }

    return label;
}

/**
 * nl2br function from PHP
 */
function nl2br(str, isXhtml)
{
    if (typeof str === 'undefined' || str === null) {
        return '';
    }
    // Adjust comment to avoid issue on locutus.io display
    var breakTag = (isXhtml || typeof isXhtml === 'undefined') ? '<br ' + '/>' : '<br>';
    return (str + '').replace(/(\r\n|\n\r|\r|\n)/g, breakTag + '$1');
}

/**
 * Trigger form loaded event
 */
function raiseTriggerFormLoadedEvent()
{
    var ev = new CustomEvent('raise_loaded_donation_form', { detail: {
        form: document.getElementById("raise-form-name").value
    }});
    window.dispatchEvent(ev);
}

/**
 * Show appropriate error message
 */
function getErrorMessage(errors) {
    if ('amount' in errors) {
        var minAmount      = currencyMinimums[selectedCurrency];
        var currencyAmount = currencies[selectedCurrency].replace('%amount%', minAmount);
        return errors['amount'].replace('%minimum_amount%', currencyAmount);
    }

    if ('amount-other' in errors) {
        var minAmount      = currencyMinimums[selectedCurrency];
        var currencyAmount = currencies[selectedCurrency].replace('%amount%', minAmount);
        return errors['amount-other'].replace('%minimum_amount%', currencyAmount);
    }

    if ('donor-email' in errors) {
        return errors['donor-email'];
    }

    return wordpress_vars.error_messages['missing_fields'];
}
