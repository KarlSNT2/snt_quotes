<?php

declare(strict_types=1);

namespace Snt\Quotes\Payment;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * HiPay Payments (hipaypayments v4.x PS 8 / v5.x PS 9) — page de paiement hébergée sur le panier technique.
 *
 * On construit nous-mêmes la requête (le CardRequestBuilder v5 ne garantit plus une page hébergée) et on n'utilise
 * du module HiPay que le service « hp.sdk.gateway » (identifiants configurés dans HiPay). La notification arrive au
 * module HiPay (orderid = "{id_cart}-{aléa}"), qui crée lui-même la commande ; notre hook actionValidateOrder
 * fait le post-traitement.
 */
class HiPayGateway
{
    public function isAvailable(): bool
    {
        return \Module::isEnabled('hipaypayments')
            && class_exists('HiPay\\Fullservice\\Gateway\\Request\\Order\\HostedPaymentPageRequest');
    }

    /**
     * @param array{accept: string, decline: string, pending: string, exception: string, cancel: string} $returnUrls
     *
     * @return string URL de la page de paiement HiPay
     */
    public function hostedPageUrl(\SntQuote $quote, \Cart $cart, float $totalTi, float $shippingTi, array $returnUrls): string
    {
        $module = \Module::getInstanceByName('hipaypayments');
        if (!$module || !$this->isAvailable() || !method_exists($module, 'getService')) {
            throw new QuoteException('Online card payment is not available on this shop.');
        }
        $idShop = (int) $cart->id_shop;
        $idShopGroup = (int) $cart->id_shop_group;
        $settings = $module->getService('hp.settings.loader')->withContext($idShop, $idShopGroup, true);

        $customer = new \Customer((int) $cart->id_customer);
        $billing = new \Address((int) $cart->id_address_invoice);
        $shipping = new \Address((int) $cart->id_address_delivery);
        $currency = (string) \Currency::getIsoCodeById((int) $cart->id_currency);
        $language = new \Language((int) $cart->id_lang);

        $request = new \HiPay\Fullservice\Gateway\Request\Order\HostedPaymentPageRequest();
        $request->orderid = sprintf('%d-%s', (int) $cart->id, substr(bin2hex(random_bytes(4)), 0, 8));
        $request->operation = $this->operation($settings);
        $request->description = sprintf('Devis %s', $quote->getFullReference());
        $request->currency = $currency;
        $request->amount = round($totalTi, 2);
        $request->shipping = round($shippingTi, 2);
        $request->language = str_replace('-', '_', (string) ($language->locale ?: 'fr-FR'));
        $request->http_accept = '*/*';

        $billingInfo = new \HiPay\Fullservice\Gateway\Request\Info\CustomerBillingInfoRequest();
        $billingInfo->firstname = $customer->firstname;
        $billingInfo->lastname = $customer->lastname;
        $billingInfo->email = $customer->email;
        $billingInfo->country = (string) \Country::getIsoById((int) $billing->id_country);
        $billingInfo->city = $billing->city;
        $billingInfo->zipcode = $billing->postcode;
        $billingInfo->streetaddress = $billing->address1;
        $billingInfo->streetaddress2 = $billing->address2;
        $billingInfo->phone = $billing->phone ?: $billing->phone_mobile;
        $billingInfo->recipientinfo = $billing->company;
        $request->customerBillingInfo = $billingInfo;

        $shippingInfo = new \HiPay\Fullservice\Gateway\Request\Info\CustomerShippingInfoRequest();
        $shippingInfo->shipto_firstname = $customer->firstname;
        $shippingInfo->shipto_lastname = $customer->lastname;
        $shippingInfo->shipto_country = (string) \Country::getIsoById((int) $shipping->id_country);
        $shippingInfo->shipto_city = $shipping->city;
        $shippingInfo->shipto_zipcode = $shipping->postcode;
        $shippingInfo->shipto_streetaddress = $shipping->address1;
        $shippingInfo->shipto_streetaddress2 = $shipping->address2;
        $shippingInfo->shipto_phone = $shipping->phone ?: $shipping->phone_mobile;
        $shippingInfo->shipto_recipientinfo = $shipping->company;
        $request->customerShippingInfo = $shippingInfo;

        $paymentMethod = new \HiPay\Fullservice\Gateway\Request\PaymentMethod\CardTokenPaymentMethod();
        $paymentMethod->eci = 7; // e-commerce : 3DS selon la configuration HiPay (jamais MO/TO)
        $paymentMethod->authentication_indicator = $this->threeDs($settings);
        $request->paymentMethod = $paymentMethod;

        $codes = $this->cardCodes($settings, $cart);
        if ($codes !== '') {
            $request->payment_product_list = $codes;
        }
        $request->accept_url = $returnUrls['accept'];
        $request->decline_url = $returnUrls['decline'];
        $request->pending_url = $returnUrls['pending'];
        $request->exception_url = $returnUrls['exception'];
        $request->cancel_url = $returnUrls['cancel'];
        // Notification vers le module HiPay : c'est lui qui crée la commande.
        $request->notify_url = \Context::getContext()->link->getModuleLink('hipaypayments', 'notify', [], true, (int) $cart->id_lang, $idShop);
        $request->display_cancel_button = true;
        $request->source = ['source' => 'CMS', 'brand' => 'prestashop', 'brand_version' => _PS_VERSION_, 'integration_version' => 'snt_quotes'];

        try {
            $transaction = $module->getService('hp.sdk.gateway')
                ->init($idShop, $idShopGroup, \HiPay\PrestaShop\Api\Credentials::CREDENTIALS_TYPE_MAIN)
                ->server()
                ->requestHostedPaymentPage($request);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] HiPay hosted page: ' . $e->getMessage(), 3, null, 'SntQuote', (int) $quote->id);
            throw new QuoteException('The payment page could not be opened. Try again in a few minutes or contact us.');
        }
        $url = (string) $transaction->getForwardUrl();
        if ($url === '') {
            throw new QuoteException('The payment page could not be opened. Try again in a few minutes or contact us.');
        }

        return $url;
    }

    private function operation(object $settings): string
    {
        $map = defined('HiPay\\PrestaShop\\Settings\\Entity\\MainSettings::OPERATION_VALUE')
            ? \HiPay\PrestaShop\Settings\Entity\MainSettings::OPERATION_VALUE
            : ['auto' => 'Sale', 'manual' => 'Authorization'];
        $mode = $settings->mainSettings->captureMode ?? 'auto';

        return (string) ($map[$mode] ?? 'Sale');
    }

    private function threeDs(object $settings): int
    {
        $map = defined('HiPay\\PrestaShop\\Settings\\Entity\\CardPaymentSettings::THREE_DS_MODES')
            ? \HiPay\PrestaShop\Settings\Entity\CardPaymentSettings::THREE_DS_MODES
            : ['if_available' => 1];
        $mode = $settings->cardPaymentSettings->threeDSMode ?? 'if_available';

        return (int) ($map[$mode] ?? 1);
    }

    private function cardCodes(object $settings, \Cart $cart): string
    {
        try {
            $codes = $settings->cardPaymentSettings->getCardPaymentsCodes($cart);
        } catch (\Throwable $e) {
            return '';
        }

        return is_array($codes) ? implode(',', $codes) : '';
    }
}
