<?php

declare(strict_types=1);

namespace Snt\Quotes\Config;

/**
 * Lecture typée de la configuration du module pour une boutique donnée.
 */
class ModuleConfig
{
    public function get(string $key, ?int $idShop = null): string
    {
        $value = \Configuration::get($key, null, null, $idShop ?? $this->currentShopId());

        return $value === false || $value === null ? '' : (string) $value;
    }

    public function getInt(string $key, ?int $idShop = null): int
    {
        return (int) $this->get($key, $idShop);
    }

    public function getFloat(string $key, ?int $idShop = null): float
    {
        return (float) str_replace(',', '.', $this->get($key, $idShop));
    }

    public function getBool(string $key, ?int $idShop = null): bool
    {
        return (bool) $this->getInt($key, $idShop);
    }

    /**
     * Valeur stockée sous forme « a,b,c ».
     *
     * @return string[]
     */
    public function getList(string $key, ?int $idShop = null): array
    {
        $raw = $this->get($key, $idShop);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return int[]
     */
    public function getIntList(string $key, ?int $idShop = null): array
    {
        return array_map('intval', $this->getList($key, $idShop));
    }

    public function set(string $key, string|int|float|bool $value, ?int $idShop = null, bool $html = false): void
    {
        $idShop ??= $this->currentShopId();
        $shop = new \Shop($idShop);
        \Configuration::updateValue($key, is_bool($value) ? (int) $value : $value, $html, (int) $shop->id_shop_group, $idShop);
    }

    private function currentShopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }
}
