<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

/**
 * Façons de créer la commande d'un devis.
 */
enum PaymentMode: string
{
    /** Virement : module snt_quotes, statut « en attente de virement ». */
    case Transfer = 'transfer';
    /** Paiement différé B2B : module snt_quotes, statut dédié. */
    case Deferred = 'deferred';
    /** En attente de paiement (à régler plus tard, par tout moyen). */
    case Pending = 'pending';
    /** Carte par téléphone : commande au nom de HiPay Payments, encaissement MO/TO depuis la fiche commande. */
    case Phone = 'phone';
    /** Lien PayPlug : commande au nom de PayPlug, qui envoie lui-même le lien au client. */
    case PayPlugLink = 'payplug_link';
    /** Carte en ligne via la page HiPay : la commande est créée par HiPay à la notification. */
    case CardOnline = 'card_online';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Bank transfer',
            self::Deferred => 'Deferred payment (B2B)',
            self::Pending => 'Awaiting payment',
            self::Phone => 'Credit card by phone (HiPay MO/TO)',
            self::PayPlugLink => 'PayPlug payment link',
            self::CardOnline => 'Credit card (online)',
        };
    }

    /**
     * Moyen de paiement autorisé sur le devis correspondant (case cochée dans l'éditeur).
     */
    public function quoteMethod(): string
    {
        return match ($this) {
            self::Transfer => 'transfer',
            self::Deferred => 'deferred',
            self::Pending => 'pending',
            self::Phone, self::PayPlugLink, self::CardOnline => 'card',
        };
    }
}
