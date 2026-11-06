<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Gabarit PDF natif (TCPDF) d'un devis. Instancié par PDF via le nom « SntQuote ».
 *
 * L'objet passé est un tableau préparé par Snt\Quotes\Pdf\QuotePdfGenerator :
 * ['quote' => SntQuote, 'view' => vue présentée, 'shop' => infos boutique, 'settings' => branding].
 *
 * Templates surchargeables : themes/<thème>/modules/snt_quotes/views/templates/pdf/<nom>.tpl
 */
class HTMLTemplateSntQuote extends HTMLTemplate
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var bool */
    public $send_bulk_flag = false;

    /**
     * @param array<string, mixed> $data
     * @param Smarty               $smarty
     * @param bool                 $send_bulk_flag
     */
    public function __construct($data, $smarty, $send_bulk_flag = false)
    {
        $this->data = $data;
        $this->smarty = $smarty;
        $this->send_bulk_flag = $send_bulk_flag;
        $this->shop = new Shop((int) $data['quote']->id_shop);
        $this->title = 'Devis ' . $data['view']['full_reference'];
        $this->date = (string) $data['quote']->date_add;
    }

    public function getHeader()
    {
        $this->smarty->assign($this->data + ['pdf_part' => 'header']);

        return $this->smarty->fetch($this->resolve('quote-header'));
    }

    public function getFooter()
    {
        $this->smarty->assign($this->data + ['pdf_part' => 'footer']);

        return $this->smarty->fetch($this->resolve('quote-footer'));
    }

    public function getContent()
    {
        $this->smarty->assign($this->data + ['pdf_part' => 'content']);

        return $this->smarty->fetch($this->resolve('quote'));
    }

    public function getFilename()
    {
        return self::filenameFor($this->data['quote']);
    }

    public function getBulkFilename()
    {
        return 'Devis.pdf';
    }

    public function getPagination()
    {
        $this->smarty->assign($this->data);

        return $this->smarty->fetch($this->resolve('quote-pagination'));
    }

    public static function filenameFor(SntQuote $quote): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $quote->reference) ?? 'devis';

        return 'Devis-' . $reference . '-v' . (int) $quote->version . '.pdf';
    }

    /**
     * Template du thème actif s'il existe, sinon celui du module.
     */
    private function resolve(string $name): string
    {
        foreach (self::themeChain((string) $this->shop->theme_name) as $theme) {
            $file = _PS_ALL_THEMES_DIR_ . $theme . '/modules/snt_quotes/views/templates/pdf/' . $name . '.tpl';
            if (is_file($file)) {
                return $file;
            }
        }

        return _PS_MODULE_DIR_ . 'snt_quotes/views/templates/pdf/' . $name . '.tpl';
    }

    /**
     * Thème enfant puis parent (clé « parent » de config/theme.yml), ex. ZOneChild → ZOne.
     *
     * @return string[]
     */
    private static function themeChain(string $theme): array
    {
        $chain = [];
        while ($theme !== '' && !in_array($theme, $chain, true) && count($chain) < 3) {
            $chain[] = $theme;
            $yml = @file_get_contents(_PS_ALL_THEMES_DIR_ . $theme . '/config/theme.yml') ?: '';
            $theme = preg_match('/^parent:\s*[\'"]?([A-Za-z0-9_.-]+)/m', $yml, $m) ? $m[1] : '';
        }

        return $chain;
    }
}
