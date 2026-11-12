<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Payment\PaymentLinkService;

/**
 * Tâche planifiée d'une boutique (URL cron de la configuration, à appeler une fois par heure ou par jour) :
 *
 * 1. passe en « Expiré » les devis envoyés / vus dont la date de validité est dépassée, et désactive leurs liens ;
 * 2. relance une fois par e-mail (avec le PDF) les devis qui expirent dans N jours (réglage « Relance automatique ») ;
 * 3. désactive les liens de paiement encore actifs des devis refusés, expirés ou remplacés.
 *
 * Chaque étape est limitée par passage ; une erreur sur un devis est journalisée sans bloquer les autres.
 */
class CronRunner
{
    private const BATCH = 100;

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly QuoteSender $sender,
        private readonly QuoteStateMachine $stateMachine,
        private readonly PaymentLinkService $paymentLinks,
    ) {
    }

    /**
     * @return array{expired: int, reminded: int, links_revoked: int, errors: int}
     */
    public function run(int $idShop): array
    {
        $result = ['expired' => 0, 'reminded' => 0, 'links_revoked' => 0, 'errors' => 0];
        $this->expire($idShop, $result);
        $this->remind($idShop, $result);
        $this->revokeClosedLinks($idShop, $result);

        return $result;
    }

    /**
     * @param array{expired: int, reminded: int, links_revoked: int, errors: int} $result
     */
    private function expire(int $idShop, array &$result): void
    {
        $ids = $this->ids(
            '`id_shop` = ' . $idShop . ' AND `deleted` = 0 AND `id_order` IS NULL
             AND `status` IN (\'' . QuoteStatus::Sent->value . '\', \'' . QuoteStatus::Viewed->value . '\')
             AND `valid_until` IS NOT NULL AND `valid_until` < \'' . date('Y-m-d') . '\''
        );
        foreach ($ids as $id) {
            $quote = new \SntQuote($id);
            try {
                $this->stateMachine->apply($quote, QuoteStatus::Expired, 'Validity date passed', ['via' => 'cron'], \SntQuoteHistory::ACTOR_SYSTEM, null);
                $this->paymentLinks->revokeAll($quote, 'expired');
                ++$result['expired'];
            } catch (\Throwable $e) {
                $this->fail('expire', $quote, $e, $result);
            }
        }
    }

    /**
     * Une seule relance automatique par envoi : aucune relance depuis le dernier envoi au client.
     *
     * @param array{expired: int, reminded: int, links_revoked: int, errors: int} $result
     */
    private function remind(int $idShop, array &$result): void
    {
        $days = (int) $this->config->get(ConfigKeys::REMINDER_DAYS, $idShop);
        if ($days <= 0) {
            return;
        }
        $ids = $this->ids(
            '`id_shop` = ' . $idShop . ' AND `deleted` = 0 AND `id_order` IS NULL
             AND `status` IN (\'' . QuoteStatus::Sent->value . '\', \'' . QuoteStatus::Viewed->value . '\')
             AND `valid_until` IS NOT NULL
             AND `valid_until` >= \'' . date('Y-m-d') . '\'
             AND `valid_until` <= \'' . date('Y-m-d', strtotime('+' . $days . ' days')) . '\'
             AND (`last_reminder_at` IS NULL OR (`sent_at` IS NOT NULL AND `last_reminder_at` < `sent_at`))'
        );
        foreach ($ids as $id) {
            $quote = new \SntQuote($id);
            try {
                $this->sender->remind($quote);
                ++$result['reminded'];
            } catch (\Throwable $e) {
                $this->fail('remind', $quote, $e, $result);
            }
        }
    }

    /**
     * @param array{expired: int, reminded: int, links_revoked: int, errors: int} $result
     */
    private function revokeClosedLinks(int $idShop, array &$result): void
    {
        $closed = [QuoteStatus::Declined->value, QuoteStatus::Expired->value, QuoteStatus::Cancelled->value];
        $rows = \Db::getInstance()->executeS(
            'SELECT DISTINCT l.`id_snt_quote`
             FROM `' . _DB_PREFIX_ . 'snt_quote_payment_link` l
             INNER JOIN `' . _DB_PREFIX_ . 'snt_quote` q ON q.`id_snt_quote` = l.`id_snt_quote`
             WHERE q.`id_shop` = ' . $idShop . ' AND l.`revoked_at` IS NULL AND l.`used_at` IS NULL
               AND (q.`deleted` = 1 OR q.`status` IN (\'' . implode('\', \'', $closed) . '\'))
             LIMIT ' . self::BATCH
        ) ?: [];
        foreach ($rows as $row) {
            $quote = new \SntQuote((int) $row['id_snt_quote']);
            try {
                $this->paymentLinks->revokeAll($quote, 'closed');
                ++$result['links_revoked'];
            } catch (\Throwable $e) {
                $this->fail('revoke', $quote, $e, $result);
            }
        }
    }

    /**
     * @return int[]
     */
    private function ids(string $where): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_snt_quote` FROM `' . _DB_PREFIX_ . 'snt_quote` WHERE ' . $where . ' ORDER BY `id_snt_quote` LIMIT ' . self::BATCH
        ) ?: [];

        return array_map(static fn (array $r): int => (int) $r['id_snt_quote'], $rows);
    }

    /**
     * @param array{expired: int, reminded: int, links_revoked: int, errors: int} $result
     */
    private function fail(string $step, \SntQuote $quote, \Throwable $e, array &$result): void
    {
        ++$result['errors'];
        \PrestaShopLogger::addLog('[snt_quotes] cron ' . $step . ' ' . $quote->getFullReference() . ': ' . get_class($e) . ': ' . $e->getMessage(), 3, null, 'SntQuote', (int) $quote->id);
    }
}
