CREATE TABLE IF NOT EXISTS `xmr_payments` (
  `id` int(9) NOT NULL AUTO_INCREMENT,
  `type` enum('receive','transfer') NOT NULL,
  `address` char(95) DEFAULT NULL,
  `payment_id` varchar(64) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expire` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `status` enum('pending','complete') NOT NULL,
  `block_height` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;
