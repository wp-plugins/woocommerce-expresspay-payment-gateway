<?php

/*
  Plugin Name: Woocommerce Expresspay Payment Plugin
  Plugin URI: http://txtghana.com
  Description: Integrate visa ghana, visa card, master card and mobile money payment into your Woocommerce site
  Version: 2.0.0
  Author: Delu Akin
  Author URI: https://www.facebook.com/deluakin
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    exit;
}

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
            $this->sms = $this->settings['sms'];
            $this->sms_url = $this->settings['sms_url'];
            $this->sms_message = $this->settings['sms_message'];

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

            if (isset($_REQUEST["exp-pay-notice"])) {
                wc_add_notice($_REQUEST["exp-pay-notice"], "error");
            }

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
                    'title' => __('Enable/Disable', 'expresspay'),
                    'type' => 'checkbox',
                    'label' => __('Enable Expresspay Payment Module.', 'expresspay'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'expresspay'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'expresspay'),
                    'default' => __('ExpressPay', 'expresspay')),
                'description' => array(
                    'title' => __('Description:', 'expresspay'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'expresspay'),
                    'default' => __('Integrate visa ghana, visa card, master card and mobile money payment into your Woocommerce site.', 'expresspay')),
                'merchant_id' => array(
                    'title' => __('Merchant ID', 'expresspay'),
                    'type' => 'text',
                    'description' => __('This Merchant ID Given to Merchant by ExpressPay."')),
                'salt' => array(
                    'title' => __('API Key', 'expresspay'),
                    'type' => 'text',
                    'description' => __('API Key given to Merchant by ExpressPay', 'expresspay')),
                'sandbox' => array(
                    'title' => __('Sandbox', 'expresspay'),
                    'type' => 'checkbox',
                    'description' => __('Is API in sandbox mode', 'expresspay')),
                'sms' => array(
                    'title' => __('SMS Notification', 'expresspay'),
                    'type' => 'checkbox',
                    'description' => __('Enable SMS notification after sucessful payment on Expresspay', 'expresspay')),
                'sms_url' => array(
                    'title' => __('Send SMS REST API URL'),
                    'type' => 'text',
                    'description' => __('Use {NUMBER} for the customers number, {MESSAGE} should be in place of the message')),
                'sms_message' => array(
                    'title' => __('SMS Response'),
                    'type' => 'textarea',
                    'description' => __('Use {ORDER-ID} for the order id, {AMOUNT} for amount, {CUSTOMER} for customer name.'))
            );
        }

        public function admin_options() {
            echo '<h3>' . __('Expresspay Payment Gateway', 'expresspay') . '</h3>';
            echo '<p>' . __('Expresspay is most popular payment gateway for online shopping in Ghana') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
            wp_enqueue_script('expresspay_admin_option_js', plugin_dir_url(__FILE__) . 'assets/js/settings.js', array('jquery'), '1.0.1');
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

            WC()->session->set('expresspay_wc_hash_key', $hash);

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
            $status = $response_decoded->status;
            $token = $response_decoded->token;

            if ($status == 1) {
                return $this->checkouturl . $token;
            } else {
                $error_message = "";
                if ($status == 2) {
                    $error_message = "Invalid credentials or credentials not set in the settings page.";
                } else if ($status == 3) {
                    $error_message = "Your request is invalid";
                } else {
                    $error_message = "Invalid IP, kindly contact info@expresspaygh.com to get your IP verified.";
                }
                global $woocommerce;
                $url = $woocommerce->cart->get_checkout_url();
                if (strstr($url, "?")) {
                    return $url . "&exp-pay-notice=" . $error_message;
                } else {
                    return $url . "?exp-pay-notice=" . $error_message;
                }
            }
        }

        function process_payment($order_id) {
            WC()->session->set('expresspay_wc_oder_id', $order_id);
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $this->post_to_url($this->posturl, $this->get_expresspay_args($order))
            );
        }

        function showMessage($content) {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        function sendsms($number, $message) {
            $url = $this->sms_url;
            $url = str_replace("{NUMBER}", urlencode($number), $url);
            $url = str_replace("{MESSAGE}", urlencode($message), $url);
            $url = str_replace("amp;", "&", $url);
            if (trim($url) <> "") {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_URL => $url
                ));
                curl_exec($curl);
                curl_close($curl);
            }
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

                $wc_order_id = WC()->session->get('expresspay_wc_oder_id');
                $hash = WC()->session->get('expresspay_wc_hash_key');
                $order = new WC_Order($wc_order_id);

                if ($wc_order_id != '') {
                    try {
                        $order_id_data = explode('_', $_REQUEST['order-id']);
                        $order_id = (int) $order_id_data[0];
                        if ($wc_order_id <> $order_id) {
                            $message = "Thank you for shopping with us. 
                                Howerever, Your transaction session timed out. 
                                Your Order id is $wc_order_id";
                            $message_type = "notice";
                            $order->add_order_note($message);

                            $redirect_url = $order->get_cancel_order_url();
                            wp_redirect($redirect_url);
                            exit;
                        }
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
                                $total_amount = strip_tags($woocommerce->cart->get_cart_total());

                                $message = "Thank you for shopping with us. 
                                Your transaction was succssful, payment was received. 
                                You order is currently beign processed. 
                                Your Order id is $order_id";
                                $message_type = "success";

                                $order->payment_complete();
                                $order->update_status('completed');
                                $order->add_order_note('Expresspay payment successful<br/>Unnique Id from Expresspay: ' . $_REQUEST['token']);
                                $order->add_order_note($this->msg['message']);
                                $woocommerce->cart->empty_cart();
                                $redirect_url = $this->get_return_url($order);
                                $customer = trim($order->billing_last_name . " " . $order->billing_first_name);
                                if ($this->sms == "yes") {
                                    $phone_no = get_user_meta(get_current_user_id(), 'billing_phone', true);
                                    $sms = $this->sms_message;
                                    $sms = str_replace("{ORDER-ID}", $order_id, $sms);
                                    $sms = str_replace("{AMOUNT}", $total_amount, $sms);
                                    $sms = str_replace("{CUSTOMER}", $customer, $sms);
                                    $this->sendsms($phone_no, $sms);
                                }
                                break;
                            case 2:
                                //request declined
                                $message = "Thank you for shopping with us. However, 
                                    the transaction could not be completed.";
                                $message_type = "error";
                                $order->add_order_note('Transaction declined');
                                $redirect_url = $order->get_cancel_order_url();
                            default:
                                $result_text = (isset($response_decoded->{'result-text'})) ? $response_decoded->{'result-text'} : "";
                                if ($cancel == "true") {
                                    //user cancel request
                                    $message = "Thank you for shopping with us. However, you cancelled your transaction.";
                                    $message_type = "error";
                                    $order->add_order_note('Transaction cancelled by user');
                                    $redirect_url = $order->get_cancel_order_url();
                                } else {
                                    //system error
                                    $message = "Thank you for shopping with us. However, the transaction failed.";
                                    $message_type = "error";
                                    $order->add_order_note('Transaction Failed: ' . $result_text);
                                    $redirect_url = $order->get_cancel_order_url();
                                }
                                break;
                        }

                        $notification_message = array(
                            'message' => $message,
                            'message_type' => $message_type
                        );
                        if (version_compare(WOOCOMMERCE_VERSION, "2.2") >= 0) {
                            add_post_meta($wc_order_id, '_expresspay_hash', $hash, true);
                        }
                        update_post_meta($wc_order_id, '_expresspay_wc_message', $notification_message);

                        WC()->session->__unset('expresspay_wc_hash_key');
                        WC()->session->__unset('expresspay_wc_order_id');

                        wp_redirect($redirect_url);
                        exit;
                    } catch (Exception $e) {
                        $order->add_order_note('Error: ' . $e->getMessage());
                        $redirect_url = $order->get_cancel_order_url();
                        wp_redirect($redirect_url);
                        exit;
                    }
                }

                $redirect_url = get_permalink(get_option('woocommerce_myaccount_page_id')) . "&view-order=" . $order_id;
                wp_redirect($redirect_url);
            }
        }

        static function add_expresspay_ghs_currency($currencies) {
            $currencies['GHS'] = __('Ghana Cedi', 'woocommerce');
            return $currencies;
        }

        static function add_expresspay_ghs_currency_symbol($currency_symbol, $currency) {
            switch ($currency) {
                case 'GHS': $currency_symbol = 'GHS ';
                    break;
            }
            return $currency_symbol;
        }

        static function woocommerce_add_expresspay_gateway($methods) {
            $methods[] = 'WC_Expresspay';
            return $methods;
        }

        static function woocommerce_add_expresspay_settings_link($links) {
            $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=wc_expresspay">Settings</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

    }

    $plugin = plugin_basename(__FILE__);

    add_filter('woocommerce_currencies', array('WC_Expresspay', 'add_expresspay_ghs_currency'));
    add_filter('woocommerce_currency_symbol', array('WC_Expresspay', 'add_expresspay_ghs_currency_symbol'), 10, 2);

    add_filter("plugin_action_links_$plugin", array('WC_Expresspay', 'woocommerce_add_expresspay_settings_link'));
    add_filter('woocommerce_payment_gateways', array('WC_Expresspay', 'woocommerce_add_expresspay_gateway'));
}