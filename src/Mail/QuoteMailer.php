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

    /** Modèles activables / désactivables dans la configuration. */
    public const TEMPLATES = [self::TPL_SENT, self::TPL_NEW_VERSION, self::TPL_REMINDER];

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
            default => $this->trans('Your quote %reference% — %shop%', $params, $quote),
        };
    }

    /**
     * Envoie un e-mail de devis. Le PDF (si fourni) est joint automatiquement.
     *
     * @param array{content: string, name: string, mime: string}|null $pdf
     */
    public function send(\SntQuote $quote, string $template, SendOptions $options, ?array $pdf = null): void
    {
        $idShop = (int) $quote->id_shop;
        if (!$this->isEnabled($template, $idShop)) {
            return;
        }
        if ($options->to === []) {
            throw new QuoteException('Enter at least one valid recipient e-mail address.');
        }

        $subject = trim((string) $options->subject) !== '' ? trim((string) $options->subject) : $this->defaultSubject($quote, $template);
        $vars = $this->templateVars($quote, $options->message);
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
            // Lien vers le devis en ligne et lien de paiement : alimentés à partir du jalon 3 (FO).
            '{quote_link_html}' => '',
            '{quote_link_txt}' => '',
        ];
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
