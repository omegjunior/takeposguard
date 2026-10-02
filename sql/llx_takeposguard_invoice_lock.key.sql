-- TakePOS Payment Guard - GPL-3.0-or-later
ALTER TABLE llx_takeposguard_invoice_lock ADD UNIQUE INDEX uk_tpg_lock_invoice (entity, fk_invoice);
ALTER TABLE llx_takeposguard_invoice_lock ADD INDEX idx_tpg_lock_expiration (entity, expires_at);
