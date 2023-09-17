<?php
/*
Plugin Name: EarnFast Payments Gateway
Description: Payment gateway for EarnFast Payments.
Version: 1.0
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_earnfast_payments_gateway');

/**
 * Initialize the EarnFast Payments Gateway
 */
function init_earnfast_payments_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_EarnFast_Payments_Gateway extends WC_Payment_Gateway
    {
        /**
         * Constructor for the payment gateway.
         */
        public function __construct()
        {
            $this->id = 'woocommerce-earnfast-pg';
            $this->has_fields = false;
            $this->method_title = 'EarnFast PG';
            $this->method_description = 'WooCommerce payment gateway for EarnFast Payments';
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->secret_key = $this->get_option('secret_key');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        }

        /**
         * Initialize form fields for the payment gateway settings.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable EarnFast PG',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'EarnFast Payments',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay securely with EarnFast Payments.',
                ),
                'secret_key' => array(
                    'title' => 'Secret Key',
                    'type' => 'text',
                    'description' => 'Enter your EarnFast Payments secret key here.',
                    'default' => '',
                ),
            );
        }

        /**
         * Process the payment and return the redirect URL.
         *
         * @param int $order_id The order ID.
         * @return array The payment result with redirect URL.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            // Return redirect
            return array(
                'result'   => 'success',
                'redirect' => apply_filters('upiwc_process_payment_redirect', $order->get_checkout_payment_url(true), $order),
            );
        }

        /**
         * Perform actions when payment is done.
         *
         * @param int $order_id The order ID.
         */
        public function done_payment($order_id) {
            $order = wc_get_order($order_id);
            
            // Update the order status to 'processing'
            $order->update_status( 'processing' );
            
            // Reduce stock for ordered items
            $order_items = $order->get_items();
        
            foreach ($order_items as $item) {
                $product = wc_get_product($item->get_product_id());
                if ($product->managing_stock()) {
                    $product->reduce_stock($item->get_quantity());
                }
            }
        
            // Empty the cart
            WC()->cart->empty_cart();
        
            // Log payment details
            wc_get_logger()->info('Payment successfully processed for order ' . $order_id);

            // Send order confirmation email
            WC()->mailer()->customer_invoice($order_id);
        
            // Redirect to the thank you page
            $redirect_url = $this->get_return_url($order);
            wp_redirect($redirect_url);
            exit;
        }
        
        

        /**
         * Generate the payment token for the order.
         *
         * @param int $order_id The order ID.
         * @return string The generated payment token.
         */
        public function generate_pg_token($order_id)
        {
            $order = wc_get_order($order_id);
            $amount = $order->get_total();
            $comment = '';
            $mobile = $order->get_billing_phone();
            $email = $order->get_billing_email();
            $orderid = $order->get_order_number();

            // Create the token
            $token_data = array(
                'amount' => $amount,
                'comment' => $comment,
                'mobile' => $mobile,
                'email' => $email,
                'secretkey' => $this->secret_key,
                'orderid' => $orderid,
            );

            $token = base64_encode(json_encode($token_data));
            return $token;
        }

        /**
         * Scrape data from the payment gateway page.
         *
         * @param string $page_content The page content.
         * @return array The scraped data.
         */
        public function scrape_data($page_content)
        {
            // Extract the amount from the URL parameter
            $amount_pattern = '/&am=([\d.]+)&/';
            $_amount = 0;

            if (preg_match($amount_pattern, $page_content, $matches)) {
                $matched_amount = $matches[1];
                if (is_numeric($matched_amount)) {
                    $_amount = $matched_amount;
                }
            }

            // Extract the UPI Id
            $upi_pattern = '/pa=([^&]+)/';

            if (preg_match($upi_pattern, $page_content, $matches)) {
                $_upi_id = $matches[1];
            }

            // Extract the UPI general link
            $upi_general_link = '';
            $upi_link_pattern = '/href=\'(.*?)\'/';

            if (preg_match($upi_link_pattern, $page_content, $matches)) {
                $href = $matches[1];
                $link_start = strpos($href, '://pay');
                if ($link_start !== false) {
                    $upi_general_link = substr($href, $link_start);
                }
            }

            // Generate specific payment links
            $_paytm_link = 'paytmmp' . $upi_general_link;
            $_phonepe_link = 'phonepe' . $upi_general_link;
            $_googlepay_link = 'tez' . $upi_general_link;
            $_other_upi_link = 'upi' . $upi_general_link;

            // Extract the QR link
            $_qr_link = '';
            $qr_pattern = '/qr\/(.*?)\'/';

            if (preg_match($qr_pattern, $page_content, $matches)) {
                $qr = $matches[1];
                $new_url = 'https://payment.earnfastpayments.com/gate/qr/' . $qr;
                $_qr_link = $new_url;
            }

            // Return the extracted data as an associative array
            return [
                'amount' => $_amount,
                'upi_id' => $_upi_id,
                'paytm_link' => $_paytm_link,
                'phonepe_link' => $_phonepe_link,
                'googlepay_link' => $_googlepay_link,
                'other_upi_link' => $_other_upi_link,
                'qr_link' => $_qr_link,
            ];
        }

        /**
         * Generate the payment gateway for the order.
         *
         * @param int $order_id The order ID.
         * @return array The payment gateway data.
         */
        public function generate_payment_gateway($order_id)
        {
            // Define the URL for the payment gateway
            $pg_url = 'https://earnfastpayments.com/accept-payment/gate?token=' . $this->generate_pg_token($order_id);

            // Make a request to the URL and fetch the page content
            $page_content = file_get_contents($pg_url);

            if ($page_content == ' Duplicate Order ID! ') {
                echo "<script>console.log('Duplicate Order ID');</script>";
                $payment_status = payment_status($order_id);
                if ($payment_status == 'Paid') {
                    echo "<script>console.log('Paid');</script>";
                    $this->done_payment($order_id);
                    return;
                }
                if ($payment_status == 'Unpaid') {
                    $pg_url = 'https://payment.earnfastpayments.com/gate/?orderid='.$order_id;
                    $page_content = file_get_contents($pg_url);
                    echo "<script>console.log('Unpaid');</script>";
                }
            }

            return $this->scrape_data($page_content);
        }

        /**
         * Display the payment receipt page.
         *
         * @param int $order_id The order ID.
         */
        public function receipt_page($order_id)
        {

            $payment_data = $this->generate_payment_gateway($order_id);

            // Check if the data was successfully retrieved
            if (!empty($payment_data)) {
                // Access and use the extracted data
                $amount = $payment_data['amount'];
                $upi_id = $payment_data['upi_id'];
                $paytm_link = $payment_data['paytm_link'];
                $phonepe_link = $payment_data['phonepe_link'];
                $googlepay_link = $payment_data['googlepay_link'];
                $other_upi_link = $payment_data['other_upi_link'];
                $qr_link = $payment_data['qr_link'];
            } else {
                echo '<div class="payment-container">Failed to retrieve payment data.<br> </div>';
                die;
            }
            echo '
            <input type="hidden" id="order_id" value="'.$order_id.'">
            <input type="hidden" id="cancle_url" value="'.wc_get_checkout_url().'">
            <div class="payment-container">
                <h6 class="payment-text">Please click the Pay Now button below to complete the payment against this order.</h6>
                <div class="payment-container-buttons">
                    <button id="pay-now" class="btn button">Scan &amp; Pay Now</button>
                    <button id="cancel-payment" class="btn button">Cancel</button>
                </div>
            </div>

            <!-- Payment Dialog -->
            <div class="payment-dialog" id="payment-dialog">
                <div class="payment-header">
                    <div class="store-info">
                        <div>
                            <span class="store-name">My Shop</span>
                        </div>
                        <div>
                            <span class="order-id">Order ID: #'.$order_id.'</span>
                        </div>
                    </div>
                    <div id="close-icon" class="close-icon"></div>
                </div>
                <main>
                    <div class="main-content">
                        <div class="qr-code-container">
                            <div class="qr-code">
                                <img src="'.$qr_link.'" style="display: inline;">
                            </div>
                            <div class="upi-id">
                                '.$upi_id.'
                            </div>
                        </div>

                        Please complete your payment by scanning the QR code with your UPI app.

                        <div class="installed-apps-section">
                            <p class="installed-apps-text">Or pay with installed apps</p>
                            <div class="payment-methods">
                                <a href="'.$googlepay_link.'" class="upi-button">
                                    <img src="'.get_option('siteurl').'/wp-content/plugins/woocommerce-earnfast-pg/includes/googlepay.svg" alt="Google Pay">
                                    <span class="button-text">GPay</span>
                                </a>
                                <a href="'.$phonepe_link.'" class="upi-button">
                                    <img src="'.get_option('siteurl').'/wp-content/plugins/woocommerce-earnfast-pg/includes/phonepe.svg" alt="PhonePe">
                                    <span class="button-text">PhonePe</span>
                                </a>
                                <a href="'.$paytm_link.'" class="upi-button">
                                    <img src="'.get_option('siteurl').'/wp-content/plugins/woocommerce-earnfast-pg/includes/paytm.svg" alt="Paytm">
                                    <span class="button-text">Paytm</span>
                                </a>
                                <a href="'.$other_upi_link.'" class="upi-button">
                                    <img src="'.get_option('siteurl').'/wp-content/plugins/woocommerce-earnfast-pg/includes/bhim.svg" alt="BHIM">
                                    <span class="button-text">Others</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </main>
                <div class="payment-footer">
                    <div class="amount">
                        ₹ '.$amount.'
                    </div>
                    <a href="'.$other_upi_link.'">
                        <button class="payment-button">Pay Now</button>
                    </a>
                </div>
            </div>';
        }
    }

    /**
     * Add the EarnFast Payments Gateway to WooCommerce payment gateways.
     *
     * @param array $methods The existing payment methods.
     * @return array The updated payment methods.
     */
    function add_earnfast_payments_gateway($methods)
    {
        $methods[] = 'WC_EarnFast_Payments_Gateway';
        return $methods;
    }

    /**
     * Enqueue the payment gateway styles.
     */
    function enqueue_payment_gateway_styles()
    {
        // Check if we are on the payment page (replace 'your-payment-page-slug' with the actual slug or identifier of your payment page).
        if (is_page('checkout')) {
            // Enqueue your CSS file.
            wp_enqueue_style('payment-styles', plugin_dir_url(__FILE__) . 'includes/payment-styles.css', array(), '1.0', 'all');
        }
    }

    /**
     * Enqueue the payment dialog script.
     */
    function enqueue_payment_dialog_script()
    {
        if (is_page('checkout')) {
            // Enqueue the JavaScript file
            wp_enqueue_script('payment-script', plugin_dir_url(__FILE__) . 'includes/payment.js', array(), '1.0', true);
        }
    }

    /**
     * Scan the payment status.
     *
     * @param int $order_id The order ID.
     * @return string|null The payment status.
     */
    function payment_status($order_id) 
    {
        // You can use cURL or file_get_contents to make an HTTP request
        $url = 'https://payment.earnfastpayments.com/gate/status/?orderid=' . $order_id;
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        return $data['status'];
    }
    

    // Hook the function into the wp_enqueue_scripts action
    add_action('wp_enqueue_scripts', 'enqueue_payment_dialog_script');
    add_action('wp_enqueue_scripts', 'enqueue_payment_gateway_styles');
    add_filter('woocommerce_payment_gateways', 'add_earnfast_payments_gateway');
}
