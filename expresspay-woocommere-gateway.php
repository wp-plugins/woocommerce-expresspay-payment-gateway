<?php

/*
  Plugin Name: Woocommerce Expresspay Payment Plugin
  Plugin URI: http://txtghana.com
  Description: Integrate visa ghana, visa card, master card and mobile money payment into your Woocommerce site
  Version: 1.0
  Author: Delu Akin
  Author URI: https://www.facebook.com/deluakin
 */

add_action('plugins_loaded', 'woocommerce_expresspay_init', 0);

function woocommerce_expresspay_init() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Expresspay extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'expresspay';
            $this->medthod_title = 'Expresspay';
            $this->icon = apply_filters('woocommerce_expresspay_icon', plugins_url('assets/images/logo.png', __FILE__));
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->merchant_id = $this->settings['merchant_id'];
            $this->salt = $this->settings['salt'];
            $this->sandbox = $this->settings['sandbox'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];

            if ($this->settings['sandbox'] == "yes") {
                $this->posturl = 'https://sandbox.expresspaygh.com/api/submit.php';
                $this->geturl = 'https://sandbox.expresspaygh.com/api/query.php';
                $this->checkouturl = 'https://sandbox.expresspaygh.com/api/checkout.php?token=';
            } else {
                $this->posturl = 'https://expresspaygh.com/api/submit.php';
                $this->geturl = 'https://expresspaygh.com/api/query.php';
                $this->checkouturl = 'https://expresspaygh.com/api/checkout.php?token=';
            }

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            if (isset($_REQUEST["order-id"]) && $_REQUEST["order-id"] <> "") {
                $this->check_expresspay_response();
            }

            //add_action('init', array(&$this, 'check_expresspay_response'));
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'mrova'),
                    'type' => 'checkbox',
                    'label' => __('Enable Expresspay Payment Module.', 'mrova'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'mrova'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'mrova'),
                    'default' => __('ExpressPay', 'mrova')),
                'description' => array(
                    'title' => __('Description:', 'mrova'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'mrova'),
                    'default' => __('Pay securely by Credit or Debit card ExpressPay Secure Servers.', 'mrova')),
                'merchant_id' => array(
                    'title' => __('Merchant ID', 'mrova'),
                    'type' => 'text',
                    'description' => __('This Merchant ID Given to Merchant by ExpressPay."')),
                'salt' => array(
                    'title' => __('API Key', 'mrova'),
                    'type' => 'text',
                    'description' => __('API Key given to Merchant by ExpressPay', 'mrova')),
                'sandbox' => array(
                    'title' => __('Sandbox', 'mrova'),
                    'type' => 'checkbox',
                    'description' => __('Is API in sandbox mode', 'mrova'))
            );
        }

        public function admin_options() {
            echo '<h3>' . __('Expresspay Payment Gateway', 'mrova') . '</h3>';
            echo '<p>' . __('Expresspay is most popular payment gateway for online shopping in Ghana') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        protected function get_expresspay_args($order) {
            global $woocommerce;

            //$order = new WC_Order($order_id);
            $txnid = $order->id . '_' . date("ymds");

            $redirect_url = $woocommerce->cart->get_checkout_url();

            $productinfo = "Order: " . $order->id;

            $str = "$this->merchant_id|$txnid|$order->order_total|$productinfo|$order->billing_first_name|$order->billing_email|||||||||||$this->salt";
            $hash = hash('sha512', $str);

            $expresspay_args = array(
                'merchant-id' => $this->merchant_id,
                'api-key' => $this->salt,
                'firstname' => $order->billing_first_name,
                'lastname' => $order->billing_last_name,
                'phonenumber' => $order->billing_phone,
                'email' => $order->billing_email,
                'username' => $order->billing_email,
                'currency' => "GHS",
                'amount' => $order->order_total,
                'order-id' => $txnid,
                'order-desc' => $productinfo,
                'redirect-url' => $redirect_url,
                'hash' => $hash
            );
            apply_filters('woocommerce_expresspay_args', $expresspay_args, $order);
            return $expresspay_args;
        }

        function post_to_url($url, $data) {
            $fields = "";
            foreach ($data as $key => $value) {
                $fields .= $key . '=' . $value . '&';
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, rtrim($fields, '&'));
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            $response_decoded = json_decode($response);
            print_r($response_decoded);
            $status = $response_decoded->status;
            $token = $response_decoded->token;

            if ($status == 1) {
                return $this->checkouturl . $token;
            } else {
                return false;
            }
        }

        function process_payment($order_id) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $this->post_to_url($this->posturl, $this->get_expresspay_args($order))
            );
        }

        function showMessage($content) {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title)
                $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

        function check_expresspay_response() {
            global $woocommerce;
            $cancel = isset($_REQUEST["cancel"]) ? $_REQUEST["cancel"] : "";
            $token = isset($_REQUEST["token"]) ? $_REQUEST["token"] : "";
            if (isset($_REQUEST["order-id"])) {
                $order_id_data = explode('_', $_REQUEST['order-id']);
                $order_id = (int) $order_id_data[0];
                if ($order_id != '') {
                    try {
                        $order = new WC_Order($order_id);
                        $data = array(
                            'merchant-id' => $this->merchant_id,
                            'api-key' => $this->salt,
                            "token" => $token
                        );
                        $fields = "";
                        foreach ($data as $key => $value) {
                            $fields .= $key . '=' . $value . '&';
                        }
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $this->geturl);
                        curl_setopt($ch, CURLOPT_HEADER, 0);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, rtrim($fields, '&'));
                        curl_setopt($ch, CURLOPT_VERBOSE, true);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $response = curl_exec($ch);
                        $response_decoded = json_decode($response);
                        $result = $response_decoded->result;
                        switch ($result) {
                            case 1:
                                //transaction was successful
                                $order->payment_complete();
                                $order->update_status('completed');
                                $order->add_order_note('Expresspay payment successful<br/>Unnique Id from Expresspay: ' . $_REQUEST['token']);
                                $order->add_order_note($this->msg['message']);
                                $woocommerce->cart->empty_cart();
                                break;
                            case 2:
                                //request declined
                                $this->msg['class'] = 'woocommerce_error';
                                $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                                $order->add_order_note('Transaction Declined');
                                break;
                            default:
                                $result_text = (isset($response_decoded->{'result-text'})) ? $response_decoded->{'result-text'} : "";
                                if ($cancel == "true") {
                                    //user cancel request
                                    $this->msg['class'] = 'woocommerce_error';
                                    $this->msg['message'] = "Thank you for shopping with us. However, you cancelled your transaction.";
                                    $order->add_order_note('Transaction cancelled by user');
                                } else {
                                    //system error
                                    $this->msg['class'] = 'woocommerce_error';
                                    $this->msg['message'] = "Thank you for shopping with us. However, the transaction failed.";
                                    $order->add_order_note('Transaction Failed: ' . $result_text);
                                }
                                break;
                        }
                    } catch (Exception $e) {
                        $this->msg['class'] = 'woocommerce_error';
                        $this->msg['message'] = "Error.";
                    }
                }

                $redirect_url = get_permalink(get_option('woocommerce_myaccount_page_id')) . "&view-order=" . $order_id;
                wp_redirect($redirect_url);
            }
        }

    }

    add_filter('woocommerce_currencies', 'add_my_currency');

    function add_my_currency($currencies) {
        $currencies['GHS'] = __('Ghana Cedi', 'woocommerce');
        return $currencies;
    }

    add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);

    function add_my_currency_symbol($currency_symbol, $currency) {
        switch (
        $currency) {
            case 'GHS': $currency_symbol = 'GHS ';
                break;
        }
        return $currency_symbol;
    }

    function woocommerce_add_expresspay_gateway($methods) {
        $methods[] = 'WC_Expresspay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_expresspay_gateway');
}