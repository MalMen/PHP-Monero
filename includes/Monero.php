<?php
/**
 * PHP-Monero API
 *
 * Provides a way for PHP developers to send
 * commands to a Monero wallet RPC server.
 *
 * @filesource
 * @license GNU General Public License, version 2
 * @link https://github.com/MalMen/PHP-Monero
 * @api
 */

/**
 * Namespace
 *
 * Can be changed to suit your project
 */
namespace includes;

/**
 * PHP-Monero API
 *
 * API object which allows interface with a 
 * Monero RPC server.  Configuration may be set 
 * in the class itself or as arguments to the
 * constructor.
 *
 * @TODO actually test everything
 */
class PHP_Monero 
{

    /** @var string Human readable time until payment expires. Must be compatible with strtotime() */
    private $expire_payments    = '+1 day';
    
    /** @var string IP:Port of the wallet daemon.  Usually 127.0.0.1:18082 */
    private $rpc_address        = 'ip:port';

    /** @var string Full address of the Monero wallet. */
    private $wallet_address     = '/4([0-9]|[A-B])(.){93}/';    // wallet full address
    
    /** @var string Monero wallet OpenAlias address */
    private $open_alias         = "donate.getmonero.org";
    
    /** @var object Instance of MySQLi class. */
    private $mysqli;


    /**
     * Initialize the class, override defaults if needed
     *
     * The constructor takes a mysqli object and injects it into the $mysqli property.
     * It also checks to see if any of the configuration properties have been passed
     * as an argument and if so will change them.  To change only certain variables,
     * pass an empty string to those you wish to leave unchanged.
     *
     * @author Jacob Briggs <jebriggsy@gmail.com>
     * @param object $mysqli An instance of the MySQLi class
     * @param string $expire strtotime() compatible string describing expiration date of payment
     * @param string $rpc_address IP:Port of the wallet daemon
     * @param string $wallet_address Full address of the Monero wallet
     * @param string $wallet_alias OpenAlias of the Monero wallet
     * @return bool|self Returns false if configuration error, self if success
     * @throws \Exception
     * @uses \PHP-Monero\Monero::validate_address()
     */ 
    public function __construct(
        \mysqli &$mysqli,
        string $expire_payments = '',
        string $rpc_address     = '',
        string $wallet_address  = '',
        string $open_alias      = ''
    ) {
        // Make database available to other methods
        $this->mysqli = &$mysqli;
        
        // Change default configuration options if requested
        $this->expire_payments  = empty($expire_payments)   ? $this->expire_payments    : $expire_payments;
        $this->rpc_address      = empty($rpc_address)       ? $this->rpc_address        : $rpc_address;
        $this->wallet_address   = empty($wallet_address)    ? $this->wallet_address     : $wallet_address;
        $this->open_alias       = empty($open_alias)        ? $this->open_alias         : $open_alias;
    
        // Validate configuration
        if (!strtotime($this->expire_payments) {
            throw new \Exception('Payment expiration configuration value is invalid.');
            return false;
        }
        if (!preg_match (
            '/((0|1[0-9]{0,2}|2[0-9]?|2[0-4][0-9]|25[0-5]|[3-9][0-9]?)\.){3}(0|1[0-9]{0,2}|2[0-9]?|2[0-4][0-9]|25[0-5]|[3-9][0-9]?):([0-9]{1,5})/',
            $this->rpc_address
            )) {
            throw new \Exception('RPC address configuration value is invalid.');
            return false;
        }
        if (!$this->validate_address($this->wallet_address)) {
            throw new \Exception('Wallet address configuration value is invalid.');
            return false;
        }

        // @TODO validate OpenAlias address

        return true;

    }

    /**
     * Create a new Monero payment ID
     *
     * Generates a new 32 character Monero payment ID and then
     * queries the database to make sure it is unique.  Uses
     * recursion until a unique ID has ben generated.
     *
     * @return bool|string Returns false if failure or a 32 character string if successful
     * @throws \Exception
     */
    public function create_payment_id() 
    {
        // Generate an ID
        $payment_id = bin2hex(openssl_random_pseudo_bytes(32));
        
        // Check the table for duplicates
        if ($check = $this->mysqli->prepare('SELECT `id` FROM `xmr_payments` WHERE `payment_id` = ?')) {
            $check->bind_param('s', $payment_id);
            $check->execute();
            $check->store_result();
            
            // Recursion if not unique
            if ($check->num_rows > 0) {
                $check->free_result();
                $this->create_payment_id();
            } else {
                $check->free_result();
                return $payment_id;
            }
            
            // Unknown error, this code should never be executed
            throw new \Exception('Unknown error in PHP_Monero::create_payment_id()');
            return false;
        } else {
            // Database failure
            throw new \Exception('Could not query the XMR payments table.');
            return false;
        }
        
        // Unknown error, this code should never be executed
        throw new \Exception('Unknown error in PHP_Monero::create_payment_id()');
        return false;
    }
    
    /**
     * Create a new payment address
     *
     * Generate a new payment ID and then insert it into the database
     *
     * @param int $xmr The amount in Moneroj to be paid
     * @return bool|array False on failure, an array containing payment information on success
     * @throws \Exception
     * @uses \PHP-Monero\Monero::create_payment_id()
     */
    public function create_payment_address(float $xmr = 0.0)
    {        
        // @TODO
        // explain why this is multiplied
        $amount = $xmr * 1000000000000;
        
        // Generate the values
        $payment_id = $this->create_payment_id();        
        $expire = date('Y-m-d H:i:s', strtotime($this->expire_payments));
        
        // Definitely want atomicity here.
        $this->mysqli->begin_transaction();
        if ($stmt = $this->mysqli->prepare('
                                INSERT INTO xmr_payments (type, payment_id, amount, status, expire)
                                VALUES (\'receive\', ?,?,\'pending\', ?)'
                                )
        ) {
            $stmt->bind_param('sss', $payment_id, $amount, $expire);
            $stmt->execute();

            // Commit and return on success
            if ($stmt->affected_rows == 1) {
                $stmt->close();
                $this->mysqli->commit();

                return array (
                    'status'        => 'pending',
                    'payment_id'    => $payment_id,
                    'type'          => 'receive',
                    'added'         => date('Y-m-d H:i:s'),
                    'address'       => $this->wallet_address,
                    'openalias'     => $this->open_alias
                );
            } else {
                // Some kind of insert failure
                // Rollback and throw exception
                $stmt->close();
                $this->mysqli->rollback();
                throw new \Exception('Failed to create a Monero payment address');
                return false;
            }
        } else {
            // Database error
            $this->mysqli->rollback();
            throw new \Exception('Could not query the XMR payments table.');
            return false;
        }

        // Unknown error, this code should never execute
        throw new \Exception('Unknown error');
        return false;
    }
    
    
    /**
     * Send a transfer to the database
     *
     * @param string $address The Monero wallet address to tranfer to
     * @param float $xmr Amount of Moneroj to transfer
     * @return bool|array False on error, array containing transfer information on success
     * @throws \Exception
     * @uses \PHP-Monero\Monero::validate_address()
     */
    public function transfer(string $address, float $xmr = 0.0)
    {  
        // Make sure we aren't sending to a bogus address
        if (!$this->validate_address($address)) {
            throw new \Exception('Tried to transfer Moneroj to an invalid wallet address.');
            return false;
        }

        // @TODO
        // Seriously, what is with this?
        $amount = $xmr * 1000000000000;
        
        // Make sure we are actually transferring something
        if ($amount == 0) {
            throw new \Exception('Tried to transfer 0 Moneroj.');
            return false;
        }
        
        // Anything involving value should be a transaction
        $this->mysqli->begin_transaction();
        if ($stmt = $this->mysqli->prepare('INSERT INTO `xmr_payments` (`type`, `address`, `amount`, `status`)
                                            VALUES (\'transfer\', ?, ?, \'pending\')')
        ) {
            $stmt->bind_param('sd', $address, $amount);
            $stmt->execute();

            // Check result & return
            if ($stmt->affected_rows == 1) {
                // Success, commit, etc
                $stmt->close();
                $this->mysqli->commit();

                return array (
                    'type'      =>  'transfer',
                    'address'   =>  $address,
                    'amount'    =>  $amount,
                    'status'    =>  'pending'
                );
            } else {
                // Insertion error
                $stmt->close();
                $this->mysqli->rollback();

                throw new \Exception('Error commiting Monero transfer to database.');
                return false;
            }

            // This code should never execute
            throw new \Exception('Unknown PHP-Monero::transfer() error');
            return false;
        }

        // This code should never execute
        throw new \Exception('Unknown PHP-Monero::transfer() error');
        return false;
    }

        
    /**
     * Receive pending payments
     *
     * Queries the database for pending payments and then
     * queries the wallet via RPC to determine which 
     * payments have been received, updating the database
     * as it goes.
     *
     * @return bool True on success false on failure
     * @throws \Exception
     */
    public function client_receive()
    {      
        // Convert UNIX timestamp to SQL timestamp
        $now = date('Y-m-d H:i:s');
        
        // Receive all pending payment IDs 
        if ($pending = $this->mysqli->prepare('SELECT `payment_id` FROM `xmr_payments`
                                                WHERE `status` = \'pending\' AND `type` = \'receive\' AND `expire` > ?')
        ) {
            $pending->bind_param('s', $now);
            $pending->execute();
            $pending->store_result();
            
            // Do we even have any pending payments to receive?
            if ($pending->num_rows > 0) {
                // Initalize the cURL request
                $ch = curl_init();
                $data = array(
                            'jsonrpc'       => '2.0', 
                            'method'        => 'get_bulk_payments', 
                            'id'            => 'phpmonero',
                            'params'        => array(
                                                'payment_ids'   => array()
                                               )
                );
        
                // Loop through
                $pending->bind_result($payment_id);
                while ($pending->fetch()) {
                    array_push($data['params']['payment_ids'], $payment_id);
                }
                
                // Clean up database request
                $pending->free_result();
                $pending->close();

                // Set up the cURL RPC request
                curl_setopt($ch, CURLOPT_URL, 'http://' . $this->rpc_address . '/json_rpc');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
                curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                
                // Get the RPC response
                $server_output = curl_exec ($ch);
                $result = json_decode($server_output,true);
                
                // Build a usable array
                $payments = array();
                usort($result["result"]["payments"], build_sorter('block_height'));                
                
                /**
                 * Loop through and check/update database
                 * Thank god for prepared statements I guess
                 * @TODO optimize this better
                 */

                // Prepare the SELECT statement or fail
                if (!$check = $this->mysqli->prepare('SELECT `block_height` FROM `xmr_payments` WHERE `payment_id` = ?')) {
                    throw new \Exception('Could not prepare the database to confirm Monero client payment receipts.');
                    return false;
                }

                // Prepare the UPDATE statement or fail
                if (!$update = $this->mysqli->prepare('UPDATE `xmr_payments` SET `amount` = amount + ?, `block_height` = ? 
                                                        WHERE `payment_id` = ?')
                ) {
                    throw new \Exception('Could not prepare the database to update Monero payment receipts.');
                    return false;
                }
                
                // Transaction for atomicity and optimization.
                $this->mysqli->begin_transaction();

                // And lets loop
                foreach ($result["result"]["payments"] as $index => $val) {
                    array_push($payments, array(
                                            'block_height'      =>  $val['block_height'],
                                            'payment_id'        =>  $val['payment_id'],
                                            'unlock_time'       =>  $val['unlock_time'],
                                            'amount'            =>  $val['amount'], 
                                            'tx_hash'           =>  $val['tx_hash']
                    ));
                
                    // Query this ID
                    $check->bind_param('s', $val['payment_id']);
                    $check->execute();
                    $check->store_result();
                
                    // If we have a result, check it
                    if ($check->num_rows == 1) {
                        $check->bind_result($block_height);
                        $check->fetch();
                    
                        // If database out of sync, sync it
                        if ($block_height < $val['block_height']) {
                            $update->bind_param('dis', $val['amount'], $val['block_height'], $val['payment_id'])
                            $update->execute();
                        
                            // Make sure it succeeded
                            if ($update->affected_rows != 1) {
                                $update->close();
                                $this->mysqli->rollback();
                                throw new \Exception('Could not sync database and client.');
                                return false;
                            } elseif ($update->affected_rows > 1) {
                                $update->close();
                                $this->mysqli->rollback();
                                throw new \Exception('Multiple rows with same payment id.');
                                return false;
                            }
                        
                            // Prepare for next loop
                            $update->reset();
                        }
                    } elseif ($check->num_rows > 1) {
                        $check->close();
                        $this->mysqli->rollback();
                        throw new \Exception('Multiple rows with same payment id.');
                        return $payments;
                    }

                    // Get ready for the next loop
                    $check->free_result();
                    $check->reset();
                }

                // If we got here, everything went fine.  Commit & clean up
                $check->close();
                $update->close();
                $this->mysqli->commit();              
                curl_close($ch);
                return true;
            }

            // Nothing to do
            return true;
        }

        // If we get here then we couldn't query the database
        throw new \Exception('Tried to sync Monero client and database but couldn\'t query the database.');
        return false;
    }
    
    /**
     * Sync transfers between database and client
     */
    public function client_transfer(integer $mixin=3) 
    {        
        // Make sure we have a sane mixin
        if ($mixin < 3) {
            throw new \Exception('Protocol requires mixin > 3');
            return false;
        }
        
        
        $return = array();
        
        if ($pending = $this->mysqli->prepare('SELECT `address`, `amount`, `id`
                                                FROM `xmr_payments`
                                                WHERE `status` = \'pending\' AND `type` = \'transfer\'')
        ) {
            $pending->execute();
            $pending->store_result();
            
            // Is there anything to do?
            if ($pending->num_rows > 0) {
                $payment_id = $this->create_payment_id();
                
                $ch = curl_init();
                $data = array (
                            'jsonrpc'           => '2.0',
                            'method'            => 'transfer',
                            'id'                => 'phpmonero',
                            'params'            => array('destinations'     => array(),
                            'payment_id'        =>  $payment_id,
                            'mixin'             =>  $mixin,
                            'unlock_time'       =>  0
                            )
                );
                $return["payment_ids"]  = array();
                
                // Array magic
                $pending->bind_result($address, $amount, $id);
                while ($pending->fetch()) {
                    array_push(
                        $data['params']['destinations'], 
                        array(
                            'amount'    =>  0 + $amount,
                            'address'   =>  $address
                            )
                    );
                    $return['payment_ids'][]    =   $id;
                }
            
                // Send it to the RPC
                curl_setopt($ch, CURLOPT_URL, 'http://' . $this->wallet_address . '/json_rpc');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
                curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $server_output = curl_exec ($ch);
                
                if (curl_error($ch)) {
                    throw new \Exception('Error sending Monero transfers to the RPC server.');
                    return false;
                }  
                
                $this->mysqli->begin_transaction();
                if ($update = $this->mysqli->prepare('UPDATE `xmr_payments` 
                                                        SET `payment_id` = ?, status = \'complete\'
                                                        WHERE `id` = ?')
                ) {
                    
                    // Sync the database
                    foreach ($return['payment_ids'] as $index => $val) {
                        $update->bind_param('ss', $payment_id, $val);
                        $update->execute();
                           
                        if ($update->affected_rows != 1) {
                            $update->close();
                            $this->mysqli->rollback();
                            throw new \Exception('Error updating database after sending transfers to RPC.');
                            return false;
                        }
                            
                        // Prepare for next loop
                        $update->reset();                          
                    }
                        
                    // Success
                    $update->close();
                    $pending->free_result();
                    $pending->close();
                    $this->mysqli->commit();
                    $return["payment_id"]   = $payment_id;
                    $return["status"]       = 'complete';
                    return $return;
                } 
                
                // Above code should return, if we are here
                // it is an error
                $this->mysqli->rollback();
                throw new \Exception('Could not prepare query to sync database and RPC.');
                return false;                   
            }

            // If we're here it was a db error
            throw new \Exception('Could not query the database to get new transfers.');
            return false;
        }
    }

    // @TODO document this
    private function build_sorter($key) {
        return function ($a, $b) use ($key) {
            return strnatcmp($a[$key], $b[$key]);
        };
    }

    /**
     * Validates a Monero wallet address
     *
     * Always starts with 4
     * Second character is always between 0-9 or A or B
     * Always 95 characters
     *
     * @author Jacob Briggs <jebriggsy@gmail.com>
     * @param string $address The Monero wallet address to validate
     * @return bool True if a valid address, false if not
     */
    public function validate_address(string $address)
    {
        if (
            substr($address, 0) != '4' ||
            !preg_match('/([0-9]|[A-B])/', substr($address, 1)) ||
            strlen($address) != 95
        ) {
            return false;
        }

        return true;    
    }
