<?php

declare(strict_types=1);

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tâche planifiée (relances, expirations, liens de paiement) de la boutique de l'URL.
 * Appel : URL « Tâche planifiée » de la configuration (jeton propre à chaque boutique), par exemple toutes les heures :
 *   curl -fsS "https://boutique/module/snt_quotes/cron?token=…"
 * Réponse JSON avec le nombre de devis traités.
 */
class Snt_QuotesCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /** @return void */
    public function initContent()
    {
        $idShop = (int) $this->context->shop->id;
        $expected = (string) ServiceFactory::config()->get(ConfigKeys::CRON_TOKEN, $idShop);
        if ($expected === '' || !hash_equals($expected, (string) Tools::getValue('token'))) {
            $this->respond(403, ['ok' => false, 'error' => 'invalid token']);
        }

        // Un seul passage à la fois par boutique (appels qui se chevauchent).
        $lock = 'snt_quotes_cron_' . $idShop;
        if (!(int) Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($lock) . '\', 0)')) {
            $this->respond(409, ['ok' => false, 'error' => 'already running']);
        }
        @set_time_limit(300);
        try {
            $result = ServiceFactory::cronRunner()->run($idShop);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] cron: ' . get_class($e) . ': ' . $e->getMessage(), 3);
            Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')');
            $this->respond(500, ['ok' => false, 'error' => 'internal error, see the PrestaShop logs']);
        }
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')');
        ServiceFactory::config()->set(ConfigKeys::CRON_LAST_RUN, date('Y-m-d H:i:s'), $idShop);

        $this->respond(200, ['ok' => true, 'shop' => $idShop, 'date' => date('c')] + $result);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $code, array $body): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo json_encode($body);
        exit;
    }
}
