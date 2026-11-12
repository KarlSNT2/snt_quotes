<?php

declare(strict_types=1);

namespace Snt\Quotes\Domain;

/**
 * Statuts d'un devis. Les libellés sont des chaînes sources traduites dans le domaine
 * Modules.Sntquotes.Admin / .Shop ; les couleurs sont identiques partout (BO, FO, PDF, e-mails).
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case RevisionRequested = 'revision_requested';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Ordered = 'ordered';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Awaiting internal approval',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::RevisionRequested => 'Change requested',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Ordered => 'Ordered',
            self::Paid => 'Paid',
            self::Cancelled => 'Replaced',
        };
    }

    /**
     * Couleur de fond du badge (texte blanc sauf mention), contraste AA vérifié.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6C757D',
            self::PendingApproval => '#B35C00',
            self::Sent => '#1F6FB2',
            self::Viewed => '#4A90C2',
            self::RevisionRequested => '#C25E00',
            self::Accepted => '#2E7D32',
            self::Declined => '#C62828',
            self::Expired => '#757575',
            self::Ordered => '#6A3FA0',
            self::Paid => '#1B5E20',
            self::Cancelled => '#9E9E9E',
        };
    }

    public function isStrikethrough(): bool
    {
        return $this === self::Expired || $this === self::Cancelled;
    }

    /**
     * Le contenu (lignes, prix, port…) est-il modifiable ?
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::RevisionRequested], true);
    }

    /**
     * Le devis est-il encore « vivant » côté client (lien, acceptation) ?
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::Viewed], true);
    }

    /**
     * @return self[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Sent, self::Cancelled],
            self::PendingApproval => [self::Draft, self::Sent, self::Cancelled],
            self::Sent => [self::Viewed, self::Accepted, self::Declined, self::Expired, self::RevisionRequested, self::Cancelled, self::Sent],
            self::Viewed => [self::Accepted, self::Declined, self::Expired, self::RevisionRequested, self::Cancelled, self::Sent],
            self::RevisionRequested => [self::Draft, self::Sent, self::Cancelled],
            self::Accepted => [self::Ordered, self::Cancelled],
            self::Ordered => [self::Paid],
            // Remplacé : quand une nouvelle version de ce devis refusé / expiré est envoyée au client.
            self::Declined, self::Expired => [self::Draft, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Statuts de l'onglet rapide « À traiter ».
     *
     * @return self[]
     */
    public static function toProcess(): array
    {
        return [self::RevisionRequested, self::Accepted, self::PendingApproval];
    }

    /**
     * Statuts de l'onglet rapide « En attente client ».
     *
     * @return self[]
     */
    public static function waitingCustomer(): array
    {
        return [self::Sent, self::Viewed];
    }
}
