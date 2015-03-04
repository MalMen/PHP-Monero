<?php
include("includes/mysql.php");
include("includes/monero.php");

$payment_address = xmr_create_payment_address();	// create payment address to show to final user

echo "transfer 3 $payment_address[openalias] 5 $payment_address[payment_id]";	// simplewallet transfer line
echo "\n\n";
$transfer = xmr_transfer('49RPpNuDuLhayv8yHgVSNhgdvB4Uze3A9euEsBzp3groWssk2eZPEErf6LSDae9smQ78a5CfNmafYdgYnyjTEY6q4EvuPJ1', 5);	// add transfer to database to be prossess later

echo ($transfer["amount"]/12)." XMR will be sent to $transfer[address]";
echo "\n\n";


xmr_client_receive();	// processing receivements
xmr_client_transfer();	// processing transfers
// this 2 functions should be added to crontab
?>