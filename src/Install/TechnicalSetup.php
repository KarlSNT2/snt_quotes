<?php

declare(strict_types=1);

namespace Snt\Quotes\Install;

use Snt\Quotes\Config\ConfigKeys;

/**
 * Objets techniques nécessaires à la conversion devis → commande :
 * - transporteur « Livraison selon devis » (module, toutes zones, tous groupes, masqué du tunnel) ;
 * - produits « Ligne libre de devis » (un par règle de taxe) avec des déclinaisons « emplacement » réutilisables,
 *   pour porter plusieurs lignes libres de prix différents dans un même panier ;
 * - statuts de commande propres aux devis.
 *
 * Toutes les méthodes sont idempotentes : appelées à l'installation, à la mise à jour et à la volée.
 */
class TechnicalSetup
{
    public const ATTRIBUTE_GROUP_NAME = 'Ligne de devis';
    public const FREE_LINE_REFERENCE = 'SNT-QUOTE-FREE';

    public function __construct(private readonly string $moduleName = 'snt_quotes')
    {
    }

    public function installAll(): bool
    {
        return $this->ensureCarrier() > 0 && $this->ensureOrderStates();
    }

    /* ------------------------------------------------------------------ */
    /* Transporteur technique                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return int id_carrier courant du transporteur technique
     */
    public function ensureCarrier(): int
    {
        $reference = (int) \Configuration::getGlobalValue(ConfigKeys::CARRIER_ID_REFERENCE);
        if ($reference) {
            $carrier = \Carrier::getCarrierByReference($reference);
            if ($carrier instanceof \Carrier && \Validate::isLoadedObject($carrier) && !$carrier->deleted) {
                return (int) $carrier->id;
            }
        }

        $carrier = new \Carrier();
        $carrier->name = 'Livraison selon devis';
        $carrier->is_module = true;
        $carrier->external_module_name = $this->moduleName;
        $carrier->shipping_external = true;
        $carrier->need_range = false;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = false;
        $carrier->is_free = false;
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->url = '';
        $carrier->max_weight = 0;
        $carrier->grade = 0;
        $delay = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $delay[(int) $lang['id_lang']] = $lang['iso_code'] === 'fr' ? 'Selon devis' : 'As quoted';
        }
        $carrier->delay = $delay; // @phpstan-ignore-line (champ multilingue : tableau id_lang => valeur)
        if (!$carrier->add()) {
            return 0;
        }
        // id_reference = id à la création.
        \Configuration::updateGlobalValue(ConfigKeys::CARRIER_ID_REFERENCE, (int) $carrier->id_reference ?: (int) $carrier->id);

        $groups = array_map(static fn (array $g): int => (int) $g['id_group'], \Group::getGroups((int) \Configuration::get('PS_LANG_DEFAULT')));
        $carrier->setGroups($groups);
        foreach (\Zone::getZones(false) as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            \Db::getInstance()->insert('carrier_shop', ['id_carrier' => (int) $carrier->id, 'id_shop' => (int) $idShop], false, true, \Db::INSERT_IGNORE);
            \Db::getInstance()->insert('carrier_tax_rules_group_shop', [
                'id_carrier' => (int) $carrier->id,
                'id_tax_rules_group' => 0,
                'id_shop' => (int) $idShop,
            ], false, true, \Db::INSERT_IGNORE);
        }

        return (int) $carrier->id;
    }

    public function technicalCarrierId(): int
    {
        $reference = (int) \Configuration::getGlobalValue(ConfigKeys::CARRIER_ID_REFERENCE);
        if (!$reference) {
            return 0;
        }
        $carrier = \Carrier::getCarrierByReference($reference);

        return $carrier instanceof \Carrier && \Validate::isLoadedObject($carrier) ? (int) $carrier->id : 0;
    }

    public function technicalCarrierReference(): int
    {
        return (int) \Configuration::getGlobalValue(ConfigKeys::CARRIER_ID_REFERENCE);
    }

    /**
     * Le port du devis est taxé selon la règle du transporteur réel : on aligne celle du transporteur technique
     * pour la boutique, juste avant la conversion (les règles de port sont en pratique identiques entre transporteurs).
     */
    public function alignCarrierTaxRule(int $idCarrier, int $idTaxRulesGroup, int $idShop): void
    {
        \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop` (`id_carrier`, `id_tax_rules_group`, `id_shop`)
             VALUES (' . $idCarrier . ', ' . $idTaxRulesGroup . ', ' . $idShop . ')
             ON DUPLICATE KEY UPDATE `id_tax_rules_group` = ' . $idTaxRulesGroup
        );
        \Cache::clean('Carrier::getIdTaxRulesGroupByIdCarrier_*');
        \Cache::clean('carrier_id_tax_rules_group_*');
    }

    /* ------------------------------------------------------------------ */
    /* Produits « ligne libre »                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Couple [id_product, id_product_attribute] pour la n-ième ligne libre d'une règle de taxe.
     *
     * @return array{0: int, 1: int}
     */
    public function freeLineSlot(int $idTaxRulesGroup, int $slot): array
    {
        $idProduct = $this->freeLineProduct($idTaxRulesGroup);
        $idAttribute = $this->slotAttribute($slot);

        $idProductAttribute = (int) \Db::getInstance()->getValue(
            'SELECT pac.`id_product_attribute`
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = pac.`id_product_attribute`
             WHERE pa.`id_product` = ' . $idProduct . ' AND pac.`id_attribute` = ' . $idAttribute
        );
        if ($idProductAttribute) {
            return [$idProduct, $idProductAttribute];
        }

        $combination = new \Combination();
        $combination->id_product = $idProduct;
        $combination->reference = self::FREE_LINE_REFERENCE . '-' . $idTaxRulesGroup . '-' . $slot;
        $combination->price = 0;
        $combination->wholesale_price = 0;
        $combination->weight = 0;
        $combination->minimal_quantity = 1;
        $combination->default_on = $slot === 1 ? true : null;
        if (!$combination->add()) {
            throw new \RuntimeException('Unable to create the free-line slot combination.');
        }
        $combination->setAttributes([$idAttribute]);
        $idProductAttribute = (int) $combination->id;
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            \StockAvailable::setProductOutOfStock($idProduct, 1, (int) $idShop, $idProductAttribute);
        }

        return [$idProduct, $idProductAttribute];
    }

    /**
     * Ids des produits techniques (à exclure des recherches et des statistiques).
     *
     * @return int[]
     */
    public function freeLineProductIds(): array
    {
        $map = json_decode((string) \Configuration::getGlobalValue(ConfigKeys::FREE_LINE_PRODUCTS), true);

        return is_array($map) ? array_map('intval', array_values($map)) : [];
    }

    private function freeLineProduct(int $idTaxRulesGroup): int
    {
        $map = json_decode((string) \Configuration::getGlobalValue(ConfigKeys::FREE_LINE_PRODUCTS), true) ?: [];
        $id = (int) ($map[(string) $idTaxRulesGroup] ?? 0);
        if ($id && \Validate::isLoadedObject(new \Product($id))) {
            return $id;
        }

        $product = new \Product();
        $product->name = [];
        $product->link_rewrite = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $product->name[(int) $lang['id_lang']] = $lang['iso_code'] === 'fr' ? 'Ligne libre de devis' : 'Quote free line';
            $product->link_rewrite[(int) $lang['id_lang']] = 'ligne-libre-devis-' . $idTaxRulesGroup;
        }
        $product->reference = self::FREE_LINE_REFERENCE . '-' . $idTaxRulesGroup;
        $product->id_tax_rules_group = $idTaxRulesGroup;
        $product->price = 0;
        $product->wholesale_price = 0;
        // Non virtuel : un panier 100 % virtuel n'aurait pas de port.
        $product->is_virtual = false;
        $product->weight = 0;
        $product->active = true;
        $product->visibility = 'none';
        $product->indexed = false;
        $product->available_for_order = true;
        $product->show_price = false;
        $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        $product->redirect_type = '404';
        if (!$product->add()) {
            throw new \RuntimeException('Unable to create the free-line technical product.');
        }
        $product->addToCategories([(int) \Configuration::get('PS_HOME_CATEGORY')]);
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            \StockAvailable::setProductOutOfStock((int) $product->id, 1, (int) $idShop);
        }

        $map[(string) $idTaxRulesGroup] = (int) $product->id;
        \Configuration::updateGlobalValue(ConfigKeys::FREE_LINE_PRODUCTS, (string) json_encode($map));

        return (int) $product->id;
    }

    private function slotAttribute(int $slot): int
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $idGroup = (int) \Db::getInstance()->getValue(
            'SELECT agl.`id_attribute_group` FROM `' . _DB_PREFIX_ . 'attribute_group_lang` agl
             WHERE agl.`name` = \'' . pSQL(self::ATTRIBUTE_GROUP_NAME) . '\''
        );
        if (!$idGroup) {
            $group = new \AttributeGroup();
            $group->is_color_group = false;
            $group->group_type = 'select';
            $group->name = [];
            $group->public_name = [];
            foreach (\Language::getLanguages(false) as $lang) {
                $group->name[(int) $lang['id_lang']] = self::ATTRIBUTE_GROUP_NAME;
                $group->public_name[(int) $lang['id_lang']] = $lang['iso_code'] === 'fr' ? 'Ligne' : 'Line';
            }
            if (!$group->add()) {
                throw new \RuntimeException('Unable to create the technical attribute group.');
            }
            $idGroup = (int) $group->id;
        }

        $idAttribute = (int) \Db::getInstance()->getValue(
            'SELECT a.`id_attribute` FROM `' . _DB_PREFIX_ . 'attribute` a
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang . '
             WHERE a.`id_attribute_group` = ' . $idGroup . ' AND al.`name` = \'' . (int) $slot . '\''
        );
        if ($idAttribute) {
            return $idAttribute;
        }
        $attribute = new \ProductAttribute(); // PS 8.0+ (ex-« Attribute »)
        $attribute->id_attribute_group = $idGroup;
        $attribute->name = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $attribute->name[(int) $lang['id_lang']] = (string) $slot;
        }
        $attribute->position = $slot;
        if (!$attribute->add()) {
            throw new \RuntimeException('Unable to create the technical attribute.');
        }

        return (int) $attribute->id;
    }

    /* ------------------------------------------------------------------ */
    /* Statuts de commande                                                 */
    /* ------------------------------------------------------------------ */

    public function ensureOrderStates(): bool
    {
        $states = [
            ConfigKeys::OS_PENDING => ['fr' => 'Devis — en attente de paiement', 'en' => 'Quote — awaiting payment', 'color' => '#4A90C2', 'logable' => false],
            ConfigKeys::OS_DEFERRED => ['fr' => 'Paiement différé B2B', 'en' => 'Deferred payment (B2B)', 'color' => '#6A3FA0', 'logable' => true],
            ConfigKeys::OS_ERROR => ['fr' => 'Erreur conversion devis', 'en' => 'Quote conversion error', 'color' => '#C62828', 'logable' => false],
        ];
        foreach ($states as $key => $def) {
            $id = (int) \Configuration::getGlobalValue($key);
            if ($id && \Validate::isLoadedObject(new \OrderState($id)) && !(new \OrderState($id))->deleted) {
                continue;
            }
            $state = new \OrderState();
            $state->name = [];
            foreach (\Language::getLanguages(false) as $lang) {
                $state->name[(int) $lang['id_lang']] = $lang['iso_code'] === 'fr' ? $def['fr'] : $def['en'];
            }
            $state->color = $def['color'];
            $state->logable = $def['logable'];
            $state->paid = false;
            $state->invoice = false;
            $state->send_email = false;
            $state->hidden = false;
            $state->delivery = false;
            $state->shipped = false;
            $state->unremovable = true;
            $state->module_name = $this->moduleName;
            if (!$state->add()) {
                return false;
            }
            \Configuration::updateGlobalValue($key, (int) $state->id);
        }
        if (!\Configuration::getGlobalValue(ConfigKeys::OS_TRANSFER)) {
            \Configuration::updateGlobalValue(ConfigKeys::OS_TRANSFER, (int) \Configuration::get('PS_OS_BANKWIRE'));
        }

        return true;
    }
}
