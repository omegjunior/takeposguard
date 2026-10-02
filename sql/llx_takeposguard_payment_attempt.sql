-- TakePOS Payment Guard - GPL-3.0-or-later
-- Amounts use the invoice's main currency. Requested values are audit data only.
CREATE TABLE llx_takeposguard_payment_attempt
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_invoice integer NOT NULL,
	operation_token varchar(36) NOT NULL,
	fk_user integer NOT NULL,
	terminal varchar(32),
	payment_code varchar(16),
	requested_amount double(24,8),
	actual_amount double(24,8),
	remain_before double(24,8) NOT NULL,
	remain_after double(24,8),
	invoice_status_before smallint NOT NULL,
	invoice_status_after smallint,
	payment_count_before integer DEFAULT 0 NOT NULL,
	last_payment_before integer DEFAULT 0 NOT NULL,
	fk_payment integer,
	status varchar(16) NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	date_completed datetime,
	error_code varchar(64),
	error_message varchar(255),
	ip_address varchar(45),
	user_agent varchar(255)
) ENGINE=innodb;
