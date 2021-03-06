<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 * 
 * Released under the GNU General Public License
 */

if ( !defined( 'AREA' ) ) {
    die( 'Direct Access Denied' );
}

if ( !defined( 'PAYMENT_NOTIFICATION' ) ) {
    $current_url = isset( $_SERVER['HTTPS'] ) ? 'https://' : 'http://' . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $mode        = $processor_data['processor_params']['mode'];
    if ( $mode == 'test' ) {
        $form['id']  = trim( $processor_data['processor_params']['id'] );
        $form['key'] = trim( $processor_data['processor_params']['secret'] );
    } else {
        $form['id']  = trim( $processor_data['processor_params']['id'] );
        $form['key'] = trim( $processor_data['processor_params']['secret'] );
    }
    $form['reference'] = 'Order_' . $order_id;
    $form['amount']    = number_format( $order_info['total'] * 1, 2, '', '' );
    $form['currency']  = $order_info['secondary_currency'];
    $form['date']      = date( 'd-m-Y H:i' );
    $form['email']     = $order_info['email'];
    $country_code3     = db_get_field( 'SELECT code_A3 FROM ?:countries WHERE code=?s', $order_info['b_country'] );
    $return_url        = fn_url( "payment_notification.return?payment=paygate&order_id=$order_id" );
    $notify_url        = fn_url( "payment_notification.notify&payment=paygate&order_id=$order_id" );
    $p_order_id        = trim( $wc_data['order_prefix'] ) . (  ( $order_info['repaid'] ) ? ( $order_id . '_' . $order_info['repaid'] ) : $order_id );
    $initiateFields    = array(
        'PAYGATE_ID'       => $form['id'],
        'REFERENCE'        => $form['reference'],
        'AMOUNT'           => $form['amount'],
        'CURRENCY'         => $form['currency'],
        'RETURN_URL'       => $return_url,
        'TRANSACTION_DATE' => $form[date],
        'LOCALE'           => 'en-za',
        'COUNTRY'          => $country_code3,
        'EMAIL'            => $form['email'],
        'NOTIFY_URL'       => $notify_url,
        'USER3'            => 'cscart4',
    );

    $initiateFields['CHECKSUM'] = md5( implode( '', $initiateFields ) . $form['key'] );
    $curl                       = curl_init( 'https://secure.paygate.co.za/payweb3/initiate.trans' );
    curl_setopt( $curl, CURLOPT_POST, count( $initiateFields ) );
    curl_setopt( $curl, CURLOPT_POSTFIELDS, $initiateFields );
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 0 );
    curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, 0 );
    $response = curl_exec( $curl );
    curl_close( $curl );
    parse_str( $response, $responseFields );
    echo <<<HTML
<p>Kindly wait while you're redirected to PayGate ...</p>
<form action="https://secure.paygate.co.za/payweb3/process.trans" method="post" name="redirect">
        <input name="PAY_REQUEST_ID" type="hidden" value="{$responseFields['PAY_REQUEST_ID']}" />
        <input name="CHECKSUM" type="hidden" value="{$responseFields['CHECKSUM']}" />
</form>
<script type="text/javascript">document.forms['redirect'].submit();</script>
HTML;
    die;
} else if ( defined( 'PAYMENT_NOTIFICATION' ) ) {
    if ( $mode == 'return' ) {
        $order_id       = $_REQUEST['order_id'];
        $order_info     = fn_get_order_info( $order_id );
        $payment_id     = db_get_field( "SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id );
        $processor_data = fn_get_payment_method_data( $payment_id );
        $status         = $_POST['TRANSACTION_STATUS'];
        if ( $status == 1 && fn_check_payment_script( 'paygate.php', $order_id ) ) {
            $pp_response['order_status']   = 'P';
            $pp_response['reason_text']    = 'PayGate Redirect Response: The User Completed Payment with PayGate';
            $pp_response['transaction_id'] = '';
        } else if ( $status == 2 && fn_check_payment_script( 'paygate.php', $order_id ) ) {
            $pp_response['order_status'] = 'D';
            $pp_response['reason_text']  = 'PayGate Redirect Response: Transaction was declined by the payment processor';

        } else if ( $status == 4 && fn_check_payment_script( 'paygate.php', $order_id ) ) {
            $pp_response['order_status'] = 'I';
            $pp_response['reason_text']  = 'PayGate Redirect Response: User has cancelled payment';
        } else {
            $pp_response['order_status'] = 'F';
            $pp_response['reason_text']  = 'PayGate Redirect Response: Your Payment has failed';
        }
        fn_finish_payment( $order_id, $pp_response, false );
        fn_order_placement_routines( 'route', $order_id );

    } else if ( $mode == 'notify' ) {

        $order_id = $_REQUEST['order_id'];
        fn_check_payment_script( 'paygate.php', $order_id, $processor_data );
        $payment_id     = db_get_field( "SELECT payment_id FROM ?:orders WHERE order_id = ?i", $_POST['REFERENCE'] );
        $processor_data = fn_get_payment_method_data( $payment_id );

        $pp_response = array();
        $order_info  = fn_get_order_info( $order_id );

        if ( empty( $processor_data ) ) {
            $processor_data = fn_get_processor_data( $order_info['payment_id'] );
        }
        $errors       = false;
        $paygate_data = array();
        $notify_data  = array();
        // Get notify data
        if ( !$errors ) {
            $nData = $_POST;

            // Strip any slashes in data
            foreach ( $nData as $key => $val ) {
                $paygate_data[$key] = stripslashes( $val );
            }
            if ( $paygate_data === false ) {
                $errors = true;
            }
        }
        $encryption_key = '';
        $mode           = $processor_data['params']['mode'];
        if ( $mode ) {
            $encryption_key = $processor_data['params']['key'];
        } else {
            $encryption_key = 'secret';
        }

        // Verify security signature
        $checkSumParams = '';
        if ( !$errors ) {
            foreach ( $paygate_data as $key => $val ) {
                $notify_data[$key] = stripslashes( $val );

                if ( $key == 'PAYGATE_ID' ) {
                    $checkSumParams .= $val;
                }
                if ( $key != 'CHECKSUM' && $key != 'PAYGATE_ID' ) {
                    $checkSumParams .= $val;
                }

                if ( sizeof( $notify_data ) == 0 ) {
                    $errors = true;
                }
            }
            $checkSumParams .= $encryption_key;
        }

        // Verify security signature
        if ( !$errors ) {
            $checkSumParams = md5( $checkSumParams );
            if ( $checkSumParams != $paygate_data['CHECKSUM'] ) {
                $errors                      = true;
                $pp_response['order_status'] = 'F';
                $pp_response['reason_text']  = 'Security Error: Checksum mismatch. Illegal access detected';
                fn_finish_payment( $order_id, $pp_response, false );
                fn_order_placement_routines( 'route', $order_id );
            }
        }
        $status = $_POST['TRANSACTION_STATUS'];
        if ( !$errors ) {
            if ( $status == 1 ) {
                $pp_response['order_status']   = 'P';
                $pp_response['reason_text']    = 'PayGate Notify Response: The User Completed Payment with PayGate';
                $pp_response['transaction_id'] = '';
            } else if ( $status == 2 ) {
                $pp_response['order_status'] = 'D';
                $pp_response['reason_text']  = 'PayGate Notify Response: Transaction was declined by the payment processor';
            } else if ( $status == 4 ) {
                $pp_response['order_status'] = 'I';
                $pp_response["reason_text"]  = 'PayGate Notify Response: ' . fn_get_lang_var( 'text_transaction_cancelled' );
            } else {
                $pp_response['order_status'] = 'F';
                $pp_response['reason_text']  = 'PayGate Notify Response: Your Payment has failed';
            }
        }
        fn_finish_payment( $order_id, $pp_response, false );
        fn_order_placement_routines( 'route', $order_id );
    }
}
