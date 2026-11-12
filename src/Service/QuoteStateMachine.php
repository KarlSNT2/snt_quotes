<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Domain\Exception\InvalidTransitionException;
use Snt\Quotes\Domain\QuoteStatus;

/**
 * Point d'entrée unique des changements de statut : contrôle, horodatage, historique, événement.
 *
 * L'événement est publié via le hook « actionSntQuoteStatusUpdate » : d'autres modules peuvent s'y abonner
 * sans que ce service les connaisse.
 */
class QuoteStateMachine
{
    public const HOOK_STATUS_UPDATE = 'actionSntQuoteStatusUpdate';

    public function __construct(private readonly HistoryLogger $history)
    {
    }

    /**
     * @param array<string, mixed> $payload informations complémentaires (motif de refus, id commande…)
     *
     * @throws InvalidTransitionException
     */
    public function apply(
        \SntQuote $quote,
        QuoteStatus $to,
        ?string $comment = null,
        array $payload = [],
        ?string $actorType = null,
        ?int $actorId = null,
    ): void {
        $from = $quote->getStatus();
        if (!$from->canTransitionTo($to)) {
            throw new InvalidTransitionException($from, $to);
        }

        $now = date('Y-m-d H:i:s');
        $quote->status = $to->value;
        switch ($to) {
            case QuoteStatus::Sent:
                $quote->sent_at = $now;
                break;
            case QuoteStatus::Viewed:
                $quote->viewed_at ??= $now;
                break;
            case QuoteStatus::Accepted:
                $quote->accepted_at = $now;
                $quote->accepted_ip = PHP_SAPI === 'cli' ? null : substr((string) \Tools::getRemoteAddr(), 0, 45);
                break;
            default:
                break;
        }
        if (!$quote->update()) {
            throw new \RuntimeException('Unable to save quote ' . (int) $quote->id);
        }

        $this->history->log(
            $quote,
            \SntQuoteHistory::EVENT_STATUS,
            $comment,
            $payload ?: null,
            $from->value,
            $to->value,
            $actorType,
            $actorId
        );

        \Hook::exec(self::HOOK_STATUS_UPDATE, [
            'quote' => $quote,
            'status_from' => $from->value,
            'status_to' => $to->value,
            'payload' => $payload,
        ]);
    }

    public function can(\SntQuote $quote, QuoteStatus $to): bool
    {
        return $quote->getStatus()->canTransitionTo($to);
    }
}
