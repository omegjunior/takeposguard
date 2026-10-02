<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Partial-payment eligibility and request-local native stock configuration. */
class TakeposguardPaymentPolicy
{
	// Native facture.fk_statut/type values in Dolibarr 22.x.
	const DRAFT = 0;
	const VALIDATED = 1;
	const CREDIT_NOTE = 2;

	private $settings;
	private $key = '';
	private $previous;
	private $existed = false;
	private $shutdownRegistered = false;

	/** @return string Empty on eligibility, otherwise a translated error key */
	public function checkInvoice($invoice)
	{
		$status = (int) $invoice->status;
		if ($status === self::DRAFT) {
			return ''; // Native validation remains responsible for lines, stock and totals.
		}
		if ($status !== self::VALIDATED) {
			return 'TakeposguardPaymentInvoiceNotPayable';
		}
		try {
			$remain = $invoice->getRemainToPay();
		} catch (Throwable $exception) {
			return 'TakeposguardPaymentTechnicalFailure';
		}
		if (!empty($invoice->error) || !is_numeric($remain) || !is_finite((float) $remain)) {
			return 'TakeposguardPaymentTechnicalFailure';
		}
		// Mirror native credit-note direction; amounts from the browser are irrelevant.
		$credit = (int) $invoice->type === self::CREDIT_NOTE;
		return ((!$credit && $remain > 0) || ($credit && $remain < 0))
			? '' : 'TakeposguardPaymentInvoiceNotPayable';
	}

	/** @return bool Apply only after successful initial validation has been proven */
	public function suppressStock($terminal)
	{
		global $conf;
		if ($this->key !== '' || !is_string($terminal)
			|| !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $terminal)) {
			return false;
		}
		$this->settings = $conf->global;
		$this->key = 'CASHDESK_NO_DECREASE_STOCK'.$terminal;
		$this->existed = property_exists($this->settings, $this->key);
		$this->previous = $this->existed ? $this->settings->{$this->key} : null;
		if (!$this->shutdownRegistered) {
			// Only restore configuration here: no success inference or lock release.
			register_shutdown_function(array($this, 'restore'));
			$this->shutdownRegistered = true;
		}
		$this->settings->{$this->key} = '1';
		return true;
	}

	/** @return void Restore exactly the original property, also on early exit */
	public function restore()
	{
		if ($this->key === '') {
			return;
		}
		if ($this->existed) {
			$this->settings->{$this->key} = $this->previous;
		} else {
			unset($this->settings->{$this->key});
		}
		$this->key = '';
		$this->settings = null;
		$this->previous = null;
	}
}
