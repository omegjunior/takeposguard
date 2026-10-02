-- TakePOS Payment Guard - GPL-3.0-or-later
ALTER TABLE llx_takeposguard_payment_attempt ADD UNIQUE INDEX uk_tpg_attempt_invoice_token (entity, fk_invoice, operation_token);
-- A token cannot be reassigned to another invoice in the same entity.
ALTER TABLE llx_takeposguard_payment_attempt ADD UNIQUE INDEX uk_tpg_attempt_entity_token (entity, operation_token);
ALTER TABLE llx_takeposguard_payment_attempt ADD INDEX idx_tpg_attempt_invoice_date (entity, fk_invoice, datec);
ALTER TABLE llx_takeposguard_payment_attempt ADD INDEX idx_tpg_attempt_status_date (entity, status, datec);
ALTER TABLE llx_takeposguard_payment_attempt ADD INDEX idx_tpg_attempt_completed (entity, date_completed);
