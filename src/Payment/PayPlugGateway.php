<?php

declare(strict_types=1);

namespace Snt\Quotes\Payment;

/**
 * PayPlug (defense-privee) : une commande créée au nom de PayPlug avec son statut « lien de paiement par e-mail »
 * déclenche la création du lien et son envoi au client par PayPlug (Hook::actionOrderStatusUpdate → createPaymentLinkAction).
 * Code lu en v4.22.0 et v5.1.1.
 */
class PayPlugGateway
{
    public function isAvailable(): bool
    {
        return \Module::isEnabled('payplug') && $this->emailLinkStateId() > 0;
    }

    /**
     * Statut « lien de paiement par e-mail » de PayPlug, en mode test ou production selon sa configuration.
     */
    public function emailLinkStateId(): int
    {
        $sandbox = (bool) \Configuration::get('PAYPLUG_SANDBOX_MODE');

        return (int) \Configuration::get($sandbox ? 'PAYPLUG_ORDER_STATE_EMAIL_LINK_TEST' : 'PAYPLUG_ORDER_STATE_EMAIL_LINK');
    }
}
