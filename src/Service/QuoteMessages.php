<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Fil d'échanges client ↔ équipe d'un devis (espace « Mes devis » et fiche BO).
 */
class QuoteMessages
{
    public const MAX_LENGTH = 3000;

    public function __construct(private readonly HistoryLogger $history)
    {
    }

    public function add(\SntQuote $quote, string $authorType, int $authorId, string $text): \SntQuoteMessage
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            throw new QuoteException('Write your message before sending it.');
        }
        $message = new \SntQuoteMessage();
        $message->id_snt_quote = (int) $quote->id;
        $message->author_type = $authorType;
        $message->author_id = $authorId;
        $message->message = mb_substr($text, 0, self::MAX_LENGTH);
        if (!$message->add()) {
            throw new \RuntimeException('Unable to save the message.');
        }
        $this->history->log($quote, \SntQuoteHistory::EVENT_NOTE, null, [
            'message' => (int) $message->id,
            'from' => $authorType,
        ], null, null, $authorType === 'customer' ? \SntQuoteHistory::ACTOR_CUSTOMER : null, $authorType === 'customer' ? $authorId : null);

        return $message;
    }

    /**
     * Messages du devis et de ses versions précédentes, du plus ancien au plus récent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thread(\SntQuote $quote): array
    {
        $root = (int) ($quote->id_root_quote ?: $quote->id);
        $rows = \Db::getInstance()->executeS(
            'SELECT m.*, e.`firstname` AS employee_firstname, e.`lastname` AS employee_lastname,
                    c.`firstname` AS customer_firstname, c.`lastname` AS customer_lastname
             FROM `' . _DB_PREFIX_ . 'snt_quote_message` m
             INNER JOIN `' . _DB_PREFIX_ . 'snt_quote` q ON q.`id_snt_quote` = m.`id_snt_quote`
             LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (m.`author_type` = \'employee\' AND e.`id_employee` = m.`author_id`)
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (m.`author_type` = \'customer\' AND c.`id_customer` = m.`author_id`)
             WHERE q.`id_snt_quote` = ' . $root . ' OR q.`id_root_quote` = ' . $root . '
             ORDER BY m.`date_add` ASC, m.`id_snt_quote_message` ASC'
        ) ?: [];
        foreach ($rows as &$row) {
            $row['author_name'] = $row['author_type'] === 'employee'
                ? trim($row['employee_firstname'] . ' ' . $row['employee_lastname'])
                : trim($row['customer_firstname'] . ' ' . $row['customer_lastname']);
            $row['date_display'] = \Tools::displayDate((string) $row['date_add'], true);
        }

        return $rows;
    }

    /**
     * Marque comme lus les messages écrits par l'autre partie.
     */
    public function markRead(\SntQuote $quote, string $readerType): void
    {
        $author = $readerType === 'employee' ? 'customer' : 'employee';
        \Db::getInstance()->update(
            'snt_quote_message',
            ['read_at' => date('Y-m-d H:i:s')],
            '`id_snt_quote` = ' . (int) $quote->id . ' AND `author_type` = \'' . $author . '\' AND `read_at` IS NULL'
        );
    }

    public function unreadForEmployee(\SntQuote $quote): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'snt_quote_message`
             WHERE `id_snt_quote` = ' . (int) $quote->id . ' AND `author_type` = \'customer\' AND `read_at` IS NULL'
        );
    }
}
