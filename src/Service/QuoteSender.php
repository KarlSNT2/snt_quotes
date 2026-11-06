<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Calculator\DiscountGuard;
use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Mail\QuoteMailer;
use Snt\Quotes\Mail\SendOptions;
use Snt\Quotes\Pdf\QuotePdfGenerator;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Service\Permission\QuotePermissions;

/**
 * « Envoyer au client » : contrôles, validation interne si remise au-delà du seuil,
 * archive du PDF, e-mail, passage au statut Envoyé. Relances et expiration manuelles.
 */
class QuoteSender
{
    public const RESULT_SENT = 'sent';
    public const RESULT_PENDING_APPROVAL = 'pending_approval';

    public function __construct(
        private readonly QuoteEditor $editor,
        private readonly QuoteStateMachine $stateMachine,
        private readonly QuotePdfGenerator $pdf,
        private readonly QuoteMailer $mailer,
        private readonly QuotePermissions $permissions,
        private readonly QuotePresenter $presenter,
        private readonly ModuleConfig $config,
    ) {
    }

    /**
     * Contrôles bloquants avant envoi, avec un message qui dit quoi faire.
     */
    public function assertSendable(\SntQuote $quote): void
    {
        $status = $quote->getStatus();
        if (!in_array($status, [QuoteStatus::Draft, QuoteStatus::PendingApproval, QuoteStatus::RevisionRequested, QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
            throw new QuoteException('A quote with status « %status% » cannot be sent. Create a new version instead.', ['%status%' => $status->label()]);
        }
        if (!$quote->id_customer) {
            throw new QuoteException('Choose a customer before sending the quote.');
        }
        $hasAmountLine = false;
        foreach ($quote->getLines() as $line) {
            if ($line->getType()->hasAmount()) {
                $hasAmountLine = true;
                break;
            }
        }
        if (!$hasAmountLine) {
            throw new QuoteException('Add at least one product or free line before sending the quote.');
        }
        if ($quote->isExpiredByDate()) {
            throw new QuoteException('The validity date has passed. Change it in « Terms » before sending.');
        }
        if ($quote->delivery_mode === 'carrier' && !$quote->id_carrier_real) {
            throw new QuoteException('Choose a carrier (or « Pickup ») before sending the quote.');
        }
    }

    public function requiresApproval(\SntQuote $quote): bool
    {
        $input = $this->editor->calcInputFromQuote($quote);
        $totals = $this->editor->totalsFromQuote($quote);

        return (new DiscountGuard($this->config->getFloat(ConfigKeys::DISCOUNT_THRESHOLD, (int) $quote->id_shop)))->requiresApproval($input, $totals);
    }

    /**
     * Envoie le devis. Si la remise dépasse le seuil et que l'employé n'a pas le droit de la valider,
     * le devis passe « En attente de validation interne » et n'est pas envoyé.
     */
    public function send(\SntQuote $quote, SendOptions $options): string
    {
        $this->assertSendable($quote);

        if ($this->requiresApproval($quote) && !$this->permissions->canApproveDiscount()) {
            if ($quote->getStatus() !== QuoteStatus::PendingApproval) {
                $this->stateMachine->apply($quote, QuoteStatus::PendingApproval, 'Discount above the authorised threshold.');
            }

            return self::RESULT_PENDING_APPROVAL;
        }

        $archive = $this->pdf->archive($quote, $this->presenter);
        $content = $this->pdf->archivedContent($archive, (int) $quote->id_shop);

        $this->mailer->send($quote, $this->mailer->templateFor($quote), $options, [
            'content' => $content,
            'name' => \HTMLTemplateSntQuote::filenameFor($quote),
            'mime' => 'application/pdf',
        ]);

        $this->stateMachine->apply($quote, QuoteStatus::Sent, null, ['recipients' => $options->to]);
        $this->cancelPreviousVersion($quote);

        return self::RESULT_SENT;
    }

    /**
     * Relance manuelle (BO-01, actions de masse) : renvoie le dernier PDF archivé.
     */
    public function remind(\SntQuote $quote): void
    {
        if (!$quote->getStatus()->isOpen()) {
            throw new QuoteException('Only sent quotes awaiting the customer can be reminded.');
        }
        $customer = new \Customer((int) $quote->id_customer);
        $pdf = null;
        $idArchive = (int) \Db::getInstance()->getValue(
            'SELECT `id_snt_quote_pdf` FROM `' . _DB_PREFIX_ . 'snt_quote_pdf` WHERE `id_snt_quote` = ' . (int) $quote->id . ' ORDER BY `id_snt_quote_pdf` DESC'
        );
        if ($idArchive) {
            $pdf = [
                'content' => $this->pdf->archivedContent(new \SntQuotePdf($idArchive), (int) $quote->id_shop),
                'name' => \HTMLTemplateSntQuote::filenameFor($quote),
                'mime' => 'application/pdf',
            ];
        }
        $this->mailer->send($quote, QuoteMailer::TPL_REMINDER, new SendOptions(SendOptions::parseEmails([$customer->email])), $pdf);
        $quote->last_reminder_at = date('Y-m-d H:i:s');
        $quote->update();
    }

    public function expire(\SntQuote $quote, ?string $comment = null): void
    {
        $this->stateMachine->apply($quote, QuoteStatus::Expired, $comment);
    }

    /**
     * Une nouvelle version envoyée remplace la précédente encore ouverte.
     */
    private function cancelPreviousVersion(\SntQuote $quote): void
    {
        if (!$quote->id_parent_quote) {
            return;
        }
        $parent = new \SntQuote((int) $quote->id_parent_quote);
        if (\Validate::isLoadedObject($parent) && $parent->getStatus()->canTransitionTo(QuoteStatus::Cancelled) && !$parent->id_order) {
            $this->stateMachine->apply($parent, QuoteStatus::Cancelled, null, ['replaced_by' => $quote->getFullReference()]);
        }
    }
}
