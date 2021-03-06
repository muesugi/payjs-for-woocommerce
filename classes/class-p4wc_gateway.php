<?php
/**
 * Pay.js Gateway
 *
 * Provides a Pay.js Payment Gateway.
 *
 * @class       P4WC_Gateway
 * @extends     WC_Payment_Gateway
 * @package     WooCommerce/Classes/Payment
 * @author      Moie Uesugi
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class P4WC_Gateway extends WC_Payment_Gateway {
    protected $order                     = null;
    protected $form_data                 = null;
    protected $transaction_id            = null;
    protected $transaction_error_message = null;

    public function __construct() {
        global $p4wc;

        $this->id           = 'p4wc';
        $this->method_title = 'Pay.js for WooCommerce';
        $this->has_fields   = true;
        $this->supports     = array(
            'default_credit_card_form',
            'products',
            'refunds'
        );

        // Init settings
        $this->init_form_fields();
        $this->init_settings();

        // Use settings
        $this->enabled     = $this->settings['enabled'];
        $this->title       = $this->settings['title'];
        $this->description = $this->settings['description'];

        // Get current user information
        $this->stripe_customer_info = get_user_meta( get_current_user_id(), $p4wc->settings['stripe_db_location'], true ); //*****

        // Add an icon with a filter for customization
        $icon_url = apply_filters( 'p4wc_icon_url', plugins_url( 'assets/images/credits.png', dirname(__FILE__) ) ); //*****
        if ( $icon_url ) {
            $this->icon = $icon_url;
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );
        add_action( 'woocommerce_credit_card_form_start', array( $this, 'before_cc_form' ) );
        add_action( 'woocommerce_credit_card_form_end', array( $this, 'after_cc_form' ) );
    }

    /**
     * Check if this gateway is enabled and all dependencies are fine.
     * Disable the plugin if dependencies fail.
     *
     * @access      public
     * @return      bool
     */
    public function is_available() {
        global $p4wc;

        if ( $this->enabled === 'no' ) {
            return false;
        }

        // Stripe won't work without keys //neither will Pay.js
        if ( ! $p4wc->settings['publishable_key'] && ! $p4wc->settings['secret_key'] ) {
            return false;
        }

        // Disable plugin if we don't use ssl ///might want to disable this for testing.
        if ( ! is_ssl() && $this->settings['testmode'] === 'no' ) {
            return false;
        }

        // Allow smaller orders to process for WooCommerce Bookings
        if ( is_checkout_pay_page() ) {
            $order_key = urldecode( $_GET['key'] );
            $order_id  = absint( get_query_var( 'order-pay' ) );
            $order     = new WC_Order( $order_id );

            if ( $order->id == $order_id && $order->order_key == $order_key && $this->get_order_total() * 100 < 50) {
                return false;
            }
        }

        // Stripe will only process orders of at least 50 cents otherwise
        elseif ( $this->get_order_total() * 100 < 50 ) {
            return false;
        }

        return true;
    }

    /**
     * Send notices to users if requirements fail, or for any other reason
     *
     * @access      public
     * @return      bool
     */
    public function admin_notices() {
        global $p4wc, $pagenow, $wpdb;

        if ( $this->enabled == 'no') {
            return false;
        }

        // Check for API Keys
        if ( ! $p4wc->settings['publishable_key'] && ! $p4wc->settings['secret_key'] ) {
            echo '<div class="error"><p>' . __( 'Pay.jp needs API Keys to work, please find your secret and publishable keys in the <a href="https://pay.jp/dashboard/settings#api-key" target="_blank">Pay.jp accounts section</a>.', 'payjp-for-woocommerce' ) . '</p></div>';
            return false;
        }

        // Force SSL on production
        if ( $this->settings['testmode'] == 'no' && get_option( 'woocommerce_force_ssl_checkout' ) == 'no' ) {
            echo '<div class="error"><p>' . __( 'Stripe needs SSL in order to be secure. Read mode about forcing SSL on checkout in <a href="http://docs.woothemes.com/document/ssl-and-https/" target="_blank">the WooCommerce docs</a>.', 'payjp-for-woocommerce' ) . '</p></div>';
            return false;
        }

        // Add notices for admin page
        if ( $pagenow === 'admin.php' ) {
            $options_base = 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( get_class( $this ) );

            if ( ! empty( $_GET['action'] ) && ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'p4wc_action' ) ) {

                // Delete all test data
                if ( $_GET['action'] === 'delete_test_data' ) {

                    // Delete test data if the action has been confirmed
                    if ( ! empty( $_GET['confirm'] ) && $_GET['confirm'] === 'yes' ) {

                        $result = $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_stripe_test_customer_info' ) );

                        if ( $result !== false ) :
                            ?>
                            <div class="updated">
                                <p><?php _e( 'Stripe Test Data successfully deleted.', 'payjp-for-woocommerce' ); ?></p>
                            </div>
                            <?php
                        else :
                            ?>
                            <div class="error">
                                <p><?php _e( 'Unable to delete Stripe Test Data', 'payjp-for-woocommerce' ); ?></p>
                            </div>
                            <?php
                        endif;
                    }

                    // Ask for confimation before we actually delete data
                    else {
                        ?>
                        <div class="error">
                            <p><?php _e( 'Are you sure you want to delete all test data? This action cannot be undone.', 'payjp-for-woocommerce' ); ?></p>
                            <p>
                                <a href="<?php echo wp_nonce_url( admin_url( $options_base . '&action=delete_test_data&confirm=yes' ), 'p4wc_action' ); ?>" class="button"><?php _e( 'Delete', 'payjp-for-woocommerce' ); ?></a>
                                <a href="<?php echo admin_url( $options_base ); ?>" class="button"><?php _e( 'Cancel', 'payjp-for-woocommerce' ); ?></a>
                            </p>
                        </div>
                        <?php
                    }
                }
            }
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access      public
     * @return      void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'type'          => 'checkbox',
                'title'         => __( 'Enable/Disable', 'payjs-for-woocommerce' ),
                'label'         => __( 'Enable Pay.js for WooCommerce', 'payjs-for-woocommerce' ),
                'default'       => 'yes'
            ),
            'title' => array(
                'type'          => 'text',
                'title'         => __( 'Title', 'payjs-for-woocommerce' ),
                'description'   => __( 'This controls the title which the user sees during checkout.', 'payjs-for-woocommerce' ),
                'default'       => __( 'Credit Card Payment', 'payjs-for-woocommerce' )
            ),
            'description' => array(
                'type'          => 'textarea',
                'title'         => __( 'Description', 'payjs-for-woocommerce' ),
                'description'   => __( 'This controls the description which the user sees during checkout.', 'payjs-for-woocommerce' ),
                'default'       => '',
            ),
            'charge_type' => array(
                'type'          => 'select',
                'title'         => __( 'Charge Type', 'payjs-for-woocommerce' ),
                'description'   => __( 'Choose to capture payment at checkout, or authorize only to capture later.', 'payjs-for-woocommerce' ),
                'options'       => array(
                    'capture'   => __( 'Authorize & Capture', 'payjs-for-woocommerce' ),
                    'authorize' => __( 'Authorize Only', 'payjs-for-woocommerce' )
                ),
                'default'       => 'capture'
            ),
            'additional_fields' => array(
                'type'          => 'checkbox',
                'title'         => __( 'Additional Fields', 'payjs-for-woocommerce' ),
                'description'   => __( 'Add a Billing ZIP and a Name on Card for Stripe authentication purposes. This is only neccessary if you check the "Only ship to the users billing address" box on WooCommerce Shipping settings.', 'payjp-for-woocommerce' ),
                'label'         => __( 'Use Additional Fields', 'payjs-for-woocommerce' ),
                'default'       => 'no'
            ),
            'saved_cards' => array(
                'type'          => 'checkbox',
                'title'         => __( 'Saved Cards', 'payjs-for-woocommerce' ),
                'description'   => __( 'Allow customers to use saved cards for future purchases.', 'payjs-for-woocommerce' ),
                'default'       => 'yes',
            ),
            /*'testmode' => array(
                'type'          => 'checkbox',
                'title'         => __( 'Test Mode', 'payjs-for-woocommerce' ),
                'description'   => __( 'Use the test mode on Stripe\'s dashboard to verify everything works before going live.', 'payjs-for-woocommerce' ),
                'label'         => __( 'Turn on testing', 'payjs-for-woocommerce' ),
                'default'       => 'no'
            ),*/
            'test_secret_key'   => array(
                'type'          => 'text',
                'title'         => __( 'Pay.js API Test Secret key', 'payjs-for-woocommerce' ),
                'default'       => '',
            ),
            'test_publishable_key' => array(
                'type'          => 'text',
                'title'         => __( 'Pay.js API Test Publishable key', 'payjs-for-woocommerce' ),
                'default'       => '',
            ),
            'live_secret_key'   => array(
                'type'          => 'text',
                'title'         => __( 'Pay.js API Live Secret key', 'payjs-for-woocommerce' ),
                'default'       => '',
            ),
            'live_publishable_key' => array(
                'type'          => 'text',
                'title'         => __( 'Pay.js API Live Publishable key', 'payjs-for-woocommerce' ),
                'default'       => '',
            ),
        );
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @access      public
     * @return      void
     */
    public function admin_options() {

        $options_base = 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( get_class( $this ) );
        ?>
        <h3><?php echo ( ! empty( $this->method_title ) ) ? $this->method_title : __( 'Settings', 'woocommerce' ) ; ?></h3>
        <p><?php _e( 'Allows Credit Card payments through <a href="https://pay.jp/">Pay.jp</a>. You can find your API Keys in your <a href="https://pay.jp/dashboard/settings#api-key">Pay.jp Account Settings</a>.', 'payjp-for-woocommerce' ); ?></p>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            <tr>
                <th><?php _e( 'Delete Pay.jp Test Data', 'payjp-for-woocommerce' ); ?></th>
                <td>
                    <p>
                        <a href="<?php echo wp_nonce_url( admin_url( $options_base . '&action=delete_test_data' ), 'p4wc_action' ); ?>" class="button"><?php _e( 'Delete all Test Data', 'payjp-for-woocommerce' ); ?></a>
                        <span class="description"><?php _e( '<strong class="red">Warning:</strong> This will delete all Pay.jp test customer data, make sure to back up your database.', 'payjp-for-woocommerce' ); ?></span>
                    </p>
                </td>
            </tr>
        </table>

        <?php
    }

    /**
     * Load dependent scripts
     * - pay.jp js from the pay.jp servers
     * - p4wc.js for handling the data to submit to stripe
     *
     * @access      public
     * @return      void
     */
    public function load_scripts() {
        global $p4wc;

        // Main stripe js
        wp_enqueue_script( 'payjs', 'https://js.pay.jp', false, '2.0', true );

        // Plugin js
        wp_enqueue_script( 'p4wc_js', plugins_url( 'assets/js/p4wc.js', dirname( __FILE__ ) ), array( 'stripe', 'wc-credit-card-form' ), '1.36', true );
        //was minifed before

        // Add data that p4wc.js needs
        $p4wc_info = array(
            'publishableKey'    => $p4wc->settings['publishable_key'],
            'savedCardsEnabled' => $p4wc->settings['saved_cards'] === 'yes' ? true : false,
            'hasCard'           => ( $this->stripe_customer_info && count( $this->stripe_customer_info['cards'] ) ) ? true : false
        );

        // If we're on the pay page, Stripe needs the address
        if ( is_checkout_pay_page() ) {
            $order_key = urldecode( $_GET['key'] );
            $order_id  = absint( get_query_var( 'order-pay' ) );
            $order     = new WC_Order( $order_id );

            if ( $order->id == $order_id && $order->order_key == $order_key ) {
                $p4wc_info['billing_name']      = $order->billing_first_name . ' ' . $order->billing_last_name;
                $p4wc_info['billing_address_1'] = $order->billing_address_1;
                $p4wc_info['billing_address_2'] = $order->billing_address_2;
                $p4wc_info['billing_city']      = $order->billing_city;
                $p4wc_info['billing_state']     = $order->billing_state;
                $p4wc_info['billing_postcode']  = $order->billing_postcode;
                $p4wc_info['billing_country']   = $order->billing_country;
            }
        }

        wp_localize_script( 'p4wc_js', 'p4wc_info', $p4wc_info );
    }

    /**
     * Add additional fields just above the credit card form
     *
     * @access      public
     * @param       string $gateway_id
     * @return      void
     */
    public function before_cc_form( $gateway_id ) {
        global $p4wc;

        // Ensure that we're only outputting this for the p4wc gateway
        if ( $gateway_id !== $this->id ) {
            return;
        }

        // These form fields are optional, so we should respect that
        if ( $p4wc->settings['additional_fields'] !== 'yes' ) {
            return;
        }

        woocommerce_form_field( 'billing-name', array(
            'label'             => __( 'Name on Card', 'payjp-for-woocommerce' ),
            'required'          => true,
            'class'             => array( 'form-row-first' ),
            'input_class'       => array( 'p4wc-billing-name' ),
            'custom_attributes' => array(
                'autocomplete'  => 'off',
            ),
        ) );

        woocommerce_form_field( 'billing-zip', array(
            'label'             => __( 'Billing Zip', 'payjp-for-woocommerce' ),
            'required'          => true,
            'class'             => array( 'form-row-last' ),
            'input_class'       => array( 'p4wc-billing-zip' ),
            'clear'             => true,
            'custom_attributes' => array(
                'autocomplete'  => 'off',
            ),
        ) );
    }

    /**
     * Add an option to save card details after the form
     *
     * @access      public
     * @param       string $gateway_id
     * @return      void
     */
    public function after_cc_form( $gateway_id ) {
        global $p4wc;

        // Ensure that we're only outputting this for the p4wc gateway
        if ( $gateway_id !== $this->id ) {
            return;
        }

        // This form field is optional, so we should respect that
        if ( $p4wc->settings['saved_cards'] !== 'yes' ) {
            return;
        }

        woocommerce_form_field( 'p4wc_save_card', array(
            'type'              => 'checkbox',
            'label'             => __( 'Save Card Details For Later', 'payjp-for-woocommerce' ),
            'class'             => array( 'form-row-wide' ),
            'input_class'       => array( 'p4wc-save-card' ),
            'custom_attributes' => array(
                'autocomplete'  => 'off'
            ),
        ) );
    }

    /**
     * Output payment fields, optional additional fields and woocommerce cc form
     *
     * @access      public
     * @return      void
     */
    public function payment_fields() {

        // Output the saved card data
        p4wc_get_template( 'payment-fields.php' );

        // Output WooCommerce 2.1+ cc form
        $this->credit_card_form( array(
            'fields_have_names' => false,
        ) );
    }

    /**
     * Validate credit card form fields
     *
     * @access      public
     * @return      void
     */
    public function validate_fields() {

        $form_fields = array(
            'card-number' => array(
                'name'       => __( 'Credit Card Number', 'payjp-for-woocommerce' ),
                'error_type' => isset( $_POST['p4wc-card-number'] ) ? $_POST['p4wc-card-number'] : null,
            ),
            'card-expiry' => array(
                'name'       => __( 'Credit Card Expiration', 'payjp-for-woocommerce' ),
                'error_type' => isset( $_POST['p4wc-card-expiry'] ) ? $_POST['p4wc-card-expiry'] : null,
            ),
            'card-cvc'    => array(
                'name'       => __( 'Credit Card CVC', 'payjp-for-woocommerce' ),
                'error_type' => isset( $_POST['p4wc-card-cvc'] ) ? $_POST['p4wc-card-cvc'] : null,
            ),
        );

        foreach ( $form_fields as $form_field ) {

            if ( ! empty( $form_field['error_type'] ) ) {
                wc_add_notice( $this->get_form_error_message( $form_field['name'], $form_field['error_type'] ), 'error' );
            }
        }
    }

    /**
     * Get error message for form validator given field name and type of error
     *
     * @access      protected
     * @param       string $field_name
     * @param       string $error_type
     * @return      string
     */
    protected function get_form_error_message( $field_name, $error_type = 'undefined' ) {

        if ( $error_type === 'invalid' ) {
            return sprintf( __( 'Please enter a valid %s.', 'payjp-for-woocommerce' ), "<strong>$field_name</strong>" );
        } else {
            return sprintf( __( '%s is a required field.', 'payjp-for-woocommerce' ), "<strong>$field_name</strong>" );
        }
    }

    /**
     * Process the payment and return the result
     *
     * @access      public
     * @param       int $order_id
     * @return      array
     */
    public function process_payment( $order_id ) {

        if ( $this->send_to_stripe( $order_id ) ) {
            $this->order_complete();

            $result = array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $this->order )
            );

            return $result;
        } else {
            $this->payment_failed();

            // Add a generic error message if we don't currently have any others
            if ( wc_notice_count( 'error' ) == 0 ) {
                wc_add_notice( __( 'Transaction Error: Could not complete your payment.', 'payjp-for-woocommerce' ), 'error' );
            }
        }
    }

    /**
     * Process refund
     *
     * Overriding refund method
     *
     * @access      public
     * @param       int $order_id
     * @param       float $amount
     * @param       string $reason
     * @return      mixed True or False based on success, or WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {

        $this->order = new WC_Order( $order_id );
        $this->transaction_id = $this->order->get_transaction_id();

        if ( ! $this->transaction_id ) {
            return new WP_Error( 'p4wc_refund_error',
                sprintf(
                    __( '%s Credit Card Refund failed because the Transaction ID is missing.', 'payjp-for-woocommerce' ),
                    get_class( $this )
                )
            );
        }

        try {

            $refund_data = array();

            // If the amount is set, refund that amount, otherwise the entire amount is refunded
            if ( $amount ) {
                $refund_data['amount'] = $amount * 100;
            }

            // If a reason is provided, add it to the Stripe metadata for the refund
            if ( $reason ) {
                $refund_data['metadata']['reason'] = $reason;
            }

            // Send the refund to the Stripe API
            return P4WC_API::create_refund( $this->transaction_id, $refund_data );

        } catch ( Exception $e ) {
            $this->transaction_error_message = $p4wc->get_error_message( $e );

            $this->order->add_order_note(
                sprintf(
                    __( '%s Credit Card Refund Failed with message: "%s"', 'payjp-for-woocommerce' ),
                    get_class( $this ),
                    $this->transaction_error_message
                )
            );

            // Something failed somewhere, send a message.
            return new WP_Error( 'p4wc_refund_error', $this->transaction_error_message );
        }

        return false;
    }

    /**
     * Send form data to Stripe
     * Handles sending the charge to an existing customer, a new customer (that's logged in), or a guest
     *
     * @access      protected
     * @param       int $order_id
     * @return      bool
     */
    protected function send_to_stripe( $order_id ) {
        global $p4wc;

        // Get the order based on order_id
        $this->order = new WC_Order( $order_id );

        // Get the credit card details submitted by the form
        $this->form_data = $this->get_form_data();

        // If there are errors on the form, don't bother sending to Stripe.
        if ( $this->form_data['errors'] == 1 ) {
            return;
        }

        // Set up the charge for Stripe's servers
        try {

            // Allow for any type of charge to use the same try/catch config
            $this->charge_set_up();

            // Save data for the "Capture"
            update_post_meta( $this->order->id, '_p4wc_capture', strcmp( $this->settings['charge_type'], 'authorize' ) == 0 );

            // Save Stripe fee
            if ( isset( $this->charge->balance_transaction ) && isset( $this->charge->balance_transaction->fee ) ) {
                $stripe_fee = number_format( $this->charge->balance_transaction->fee / 100, 2, '.', '' );
                update_post_meta( $this->order->id, 'Stripe Fee', $stripe_fee );
            }

            return true;

        } catch ( Exception $e ) {

            // Stop page reload if we have errors to show
            unset( WC()->session->reload_checkout );

            $this->transaction_error_message = $p4wc->get_error_message( $e );

            wc_add_notice( __( 'Error:', 'payjp-for-woocommerce' ) . ' ' . $this->transaction_error_message, 'error' );

            return false;
        }
    }

    /**
     * Create a customer if the current user isn't already one
     * Retrieve a customer if one already exists
     * Add a card to a customer if necessary
     *
     * @access      protected
     * @return      array
     */
    protected function get_customer() {
        global $p4wc;

        $output = array();
        $customer_info = get_user_meta( $this->order->user_id, $p4wc->settings['stripe_db_location'], true );

        if ( $customer_info ) {

            // If the user is already registered on the stripe servers, retreive their information
            $customer = P4WC_API::get_customer( $customer_info['customer_id'] );

            // If the user doesn't have cards or is adding a new one
            if ( $this->form_data['chosen_card'] === 'new' ) {

                // Add new card on stripe servers and make default
                $card = P4WC_API::update_customer( $customer_info['customer_id'] . '/cards', array(
                    'card' => $this->form_data['token']
                ) );

                // Add new customer details to database
                P4WC_DB::update_customer( $this->order->user_id, array(
                    'customer_id'  => $customer->id,
                    'card'         => array(
                        'id'        => $card->id,
                        'brand'     => $card->brand,
                        'last4'     => $card->last4,
                        'exp_month' => $card->exp_month,
                        'exp_year'  => $card->exp_year,
                    ),
                    'default_card' => $card->id
                ) );

                $output['card'] = $card->id;
            } else {
                $output['card'] = $customer_info['cards'][ intval( $this->form_data['chosen_card'] ) ]['id'];
            }

        } else {

            $user = get_userdata( $this->order->user_id );

            // Allow options to be set without modifying sensitive data like token, email, etc
            $customer_data = apply_filters( 'p4wc_customer_data', array(), $this->form_data, $this->order );

            // Set default customer description
            $customer_description = $user->user_login . ' (#' . $this->order->user_id . ' - ' . $user->user_email . ') ' . $this->form_data['customer']['name']; // username (user_id - user_email) Full Name

            // Set up basics for customer
            $customer_data['description'] = apply_filters( 'p4wc_customer_description', $customer_description, $this->form_data, $this->order );
            $customer_data['email']       = $this->form_data['customer']['billing_email'];
            $customer_data['card']        = $this->form_data['token'];

            // Create the customer in the api with the above data
            $customer = P4WC_API::create_customer( $this->order->user_id, $customer_data );

            $output['card'] = $customer->default_source;
        }

        // Set up charging data to include customer information
        $output['customer_id'] = $customer->id;

        // Save data for cross-reference between Stripe Dashboard and WooCommerce
        update_post_meta( $this->order->id, 'Stripe Customer Id', $customer->id );

        return $output;
    }

    /**
     * Get the charge's description
     *
     * @access      protected
     * @param       string $type Type of product being bought
     * @return      string
     */
    protected function get_charge_description( $type = 'simple' ) {
        $order_items = $this->order->get_items();

        // Set a default name, override with a product name if it exists for Stripe's dashboard
        if ( $type === 'subscription' ) {
            $product_name = __( 'Subscription', 'payjp-for-woocommerce' );
        } else {
            $product_name = __( 'Purchases', 'payjp-for-woocommerce' );
        }

        // Grab first viable product name and use it
        foreach ( $order_items as $key => $item ) {

            if ( $type === 'subscription' && isset( $item['subscription_status'] ) ) {
                $product_name = $item['name'];
                break;
            } elseif ( $type === 'simple' ) {
                $product_name = $item['name'];
                break;
            }
        }

        // Charge description
        $charge_description = sprintf(
            __( 'Payment for %s (Order: %s)', 'payjp-for-woocommerce' ),
            $product_name,
            $this->order->get_order_number()
        );

        if ( $type === 'subscription' ) {
            return apply_filters( 'p4wc_subscription_charge_description', $charge_description, $this->order );
        } else {
            return apply_filters( 'p4wc_charge_description', $charge_description, $this->form_data, $this->order );
        }
    }

    /**
     * Mark the payment as failed in the order notes
     *
     * @access      protected
     * @return      void
     */
    protected function payment_failed() {
        $this->order->add_order_note(
            sprintf(
                __( '%s payment failed with message: "%s"', 'payjp-for-woocommerce' ),
                get_class( $this ),
                $this->transaction_error_message
            )
        );
    }

    /**
     * Mark the payment as completed in the order notes
     *
     * @access      protected
     * @return      void
     */
    protected function order_complete() {

        if ( $this->order->status == 'completed' ) {
            return;
        }

        $this->order->payment_complete( $this->transaction_id );

        $this->order->add_order_note(
            sprintf(
                __( '%s payment completed with Transaction Id of "%s"', 'payjp-for-woocommerce' ),
                get_class( $this ),
                $this->transaction_id
            )
        );
    }

    /**
     * Retrieve the form fields
     *
     * @access      protected
     * @return      mixed
     */
    protected function get_form_data() {

        if ( $this->order && $this->order != null ) {
            return array(
                'amount'      => $this->get_order_total() * 100,
                'currency'    => strtolower( $this->order->get_order_currency() ),
                'token'       => isset( $_POST['stripe_token'] ) ? $_POST['stripe_token'] : '',
                'chosen_card' => isset( $_POST['p4wc_card'] ) ? $_POST['p4wc_card'] : 'new',
                'save_card'   => isset( $_POST['p4wc_save_card'] ) && $_POST['p4wc_save_card'],
                'customer'    => array(
                    'name'          => $this->order->billing_first_name . ' ' . $this->order->billing_last_name,
                    'billing_email' => $this->order->billing_email,
                ),
                'errors'      => isset( $_POST['form_errors'] ) ? $_POST['form_errors'] : '',
            );
        }

        return false;
    }

    /**
     * Set up the charge that will be sent to Stripe
     *
     * @access      private
     * @return      void
     */
    private function charge_set_up() {
        global $p4wc;

        $customer_info = get_user_meta( $this->order->user_id, $p4wc->settings['stripe_db_location'], true );

        // Allow options to be set without modifying sensitive data like amount, currency, etc.
        $stripe_charge_data = apply_filters( 'p4wc_charge_data', array(), $this->form_data, $this->order );

        // Set up basics for charging
        $stripe_charge_data['amount']   = $this->form_data['amount']; // amount in cents
        $stripe_charge_data['currency'] = $this->form_data['currency'];
        $stripe_charge_data['capture']  = ( $this->settings['charge_type'] == 'capture' ) ? 'true' : 'false';
        $stripe_charge_data['expand[]'] = 'balance_transaction';

        // Make sure we only create customers if a user is logged in and wants to save their card
        if (
            is_user_logged_in() &&
            $this->settings['saved_cards'] === 'yes' &&
            ( $this->form_data['save_card'] || $this->form_data['chosen_card'] !== 'new' )
        ) {
            // Add a customer or retrieve an existing one
            $customer = $this->get_customer();

            $stripe_charge_data['card'] = $customer['card'];
            $stripe_charge_data['customer'] = $customer['customer_id'];

            // Update default card
            if ( count( $customer_info['cards'] ) && $this->form_data['chosen_card'] !== 'new' ) {
                $default_card = $customer_info['cards'][ intval( $this->form_data['chosen_card'] ) ]['id'];
                P4WC_DB::update_customer( $this->order->user_id, array( 'default_card' => $default_card ) );
            }

        } else {

            // Set up one time charge
            $stripe_charge_data['card'] = $this->form_data['token'];
        }

        // Charge description
        $stripe_charge_data['description'] = $this->get_charge_description();

        // Create the charge on Stripe's servers - this will charge the user's card
        $charge = P4WC_API::create_charge( $stripe_charge_data );

        $this->charge = $charge;
        $this->transaction_id = $charge->id;
    }
}
