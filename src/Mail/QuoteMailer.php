<?php

declare(strict_types=1);

namespace Snt\Quotes\Mail;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Service\HistoryLogger;

/**
 * E-mails des devis, au gabarit de la boutique (mails/{iso}/*.html|.txt, surchargeables par le thème
 * et traduisibles via « Traductions > E-mails »). Chaque envoi est tracé dans l'historique.
 */
class QuoteMailer
{
    public const TPL_SENT = 'snt_quote_sent';
    public const TPL_NEW_VERSION = 'snt_quote_new_version';
    public const TPL_REMINDER = 'snt_quote_reminder';
    public const TPL_PAYMENT_LINK = 'snt_quote_payment_link';
    public const TPL_ORDERED_EMPLOYEE = 'snt_quote_ordered_employee';
    public const TPL_CUSTOMER_ACTION = 'snt_quote_customer_action';
    public const TPL_REPLY = 'snt_quote_reply';

    /** Modèles activables / désactivables dans la configuration. */
    public const TEMPLATES = [
        self::TPL_SENT, self::TPL_NEW_VERSION, self::TPL_REMINDER, self::TPL_PAYMENT_LINK,
        self::TPL_ORDERED_EMPLOYEE, self::TPL_CUSTOMER_ACTION, self::TPL_REPLY,
    ];

    private const DOMAIN = 'Modules.Sntquotes.Emails';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly QuotePresenter $presenter,
        private readonly HistoryLogger $history,
    ) {
    }

    public function isEnabled(string $template, int $idShop): bool
    {
        return !in_array($template, $this->config->getList(ConfigKeys::MAIL_DISABLED, $idShop), true);
    }

    public function templateFor(\SntQuote $quote): string
    {
        return (int) $quote->version > 1 ? self::TPL_NEW_VERSION : self::TPL_SENT;
    }

    public function defaultSubject(\SntQuote $quote, string $template): string
    {
        $params = ['%reference%' => $quote->getFullReference(), '%shop%' => (string) \Configuration::get('PS_SHOP_NAME', null, null, (int) $quote->id_shop)];

        return match ($template) {
            self::TPL_NEW_VERSION => $this->trans('Updated quote %reference% — %shop%', $params, $quote),
            self::TPL_REMINDER => $this->trans('Reminder: your quote %reference% expires soon', $params, $quote),
            self::TPL_PAYMENT_LINK => $this->trans('Pay your quote %reference% online — %shop%', $params, $quote),
            self::TPL_ORDERED_EMPLOYEE => $this->trans('Order created from quote %reference%', $params, $quote),
            self::TPL_REPLY => $this->trans('New message about your quote %reference% — %shop%', $params, $quote),
            default => $this->trans('Your quote %reference% — %shop%', $params, $quote),
        };
    }

    /**
     * Envoie un e-mail de devis. Le PDF (si fourni) est joint automatiquement.
     *
     * @param array{content: string, name: string, mime: string}|null $pdf
     * @param array<string, string>                                   $extraVars variables déjà échappées
     */
    public function send(\SntQuote $quote, string $template, SendOptions $options, ?array $pdf = null, array $extraVars = []): void
    {
        $idShop = (int) $quote->id_shop;
        if (!$this->isEnabled($template, $idShop)) {
            return;
        }
        if ($options->to === []) {
            throw new QuoteException('Enter at least one valid recipient e-mail address.');
        }

        $subject = trim((string) $options->subject) !== '' ? trim((string) $options->subject) : $this->defaultSubject($quote, $template);
        $vars = $extraVars + $this->templateVars($quote, $options->message);
        $attachments = array_values(array_filter(array_merge($pdf ? [$pdf] : [], $options->attachments)));

        [$fromName, $replyTo, $replyToName] = $this->sender($quote);
        $bcc = $options->cc;
        $employeeEmail = $this->employeeEmail($quote);
        if ($options->copyToEmployee && $employeeEmail && !in_array($employeeEmail, $options->to, true)) {
            $bcc[] = $employeeEmail;
        }
        $bcc = array_values(array_unique($bcc));

        $to = $options->to;
        $first = array_shift($to);
        // Les destinataires supplémentaires reçoivent le même message en copie cachée (pas de fuite d'adresses).
        $bcc = array_values(array_unique(array_merge($to, $bcc)));

        $sent = \Mail::send(
            (int) $quote->id_lang,
            $template,
            $subject,
            $vars,
            $first,
            $this->recipientName($quote),
            null,
            $fromName,
            $attachments ?: null,
            null,
            _PS_MODULE_DIR_ . 'snt_quotes/mails/',
            false,
            $idShop,
            $bcc ?: null,
            $replyTo,
            $replyToName
        );
        if (!$sent) {
            throw new QuoteException('The e-mail could not be sent. Check the shop e-mail settings, then try again.');
        }

        $this->history->log($quote, \SntQuoteHistory::EVENT_EMAIL, $subject, [
            'template' => $template,
            'to' => $options->to,
            'bcc' => $bcc,
            'attachments' => array_map(static fn (array $a): string => $a['name'], $attachments),
        ]);
    }

    /**
     * Lien de paiement envoyé au client.
     *
     * @param string[] $to
     */
    public function sendPaymentLink(\SntQuote $quote, string $url, string $expiresAt, array $to, ?string $message = null): void
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $expires = \Tools::displayDate($expiresAt, true);
        $this->send($quote, self::TPL_PAYMENT_LINK, new SendOptions($to, [], null, $message), null, [
            '{payment_link_url}' => $e($url),
            '{payment_link_expires}' => $e((string) $expires),
            '{quote_link_html}' => '<p style="margin:16px 0;text-align:center;"><a href="' . $e($url) . '" style="display:inline-block;padding:12px 24px;background:#1F6FB2;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">'
                . $e(\Context::getContext()->getTranslator()->trans('View and pay my quote', [], self::DOMAIN, (new \Language((int) $quote->id_lang))->locale ?: null)) . '</a></p>',
            '{quote_link_txt}' => $url,
        ]);
    }

    /**
     * Prévient le commercial qu'une commande a été créée depuis son devis (paiement en ligne, lien…).
     */
    public function notifyEmployeeOrdered(\SntQuote $quote, \Order $order): void
    {
        if (!$quote->id_employee || !$this->isEnabled(self::TPL_ORDERED_EMPLOYEE, (int) $quote->id_shop)) {
            return;
        }
        $employee = new \Employee((int) $quote->id_employee);
        if (!\Validate::isLoadedObject($employee) || !$employee->email) {
            return;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $vars = [
            '{order_reference}' => $e((string) $order->reference),
            '{order_payment}' => $e((string) $order->payment),
            '{order_total_ti}' => $e(\Context::getContext()->getCurrentLocale()?->formatPrice((float) $order->total_paid_tax_incl, (string) \Currency::getIsoCodeById((int) $order->id_currency)) ?? (string) $order->total_paid_tax_incl),
        ] + $this->templateVars($quote, null);
        \Mail::send(
            (int) ($employee->id_lang ?: $quote->id_lang),
            self::TPL_ORDERED_EMPLOYEE,
            $this->defaultSubject($quote, self::TPL_ORDERED_EMPLOYEE),
            $vars,
            (string) $employee->email,
            trim($employee->firstname . ' ' . $employee->lastname),
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'snt_quotes/mails/',
            false,
            (int) $quote->id_shop
        );
    }

    /**
     * Rendu HTML de l'e-mail pour l'aperçu dans la modale (sans envoi).
     */
    public function preview(\SntQuote $quote, string $template, ?string $message): string
    {
        $iso = \Language::getIsoById((int) $quote->id_lang) ?: 'fr';
        $file = $this->templateFile($iso, $template, 'html') ?? $this->templateFile('en', $template, 'html');
        if (!$file) {
            return '';
        }
        $vars = $this->templateVars($quote, $message);
        $idShop = (int) $quote->id_shop;
        $logo = (string) (\Configuration::get('PS_LOGO_MAIL', null, null, $idShop) ?: \Configuration::get('PS_LOGO', null, null, $idShop));
        $vars += [
            '{shop_name}' => htmlspecialchars((string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop), ENT_QUOTES, 'UTF-8'),
            '{shop_url}' => \Context::getContext()->link->getBaseLink($idShop),
            '{shop_logo}' => $logo !== '' ? \Context::getContext()->link->getMediaLink(_PS_IMG_ . $logo) : '',
            '{color}' => htmlspecialchars((string) \Configuration::get('PS_MAIL_COLOR', null, null, $idShop), ENT_QUOTES, 'UTF-8'),
            '{my_account_url}' => \Context::getContext()->link->getPageLink('my-account', true, (int) $quote->id_lang, null, false, $idShop),
        ];

        return strtr((string) file_get_contents($file), $vars);
    }

    /**
     * @return array<string, string>
     */
    private function templateVars(\SntQuote $quote, ?string $message): array
    {
        $view = $this->presenter->present($quote, true);
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $message = trim((string) $message);

        $rows = '';
        foreach ($view['lines'] as $line) {
            if (!$line['has_amount']) {
                continue;
            }
            $rows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;">' . $e($line['label']) . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:center;">' . (int) $line['qty'] . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">' . $e($line['total_te_display']) . '</td></tr>';
        }
        $linesHtml = $rows !== ''
            ? '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">' . $rows . '</table>'
            : '';

        $customer = $view['customer'];
        $employee = $view['employee'];

        return [
            '{firstname}' => $e((new \Customer((int) $quote->id_customer))->firstname),
            '{lastname}' => $e((new \Customer((int) $quote->id_customer))->lastname),
            '{customer_name}' => $e(trim($customer['company'] !== '' ? $customer['company'] : $customer['name'])),
            '{quote_reference}' => $e($view['full_reference']),
            '{quote_project}' => $e($view['project_name']),
            '{quote_total_te}' => $e($view['total_te_display']),
            '{quote_total_ti}' => $e($view['total_ti_display']),
            '{quote_valid_until}' => $e((string) $view['valid_until_display']),
            '{quote_carrier}' => $e($view['carrier_label']),
            '{quote_lines_html}' => $linesHtml,
            '{custom_message_html}' => $message !== '' ? nl2br($e($message)) : '',
            '{custom_message_txt}' => $message,
            '{employee_name}' => $e($employee['name'] ?? ''),
            '{employee_email}' => $e($employee['email'] ?? ''),
            '{employee_phone}' => $e($employee['phone'] ?? ''),
            // Lien vers le devis dans l'espace client (remplacé par le lien de paiement dans l'e-mail dédié).
            '{quote_link_html}' => $this->button($this->quoteUrl($quote), $this->trans('View my quote online', [], $quote)),
            '{quote_link_txt}' => $this->quoteUrl($quote),
            '{quote_url}' => $e($this->quoteUrl($quote)),
        ];
    }

    /**
     * Page du devis dans l'espace « Mes devis » du client (connexion requise).
     */
    public function quoteUrl(\SntQuote $quote): string
    {
        return \Context::getContext()->link->getModuleLink('snt_quotes', 'quote', ['id_quote' => (int) $quote->id], true, (int) $quote->id_lang, (int) $quote->id_shop);
    }

    private function button(string $url, string $label): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<p style="margin:16px 0;text-align:center;"><a href="' . $e($url) . '" style="display:inline-block;padding:12px 24px;background:#1F6FB2;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">' . $e($label) . '</a></p>';
    }

    /**
     * Action du client dans son espace (demande de modification, refus, message) → e-mail au commercial.
     *
     * @param string $action revision|declined|message
     */
    public function notifyEmployeeCustomerAction(\SntQuote $quote, string $action, ?string $message): void
    {
        if (!$this->isEnabled(self::TPL_CUSTOMER_ACTION, (int) $quote->id_shop)) {
            return;
        }
        [$email, $name, $idLang] = $this->employeeRecipient($quote);
        if ($email === '') {
            return;
        }
        $language = new \Language($idLang);
        $translator = \Context::getContext()->getTranslator();
        $label = match ($action) {
            'revision' => $translator->trans('The customer asks for changes to the quote.', [], self::DOMAIN, $language->locale ?: null),
            'declined' => $translator->trans('The customer has declined the quote.', [], self::DOMAIN, $language->locale ?: null),
            default => $translator->trans('The customer sent a message about the quote.', [], self::DOMAIN, $language->locale ?: null),
        };
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $message = trim((string) $message);
        $vars = [
            '{action_label}' => $e($label),
            '{customer_message_html}' => $message !== '' ? nl2br($e($message)) : '—',
            '{customer_message_txt}' => $message !== '' ? $message : '—',
        ] + $this->templateVars($quote, null);
        \Mail::send(
            $idLang,
            self::TPL_CUSTOMER_ACTION,
            $translator->trans('Quote %reference%: %action%', ['%reference%' => $quote->getFullReference(), '%action%' => $label], self::DOMAIN, $language->locale ?: null),
            $vars,
            $email,
            $name,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'snt_quotes/mails/',
            false,
            (int) $quote->id_shop,
            null,
            (string) (new \Customer((int) $quote->id_customer))->email
        );
    }

    /**
     * Réponse de l'équipe dans le fil d'échanges → e-mail au client, avec le lien vers son devis.
     */
    public function notifyCustomerReply(\SntQuote $quote, string $message): void
    {
        $customer = new \Customer((int) $quote->id_customer);
        if (!$this->isEnabled(self::TPL_REPLY, (int) $quote->id_shop) || !\Validate::isEmail((string) $customer->email)) {
            return;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        [$fromName, $replyTo, $replyToName] = $this->sender($quote);
        \Mail::send(
            (int) $quote->id_lang,
            self::TPL_REPLY,
            $this->defaultSubject($quote, self::TPL_REPLY),
            ['{reply_html}' => nl2br($e(trim($message))), '{reply_txt}' => trim($message)] + $this->templateVars($quote, null),
            (string) $customer->email,
            $this->recipientName($quote),
            null,
            $fromName,
            null,
            null,
            _PS_MODULE_DIR_ . 'snt_quotes/mails/',
            false,
            (int) $quote->id_shop,
            null,
            $replyTo,
            $replyToName
        );
    }

    /**
     * Commercial du devis, ou adresse de la boutique à défaut.
     *
     * @return array{0: string, 1: string, 2: int} e-mail, nom, langue
     */
    private function employeeRecipient(\SntQuote $quote): array
    {
        if ($quote->id_employee) {
            $employee = new \Employee((int) $quote->id_employee);
            if (\Validate::isLoadedObject($employee) && $employee->email) {
                return [(string) $employee->email, trim($employee->firstname . ' ' . $employee->lastname), (int) ($employee->id_lang ?: $quote->id_lang)];
            }
        }

        return [(string) \Configuration::get('PS_SHOP_EMAIL', null, null, (int) $quote->id_shop), (string) \Configuration::get('PS_SHOP_NAME', null, null, (int) $quote->id_shop), (int) $quote->id_lang];
    }

    /**
     * Expéditeur : la boutique, ou le commercial (nom affiché + réponse au commercial).
     * L'adresse d'envoi reste celle de la boutique (SPF / DKIM).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function sender(\SntQuote $quote): array
    {
        if ($this->config->get(ConfigKeys::MAIL_FROM, (int) $quote->id_shop) !== 'employee' || !$quote->id_employee) {
            return [null, null, null];
        }
        $employee = new \Employee((int) $quote->id_employee);
        if (!\Validate::isLoadedObject($employee)) {
            return [null, null, null];
        }
        $name = trim($employee->firstname . ' ' . $employee->lastname) . ' — ' . \Configuration::get('PS_SHOP_NAME', null, null, (int) $quote->id_shop);

        return [$name, (string) $employee->email, trim($employee->firstname . ' ' . $employee->lastname)];
    }

    private function recipientName(\SntQuote $quote): ?string
    {
        $customer = new \Customer((int) $quote->id_customer);
        $name = trim($customer->firstname . ' ' . $customer->lastname);

        return $name !== '' ? $name : null;
    }

    private function employeeEmail(\SntQuote $quote): ?string
    {
        if (!$this->config->getBool(ConfigKeys::MAIL_CC_EMPLOYEE, (int) $quote->id_shop) || !$quote->id_employee) {
            return null;
        }
        $employee = new \Employee((int) $quote->id_employee);

        return \Validate::isLoadedObject($employee) ? strtolower((string) $employee->email) : null;
    }

    private function templateFile(string $iso, string $template, string $ext): ?string
    {
        $theme = (string) \Context::getContext()->shop->theme_name;
        foreach ([
            _PS_ALL_THEMES_DIR_ . $theme . '/modules/snt_quotes/mails/' . $iso . '/' . $template . '.' . $ext,
            _PS_MODULE_DIR_ . 'snt_quotes/mails/' . $iso . '/' . $template . '.' . $ext,
        ] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    private function trans(string $id, array $params, \SntQuote $quote): string
    {
        $language = new \Language((int) $quote->id_lang);

        return \Context::getContext()->getTranslator()->trans($id, $params, self::DOMAIN, $language->locale ?: null);
    }
}
