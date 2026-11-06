<?php

declare(strict_types=1);

namespace Snt\Quotes\Pdf;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Service\HistoryLogger;
use Snt\Quotes\Storage\S3Storage;

/**
 * PDF d'un devis : rendu à la volée (aperçu BO) et archive figée à chaque envoi (SHA-256, versionnée).
 */
class QuotePdfGenerator
{
    public function __construct(
        private readonly S3Storage $storage,
        private readonly HistoryLogger $history,
        private readonly ModuleConfig $config,
    ) {
    }

    /**
     * Contenu binaire du PDF.
     */
    public function render(\SntQuote $quote, QuotePresenter $presenter): string
    {
        $context = \Context::getContext();
        $previousLanguage = $context->language;
        $context->language = new \Language((int) $quote->id_lang ?: (int) $previousLanguage->id);
        try {
            $pdf = new \PDF([$this->buildData($quote, $presenter)], 'SntQuote', $context->smarty);

            return (string) $pdf->render(false);
        } finally {
            $context->language = $previousLanguage;
        }
    }

    /**
     * Archive le PDF tel qu'envoyé. Le nom garde la version ; un renvoi de la même version est horodaté.
     */
    public function archive(\SntQuote $quote, QuotePresenter $presenter): \SntQuotePdf
    {
        $content = $this->render($quote, $presenter);
        $filename = \HTMLTemplateSntQuote::filenameFor($quote);
        $alreadyArchived = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'snt_quote_pdf` WHERE `id_snt_quote` = ' . (int) $quote->id . ' AND `version` = ' . (int) $quote->version
        );
        if ($alreadyArchived) {
            $filename = substr($filename, 0, -4) . '-' . date('Ymd-His') . '.pdf';
        }
        $key = $this->storage->siteCode((int) $quote->id_shop) . '/' . $filename;
        $stored = $this->storage->put($key, $content, 'application/pdf', (int) $quote->id_shop);

        $archive = new \SntQuotePdf();
        $archive->id_snt_quote = (int) $quote->id;
        $archive->version = (int) $quote->version;
        $archive->storage = $stored['storage'];
        $archive->file_path = $stored['path'];
        $archive->sha256 = hash('sha256', $content);
        $archive->add();

        $this->history->log($quote, \SntQuoteHistory::EVENT_PDF, null, [
            'file' => $filename,
            'sha256' => $archive->sha256,
            'storage' => $archive->storage,
            'id_snt_quote_pdf' => (int) $archive->id,
        ]);

        return $archive;
    }

    public function archivedContent(\SntQuotePdf $archive, int $idShop): string
    {
        $content = $this->storage->get((string) $archive->storage, (string) $archive->file_path, $idShop);
        if (hash('sha256', $content) !== $archive->sha256) {
            throw new \RuntimeException('The archived PDF does not match its fingerprint.');
        }

        return $content;
    }

    public function storage(): S3Storage
    {
        return $this->storage;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildData(\SntQuote $quote, QuotePresenter $presenter): array
    {
        $idShop = (int) $quote->id_shop;
        $config = $this->config;
        $logo = (string) (\Configuration::get('PS_LOGO_INVOICE', null, null, $idShop) ?: \Configuration::get('PS_LOGO', null, null, $idShop));
        $logoPath = $logo !== '' && is_file(_PS_IMG_DIR_ . $logo) ? _PS_IMG_DIR_ . $logo : null;

        $cgv = '';
        $cgvSource = $config->get(ConfigKeys::PDF_CGV_SOURCE, $idShop);
        if ($cgvSource === 'text') {
            $cgv = nl2br(htmlspecialchars($config->get(ConfigKeys::PDF_CGV_TEXT, $idShop), ENT_QUOTES, 'UTF-8'));
        } elseif ($cgvSource === 'cms' && ($idCms = $config->getInt(ConfigKeys::PDF_CGV_ID_CMS, $idShop))) {
            $cms = new \CMS($idCms, (int) $quote->id_lang, $idShop);
            $cgv = \Validate::isLoadedObject($cms) ? (string) $cms->content : '';
        }

        $color = $config->get(ConfigKeys::PDF_COLOR, $idShop);

        return [
            'quote' => $quote,
            'view' => $presenter->present($quote, true),
            'shop' => [
                'name' => (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
                'address' => $this->shopAddress($idShop),
                'phone' => (string) \Configuration::get('PS_SHOP_PHONE', null, null, $idShop),
                'email' => (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $idShop),
                'details' => (string) \Configuration::get('PS_SHOP_DETAILS', (int) $quote->id_lang, null, $idShop),
                'logo_path' => $logoPath,
            ],
            'settings' => [
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#1F3A5F',
                'legal' => $config->get(ConfigKeys::PDF_LEGAL, $idShop),
                'payment_terms' => $config->get(ConfigKeys::PAYMENT_TERMS, $idShop),
                'iban' => $config->get(ConfigKeys::IBAN, $idShop),
                'bic' => $config->get(ConfigKeys::BIC, $idShop),
                'cgv' => $cgv,
            ],
        ];
    }

    private function shopAddress(int $idShop): string
    {
        $parts = [];
        foreach (['PS_SHOP_ADDR1', 'PS_SHOP_ADDR2'] as $key) {
            $value = trim((string) \Configuration::get($key, null, null, $idShop));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $city = trim(\Configuration::get('PS_SHOP_CODE', null, null, $idShop) . ' ' . \Configuration::get('PS_SHOP_CITY', null, null, $idShop));
        if ($city !== '') {
            $parts[] = $city;
        }
        $idCountry = (int) \Configuration::get('PS_SHOP_COUNTRY_ID', null, null, $idShop);
        if ($idCountry) {
            $parts[] = (string) \Country::getNameById((int) \Context::getContext()->language->id, $idCountry);
        }

        return implode('<br>', array_map(static fn (string $p): string => htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), $parts));
    }
}
