<?php

$xmr_expire_payments 	= '+ 1 day';
$xmr_wallet_addr		= "ip:port";		// wallet deamon
$xmr_my_addr			= "49RPpNuDuLhayv8yHgVSNhgdvB4Uze3A9euEsBzp3groWssk2eZPEErf6LSDae9smQ78a5CfNmafYdgYnyjTEY6q4EvuPJ1";	// wallet full address
$xmr_my_alias			= "pedrogaspar.pt";	//wallet alias
function xmr_create_payment_id() {
	$payment_id = bin2hex(openssl_random_pseudo_bytes(32));
	$check = query("SELECT id FROM xmr_payments WHERE payment_id = '$payment_id'");
	while (num_result($check) != 0) {
		$payment_id =  bin2hex(openssl_random_pseudo_bytes(32));
		$check = query("SELECT id FROM xmr_payments WHERE payment_id = '$payment_id'");
	}
	return $payment_id;
}
function xmr_create_payment_address($xmr = 0 /* amount in XMR*/) {
	global $xmr_expire_payments, $xmr_my_alias, $xmr_my_addr;
	$return = array();
	$amount = $xmr * 1000000000000;
	$payment_id = xmr_create_payment_id();
	$expire	= date("Y-m-d H:i", strtotime("now $xmr_expire_payments"));
	query("INSERT INTO xmr_payments (type, payment_id, amount, status, expire)
			VALUES ('receive', '$payment_id', $amount, 'pending', '$expire')");
	$inserted = query("SELECT payment_id, added, amount, type, status FROM xmr_payments
				WHERE payment_id = '$payment_id'");
	if (num_result($inserted) > 0) {
		$inserted = fetch_array($inserted);
		$expire = strtotime("Y-m-d H:m", strtotime($inserted["added"] . $xmr_expire_payments));
		$return["status"]		= $inserted["status"];
		$return["payment_id"]	= $inserted["payment_id"];
		$return["type"]			= $inserted["type"];
		$return["added"]		= $inserted["added"];
		$return["expire"]		= $expire;
		$return["address"]		= $xmr_my_addr;
		$return["openalias"]	= $xmr_my_alias;
	}
	else $return["status"] = "error";
	return $return;
}
function xmr_transfer($address, $xmr = 0 /* amount in XMR*/) {
	$return = array();
	//$address = escape_strings($address);
	$amount = $xmr * 1000000000000;
	if ($amount == 0 || $address == "")		return;
	
	query("INSERT INTO xmr_payments (type, address, amount, status)
				VALUES ('transfer', '$address', $amount, 'pending')");
	$id = get_inserted_id();
	$insert = query("SELECT type, address, amount, status
			FROM xmr_payments
			WHERE id = $id");
	if (num_result($insert) > 0) {
		$insert = fetch_array($insert);
		$return["type"] =  $insert["transfer"];
		$return["address"] =  $insert["address"];
		$return["amount"] =  $insert["amount"];
		$return["status"] =  $insert["status"];
	}
	else $return["status"] = "error";
	return $return;
	
}
function xmr_client_receive() {
	global $xmr_wallet_addr;
	$return = array();
	$now = date("Y-m-d H:i", strtotime("now"));
	$pending_receive = query("SELECT payment_id FROM xmr_payments
			WHERE status = 'pending' AND type = 'receive' AND expire > '$now'");
	if (num_result($pending_receive) > 0) {
		$ch = curl_init();
		$data = array('jsonrpc' => '2.0', 
				'method' 		=> 'get_bulk_payments', 
				"id" 			=> "phpmonero",
				"params"		=> array()
		);
		$data["params"]["payment_ids"] = array();
		while ($tocheck = fetch_assoc($pending_receive)) {
			array_push($data["params"]["payment_ids"], $tocheck["payment_id"]);
		}
		curl_setopt($ch, CURLOPT_URL,"http://$xmr_wallet_addr/json_rpc");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
		curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec ($ch);
		$result = json_decode($server_output,true);
		$payments = array();
		usort($result["result"]["payments"], build_sorter('block_height'));
		foreach ($result["result"]["payments"] AS $index=>$val) {
			array_push($payments, array("block_height"	=>	$val["block_height"],
			"payment_id"		=>	$val["payment_id"],
			"unlock_time"		=>	$val["unlock_time"],
			"amount"			=>	$val["amount"],	
			"tx_hash"			=>	$val["tx_hash"]));
			$double_check = fetch_array(query("SELECT block_height FROM xmr_payments WHERE payment_id = '$val[payment_id]'"));
			if ($double_check["block_height"] < $val["block_height"])
				query("UPDATE xmr_payments SET amount = amount + $val[amount], block_height = $val[block_height] WHERE payment_id = '$val[payment_id]'");
		}
		curl_close ($ch);
	}
}
function xmr_client_transfer() {
	global $xmr_wallet_addr;
	$return = array();
	$pending_transfer = query("SELECT address, amount, id FROM xmr_payments
			WHERE status = 'pending' AND type = 'transfer' AND status = 'pending'");
	if (num_result($pending_transfer) > 0) {
		$payment_id = xmr_create_payment_id();
		$ch = curl_init();
		$data = array('jsonrpc' => '2.0',
				'method' 		=> 'transfer',
				"id" 			=> "phpmonero",
				"params"		=> array('destinations'		=> array(),
										'payment_id'		=>	$payment_id,
										'mixin'				=>	3,
										'unlock_time'		=>	0)
		);
		$return["payment_ids"]	= array();
		while ($totransfer = fetch_assoc($pending_transfer)) {
			array_push($data["params"]["destinations"], array('amount'	=>	(0 + $totransfer["amount"]),
															'address'	=>	$totransfer["address"]));
			$return["payment_ids"][]	=	$totransfer["id"];
		}
		curl_setopt($ch, CURLOPT_URL,"http://$xmr_wallet_addr/json_rpc");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
		curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec ($ch);
		if (curl_error($ch) != "")	$return["status"] = "error";
		else {
			
			foreach ($return["payment_ids"] AS $index=>$val) {
 				query("UPDATE xmr_payments SET payment_id = '$payment_id', status = 'complete' 
 				WHERE id = $val");
			}
			$return["payment_id"]	= $payment_id;
			$return["status"]		= 'complete';
		}
		return $return;
	}
}
function build_sorter($key) {
	return function ($a, $b) use ($key) {
		return strnatcmp($a[$key], $b[$key]);
	};
}
?>