<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

/**
 * Registre des paniers techniques et réglages propres à leur traitement.
 *
 * Le cœur applique les seuils de port gratuit (PS_SHIPPING_FREE_PRICE / _WEIGHT) avant d'appeler le transporteur
 * technique, et renvoie alors le port SANS taxe. Pour un panier de devis, on neutralise ces seuils en mémoire
 * (Configuration::set, jamais en base) le temps de la requête : le port du devis est appliqué tel quel.
 */
final class QuoteCartContext
{
    /** @var array<int, int> id_cart => id_snt_quote */
    private static array $cache = [];

    private static bool $overridesActive = false;

    public static function quoteIdForCart(int $idCart): int
    {
        if ($idCart <= 0) {
            return 0;
        }
        if (!array_key_exists($idCart, self::$cache)) {
            self::$cache[$idCart] = (int) \Db::getInstance()->getValue(
                'SELECT `id_snt_quote` FROM `' . _DB_PREFIX_ . 'snt_quote_cart` WHERE `id_cart` = ' . $idCart
            );
        }

        return self::$cache[$idCart];
    }

    public static function isQuoteCart(int $idCart): bool
    {
        return self::quoteIdForCart($idCart) > 0;
    }

    public static function register(int $idCart, int $idQuote, string $purpose, float $totalTi, string $quoteDateUpd): void
    {
        \Db::getInstance()->insert('snt_quote_cart', [
            'id_cart' => $idCart,
            'id_snt_quote' => $idQuote,
            'purpose' => pSQL($purpose),
            'total_ti' => $totalTi,
            'quote_date_upd' => pSQL($quoteDateUpd),
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, \Db::ON_DUPLICATE_KEY);
        self::$cache[$idCart] = $idQuote;
    }

    public static function forget(int $idCart): void
    {
        \Db::getInstance()->delete('snt_quote_cart', '`id_cart` = ' . $idCart);
        unset(self::$cache[$idCart]);
    }

    /**
     * Neutralise les seuils de port gratuit pour la requête en cours (panier de devis uniquement).
     */
    public static function activateOverrides(int $idShop, int $idShopGroup): void
    {
        foreach (['PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT', 'PS_SHIPPING_HANDLING'] as $key) {
            \Configuration::set($key, 0, $idShopGroup, $idShop);
            \Configuration::set($key, 0, $idShopGroup, null);
            \Configuration::set($key, 0, null, null);
        }
        self::$overridesActive = true;
        self::flushCaches();
    }

    public static function overridesActive(): bool
    {
        return self::$overridesActive;
    }

    /**
     * Vide les caches de prix / panier / transporteur après modification du panier technique.
     */
    public static function flushCaches(): void
    {
        if (method_exists(\SpecificPrice::class, 'flushCache')) {
            \SpecificPrice::flushCache();
        }
        if (method_exists(\Product::class, 'flushPriceCache')) {
            \Product::flushPriceCache();
        }
        if (method_exists(\Cart::class, 'resetStaticCache')) {
            \Cart::resetStaticCache();
        }
        \Cache::clean('getPackageShippingCost_*');
        \Cache::clean('Carrier::*');
        \Cache::clean('carrier_*');
    }
}
