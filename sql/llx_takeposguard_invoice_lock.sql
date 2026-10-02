-- TakePOS Payment Guard - GPL-3.0-or-later
-- Persistent ownership metadata; expiration alone does not authorize recovery.
CREATE TABLE llx_takeposguard_invoice_lock
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_invoice integer NOT NULL,
	operation_token varchar(36) NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	expires_at datetime NOT NULL
) ENGINE=innodb;
