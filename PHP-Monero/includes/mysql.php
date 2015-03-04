<?php
function query($query, $fa = false) {
	global $connection;
	$result = mysql_query($query, $connection);
	if ($fa) {
		mysql_fetch_array($result);
	}
	else return $result;
}
function get_inserted_id() {
	global $connection;
	return mysql_insert_id($connection);
}
function fetch_array($query) {
	return mysql_fetch_array($query);
}
function fetch_assoc($query) {
	return mysql_fetch_assoc($query);
}
function num_result($result) {
	return mysql_num_rows($result);
}
function escape_strings ($text) {
	return mysql_escape_string($test);
}
function db_connect () {
	global $connection;
	$connection = mysql_connect("localhost","db_user","db_password");
	mysql_select_db("database", $connection);
}
db_connect();
?>