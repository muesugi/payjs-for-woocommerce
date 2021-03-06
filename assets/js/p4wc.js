/* global Payjs, p4wc_info */

// Set API key
Payjs.setPublishableKey( p4wc_info.publishableKey );

jQuery( function ( $ ) {
    var $form = $( 'form.checkout, form#order_review' ),
        savedFieldValues = {},
        $ccForm, $ccNumber, $ccExpiry, $ccCvc;

    function initCCForm () {
        $ccForm   = $( '#p4wc-cc-form' );
        $ccNumber = $ccForm.find( '#p4wc-card-number' );
        $ccExpiry = $ccForm.find( '#p4wc-card-expiry' );
        $ccCvc    = $ccForm.find( '#p4wc-card-cvc' );

        // Hide the CC form if the user has a saved card.
        if ( p4wc_info.hasCard && p4wc_info.savedCardsEnabled ) {
            $ccForm.hide();
        }

        // Toggle new card form
        $form.on( 'change', 'input[name="p4wc_card"]', function () {

            if ( $( 'input[name="p4wc_card"]:checked' ).val() === 'new' ) {
                $ccForm.slideDown( 200 );
            } else {
                $ccForm.slideUp( 200 );
            }
        });

        // Add in lost data
        if ( savedFieldValues.number ) {
            $ccNumber.val( savedFieldValues.number.val ).attr( 'class', savedFieldValues.number.classes );
        }

        if ( savedFieldValues.expiry ) {
            $ccExpiry.val( savedFieldValues.expiry.val );
        }

        if ( savedFieldValues.cvc ) {
            $ccCvc.val( savedFieldValues.cvc.val );
        }
    }

    function payjsFormHandler () {
        if ( $( '#payment_method_p4wc' ).is( ':checked' ) && ( ! $( 'input[name="p4wc_card"]' ).length || $( 'input[name="p4wc_card"]:checked' ).val() === 'new' ) ) {

            if ( ! $( 'input.payjs_token' ).length ) {
                var cardExpiry = $ccExpiry.payment( 'cardExpiryVal' ),
                    name = ( $( '#billing_first_name' ).val() || $( '#billing_last_name' ).val() ) ? $( '#billing_first_name' ).val() + ' ' + $( '#billing_last_name' ).val() : p4wc_info.billing_name;

                var payjsData = {
                    number          : $ccNumber.val() || '',
                    cvc             : $ccCvc.val() || '',
                    exp_month       : cardExpiry.month || '',
                    exp_year        : cardExpiry.year || '',
                    name            : $( '.p4wc-billing-name' ).val() || name || '',
                    address_line1   : $( '#billing_address_1' ).val() || p4wc_info.billing_address_1 || '',
                    address_line2   : $( '#billing_address_2' ).val() || p4wc_info.billing_address_2 || '',
                    address_city    : $( '#billing_city' ).val() || p4wc_info.billing_city || '',
                    address_state   : $( '#billing_state' ).val() || p4wc_info.billing_state || '',
                    address_zip     : $( '.p4wc-billing-zip' ).val() || $( '#billing_postcode' ).val() || p4wc_info.billing_postcode || '',
                    address_country : $( '#billing_country' ).val() || p4wc_info.billing_country || ''
                };

                // Validate form fields, create token if form is valid
                if ( payjsFormValidator( payjsData ) ) {
                    Payjs.createToken( payjsData, payjsResponseHandler );
                    return false;
                }
            }
        }

        return true;
    }

    function payjsResponseHandler ( status, response ) {

        if ( response.error ) {
            // show the errors on the form
            $( '.payment-errors, .payjs_token, .form_errors' ).remove();
            $ccForm.before( '<span class="payment-errors required">' + response.error.message + '</span>' );

        } else {
            // insert the token into the form so it gets submitted to the server
            $form.append( '<input type="hidden" class="payjs_token" name="payjs_token" value="' + response.id + '"/>' );

            // tell the server if we want to save the card
            var $ccSave = $( '#p4wc-cc-form #p4wc-save-card' ).prop('checked');
            $form.append( '<input type="hidden" class="save_card" name="save_card" value="' + $ccSave + '"/>' );
            $form.submit();
        }
    }

    function payjsFormValidator ( payjsData ) {

        // Validate form fields
        var errors = fieldValidator( payjsData );

        // If there are errors, display them using wc_add_notice on the backend
        if ( errors.length ) {

            $( '.payjs_token, .form_errors' ).remove();

            for ( var i = 0, len = errors.length; i < len; i++ ) {
                var field = errors[i].field,
                    type  = errors[i].type;

                $form.append( '<input type="hidden" class="form_errors" name="' + field + '" value="' + type + '">' );
            }

            $form.append( '<input type="hidden" class="form_errors" name="form_errors" value="1">' );

            return false;
        }

        // Create the token if we don't have any errors
        else {
            // Clear out notices
            $form.find( '.woocommerce-error' ).remove();

            return true;
        }
    }

    function fieldValidator ( payjsData ) {
        var errors = [];

        // Card number validation
        if ( ! payjsData.number ) {
            errors.push({
                'field' : 'p4wc-card-number',
                'type'  : 'undefined'
            });
        } else if ( ! $.payment.validateCardNumber( payjsData.number ) ) {
            errors.push({
                'field' : 'p4wc-card-number',
                'type'  : 'invalid'
            });
        }

        // Card expiration validation
        if ( ! payjsData.exp_month || ! payjsData.exp_year ) {
            errors.push({
                'field' : 'p4wc-card-expiry',
                'type'  : 'undefined'
            });
        } else if ( ! $.payment.validateCardExpiry( payjsData.exp_month, payjsData.exp_year ) ) {
            errors.push({
                'field' : 'p4wc-card-expiry',
                'type'  : 'invalid'
            });
        }

        // Card CVC validation
        if ( ! payjsData.cvc ) {
            errors.push({
                'field' : 'p4wc-card-cvc',
                'type'  : 'undefined'
            });
        } else if ( ! $.payment.validateCardCVC( payjsData.cvc, $.payment.cardType( payjsData.number ) ) ) {
            errors.push({
                'field' : 'p4wc-card-cvc',
                'type'  : 'invalid'
            });
        }

        // Send the errors back
        return errors;
    }

    // Make sure the credit card form exists before we try working with it
    $( 'body' ).on( 'updated_checkout.p4wc', initCCForm ).trigger( 'updated_checkout.p4wc' );

    // Checkout Form
    $( 'form.checkout' ).on( 'checkout_place_order', payjsFormHandler );

    // Pay Page Form
    $( 'form#order_review' ).on( 'submit', payjsFormHandler );

    // Both Forms
    $form.on( 'keyup change', '#p4wc-card-number, #p4wc-card-expiry, #p4wc-card-cvc, input[name="p4wc_card"], input[name="payment_method"]', function () {

        // Save credit card details in case the address changes (or something else)
        savedFieldValues.number = {
            'val'     : $ccNumber.val(),
            'classes' : $ccNumber.attr( 'class' )
        };
        savedFieldValues.expiry = {
            'val' : $ccExpiry.val()
        };
        savedFieldValues.cvc = {
            'val' : $ccCvc.val()
        };

        $( '.woocommerce_error, .woocommerce-error, .woocommerce-message, .woocommerce_message, .payjs_token, .form_errors' ).remove();
    });
});
