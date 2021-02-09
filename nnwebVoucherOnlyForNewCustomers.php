<?php

namespace nnwebVoucherOnlyForNewCustomers;

use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;

class nnwebVoucherOnlyForNewCustomers extends \Shopware\Components\Plugin {

	public static function getSubscribedEvents() {
		return [
			'sBasket::sAddVoucher::replace' => 'replaceAddVoucher' 
		];
	}

	public function activate(ActivateContext $context) {
		$context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
		parent::activate($context);
	}

	public function deactivate(DeactivateContext $context) {
		$context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
		parent::deactivate($context);
	}

	public function install(InstallContext $context) {
		$service = $this->container->get('shopware_attribute.crud_service');
		
		$service->update('s_emarketing_vouchers_attributes', 'nnwebOnlyNewCustomers', 'boolean', [
			'label' => 'Nur für Neukunden', 
			'supportText' => 'Dieser Gutschein ist nur für Kunden gültig, die noch keine Bestellung im System haben.', 
			'translatable' => true, 
			'displayInBackend' => true, 
			'position' => 1 
		]);
	}

	public function uninstall(UninstallContext $context) {
		$service = $this->container->get('shopware_attribute.crud_service');
		$service->delete('s_emarketing_vouchers_attributes', 'nnwebOnlyNewCustomers');
	}

	public function replaceAddVoucher(\Enlight_Hook_HookArgs $args) {
		$voucherCode = $args->get('voucherCode');
		
		$db = Shopware()->Db();

		$voucherDetails = $db->fetchRow('
				SELECT *
	              FROM s_emarketing_vouchers v
				  LEFT JOIN s_emarketing_vouchers_attributes va ON va.voucherID = v.id
				  LEFT JOIN s_emarketing_voucher_codes vc ON vc.voucherID = v.id
	              WHERE v.vouchercode = ? OR vc.code = ?
				', [
			$voucherCode, $voucherCode
		]) ?: [];
		
		if (!empty($voucherDetails["nnwebonlynewcustomers"])) {
			
			$config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName($this->getName());
			$session = Shopware()->Session();
			$userId = $session->get('sUserId');
			$sErrorMessages = array();
			
			$user = null;
			if (!empty($userId)) {
				$user = $db->fetchRow('
	                SELECT accountmode, email FROM s_user
	                WHERE id = ?
	            ', [
					$userId 
				]);
				$accountmode = $user["accountmode"];
				$email = $user["email"];
			}
			
			if (!$config["nnwebVoucherOnlyForNewCustomers_allowForGuestAccounts"] && (empty($userId) || $accountmode == 1)) {
				
				$sErrorMessages[] = Shopware()->Application()->Snippets()->getNamespace('frontend/basket/internalMessages')->get('VoucherFailureNewCustomerGuestOrder', 'Dieser Gutschein kann nur mit einem Kundenkonto eingelöst werden. Bitte loggen Sie sich vorher ein.');
				$args->setReturn(array(
					"sErrorFlag" => true, 
					"sErrorMessages" => $sErrorMessages 
				));
			} elseif (!empty($userId)) {
				$count = 0;
				if ($accountmode == 0) {
					$count = (int) $db->fetchOne('
			            SELECT COUNT(*) FROM s_order
			            WHERE userID != 0 AND userID = ?
			            AND status >= 0
						', [
						$userId 
					]);
				} elseif ($accountmode == 1 && $config["nnwebVoucherOnlyForNewCustomers_checkMailForGuestAccounts"]) {
					$count = (int) $db->fetchOne('
			            SELECT COUNT(*) FROM s_order o
						LEFT JOIN s_user u ON u.id = o.userID
			            WHERE u.email = ?
			            AND o.status >= 0
						', [
						$email 
					]);
				}
				
				if ($count > 0) {
					$sErrorMessages[] = Shopware()->Application()->Snippets()->getNamespace('frontend/basket/internalMessages')->get('VoucherFailureNewCustomer', 'Dieser Gutschein ist nur für Neukunden gültig.');
					$args->setReturn(array(
						"sErrorFlag" => true, 
						"sErrorMessages" => $sErrorMessages 
					));
				} else {
					$args->setReturn($args->getSubject()->executeParent($args->getMethod(), $args->getArgs()));
				}
			} else {
				$args->setReturn($args->getSubject()->executeParent($args->getMethod(), $args->getArgs()));
			}
		} else {
			$args->setReturn($args->getSubject()->executeParent($args->getMethod(), $args->getArgs()));
		}
	}
}
