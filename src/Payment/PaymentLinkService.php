<?php

declare(strict_types=1);

namespace Snt\Quotes\Payment;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Service\HistoryLogger;

/**
 * Liens de paiement signés : jeton aléatoire de 32 octets transmis une seule fois, seule son empreinte
 * SHA-256 est stockée ; expiration ; un seul lien actif par devis ; consommé dès qu'une commande est créée.
 */
class PaymentLinkService
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly HistoryLogger $history,
    ) {
    }

    /**
     * Crée un lien (et révoque le précédent). Retourne l'URL en clair — elle n'est plus récupérable ensuite.
     *
     * @return array{url: string, expires_at: string, link: \SntQuotePaymentLink}
     */
    public function create(\SntQuote $quote, ?int $ttlDays = null): array
    {
        if ($quote->id_order) {
            throw new QuoteException('This quote has already been ordered: no payment link is needed.');
        }
        if (!in_array($quote->getStatus(), [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Accepted], true)) {
            throw new QuoteException('Send the quote to the customer before creating a payment link.');
        }
        $this->revokeAll($quote, 'replaced');

        $ttlDays ??= max(1, $this->config->getInt(ConfigKeys::PAYLINK_TTL_DAYS, (int) $quote->id_shop));
        $expires = new \DateTimeImmutable('+' . $ttlDays . ' days');
        if ($quote->valid_until && $quote->getStatus() !== QuoteStatus::Accepted) {
            $validity = new \DateTimeImmutable($quote->valid_until . ' 23:59:59');
            $expires = min($expires, $validity);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $link = new \SntQuotePaymentLink();
        $link->id_snt_quote = (int) $quote->id;
        $link->token_hash = hash('sha256', $token);
        $link->purpose = 'pay';
        $link->methods = (string) json_encode($quote->getAllowedPaymentMethods());
        $link->expires_at = $expires->format('Y-m-d H:i:s');
        $link->add();

        $this->history->log($quote, \SntQuoteHistory::EVENT_PAYMENT_LINK, null, [
            'id_link' => (int) $link->id,
            'expires_at' => $link->expires_at,
        ]);

        return ['url' => $this->url($token, $quote), 'expires_at' => $link->expires_at, 'link' => $link];
    }

    public function url(string $token, \SntQuote $quote): string
    {
        return \Context::getContext()->link->getModuleLink('snt_quotes', 'pay', ['t' => $token], true, (int) $quote->id_lang, (int) $quote->id_shop);
    }

    public function resolve(string $token): ?\SntQuotePaymentLink
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            return null;
        }
        $id = (int) \Db::getInstance()->getValue(
            'SELECT `id_snt_quote_payment_link` FROM `' . _DB_PREFIX_ . 'snt_quote_payment_link`
             WHERE `token_hash` = \'' . pSQL(hash('sha256', $token)) . '\''
        );

        return $id ? new \SntQuotePaymentLink($id) : null;
    }

    public function revokeAll(\SntQuote $quote, string $reason = 'revoked'): void
    {
        \Db::getInstance()->update(
            'snt_quote_payment_link',
            ['revoked_at' => date('Y-m-d H:i:s')],
            '`id_snt_quote` = ' . (int) $quote->id . ' AND `used_at` IS NULL AND `revoked_at` IS NULL'
        );
    }

    /**
     * Dernier lien du devis (pour le bloc « Lien de paiement » de la fiche).
     *
     * @return array<string, mixed>|null
     */
    public function latest(\SntQuote $quote): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'snt_quote_payment_link` WHERE `id_snt_quote` = ' . (int) $quote->id . '
             ORDER BY `id_snt_quote_payment_link` DESC'
        );
        if (!$row) {
            return null;
        }
        $row['state'] = match (true) {
            (bool) $row['used_at'] => 'used',
            (bool) $row['revoked_at'] => 'revoked',
            strtotime((string) $row['expires_at']) < time() => 'expired',
            default => 'active',
        };

        return $row;
    }
}
