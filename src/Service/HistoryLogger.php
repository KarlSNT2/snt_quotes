<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

/**
 * Écrit le journal d'un devis. L'acteur est déduit du contexte si non fourni.
 */
class HistoryLogger
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function log(
        \SntQuote $quote,
        string $event,
        ?string $comment = null,
        ?array $payload = null,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?string $actorType = null,
        ?int $actorId = null,
    ): \SntQuoteHistory {
        [$defaultType, $defaultId] = $this->currentActor();

        $entry = new \SntQuoteHistory();
        $entry->id_snt_quote = (int) $quote->id;
        $entry->event = $event;
        $entry->status_from = $statusFrom;
        $entry->status_to = $statusTo;
        $entry->actor_type = $actorType ?? $defaultType;
        $entry->actor_id = $actorId ?? $defaultId;
        $entry->ip = $this->clientIp();
        $entry->comment = $comment !== null ? mb_substr(strip_tags($comment), 0, 5000) : null;
        $entry->payload = $payload !== null ? (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null) : null;
        $entry->add();

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>> du plus récent au plus ancien, avec nom de l'acteur
     */
    public function getEntries(int $idQuote): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT h.*, e.`firstname` AS employee_firstname, e.`lastname` AS employee_lastname,
                    c.`firstname` AS customer_firstname, c.`lastname` AS customer_lastname
             FROM `' . _DB_PREFIX_ . 'snt_quote_history` h
             LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (h.`actor_type` = \'employee\' AND e.`id_employee` = h.`actor_id`)
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (h.`actor_type` = \'customer\' AND c.`id_customer` = h.`actor_id`)
             WHERE h.`id_snt_quote` = ' . $idQuote . '
             ORDER BY h.`date_add` DESC, h.`id_snt_quote_history` DESC'
        ) ?: [];

        foreach ($rows as &$row) {
            $row['actor_name'] = match ($row['actor_type']) {
                'employee' => trim($row['employee_firstname'] . ' ' . $row['employee_lastname']),
                'customer' => trim($row['customer_firstname'] . ' ' . $row['customer_lastname']),
                default => '',
            };
            $row['payload'] = $row['payload'] ? json_decode((string) $row['payload'], true) : null;
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function currentActor(): array
    {
        $context = \Context::getContext();
        if (isset($context->employee) && \Validate::isLoadedObject($context->employee)) {
            return [\SntQuoteHistory::ACTOR_EMPLOYEE, (int) $context->employee->id];
        }
        if (isset($context->customer) && $context->customer->isLogged()) {
            return [\SntQuoteHistory::ACTOR_CUSTOMER, (int) $context->customer->id];
        }

        return [\SntQuoteHistory::ACTOR_SYSTEM, null];
    }

    private function clientIp(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }
        $ip = (string) \Tools::getRemoteAddr();

        return $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
