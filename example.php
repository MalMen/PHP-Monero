<?php
/**
 * Example code
 *
 * @filesource
 */


/** You need to create a MySQLi instance first */
$mysqli = new mysqli('host','user','pass','database');

/** The Monero Interface takes this MySQLi instance as an argument */
$MoneroAPI = new \includes\PHP_Monero($mysqli);

/** This will return an array with a new payment address */
$payment_address = $MoneroAPI->create_payment_address();

/** I wonder if it worked */
if ($payment_address) {
    echo "transfer 3 $payment_address[openalias] 5 $payment_address[payment_id]";
    echo '<br />';
} else {
    echo 'Handle the error. <br />';
}

/** This will send a transfer for 5 Moneroj to the database for later processing */
$transfer = $MoneroAPI->transfer('address', 5);	

/** Did that work? *//
if (!$transfer) {
    echo 'Didn\'t think so. "Address" is not a valid address :P <br />';
} else {
    echo ($transfer["amount"]/12) . " XMR will be sent to $transfer[address] <br />";
}


/** The below methods should be added to your Cron Jobs */
$MoneroAPI->client_receive(); // procceing receipts
$MoneroAPI->client_transfer();	// processing transfers

