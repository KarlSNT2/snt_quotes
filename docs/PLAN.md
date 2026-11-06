# snt_quotes — Plan technique (étape 1, à valider)

> Statut : **proposition**, aucun code écrit. Version 0.1 — 2026-11-06.
> Cible : PS 8.2.6 et 9.2, PHP 8.1 → 8.4, sans override.

---

## 1. Décisions d'architecture

| Sujet | Décision | Pourquoi |
|---|---|---|
| Persistance | **ObjectModel** légers (`classes/`) pour les entités utilisées par le cœur (hooks, PDF, carrier), **repositories DBAL** (`src/Repository`) pour les lectures/grids | Doctrine ORM dans un module reste fragile entre PS 8 et 9 ; ObjectModel + DBAL marche identique sur les deux |
| BO | Contrôleurs Symfony (`src/Controller/Admin`), Grid PS, Twig, Forms Symfony | Imposé |
| Éditeur de devis | Page Twig + **JS vanilla** (modules ES, pas de build obligatoire) + endpoints JSON | Pas de chaîne Node en PROD ; un `views/js/dist` commité si on ajoute un bundler plus tard |
| Calculs | Un seul `QuoteCalculator` pur (sans DB), appelé par le BO (aperçu AJAX), le PDF, le FO, le converter | Les totaux JS ne sont **jamais** persistés ; le serveur recalcule toujours |
| Argent | Entiers en **centimes** dans le calcul (`Money` value object), `DECIMAL(20,6)` en base | Contrôle « au centime » fiable, pas de dérive float |
| Transitions | `QuoteStateMachine` unique → historique + event interne → webhooks + e-mails | Imposé, un seul point d'entrée |
| Conversion | `QuoteToOrderConverter` (+ sous-services) | Imposé, testable isolément |
| Paiement | `snt_quotes` **est** un `PaymentModule` (`class Snt_Quotes extends PaymentModule`) | Permet `validateOrder()` sans second module ; options de paiement non affichées dans le tunnel standard |
| Cron | `ModuleFrontController` `cron` protégé par token (comparaison `hash_equals`) | Pas de crontab sur PS Platform |
| Traductions | `trans()` + domaines `Modules.Sntquotes.Admin/.Shop/.Pdf/.Emails`, `isUsingNewTranslationSystem(): true` | Imposé |

### 1.1 Mécanique devis → commande (§3 du prompt) — détail

```
QuoteToOrderConverter::convert(Quote $q, ConversionOptions $o): ConversionResult
 ├─ Guard : statut ∈ {accepted, sent*}, id_order NULL, lock DB (SELECT … FOR UPDATE sur snt_quote)
 ├─ TechnicalCartBuilder      → Cart (id_customer, adresses, devise, lang, shop, id_carrier = carrier technique)
 │    ├─ produits catalogue    → updateQty + SpecificPrice(id_cart, id_customer, from_quantity=1, price=net HT, reduction=0)
 │    ├─ lignes libres         → produit virtuel technique « Ligne libre » (1 déclinaison par taux de TVA*)
 │    │                          + customization texte (libellé) + SpecificPrice par ligne
 │    └─ sections/commentaires → ignorés (pas de montant), recopiés en message de commande
 ├─ DiscountApplier           → CartRule dédié (code unique, id_customer, qty 1, montant HT figé, highlight 0)
 │                              ou bon existant revalidé ; port offert → montant sur port intégré au CartRule
 ├─ TechnicalCarrier          → getOrderShippingCost() lit snt_quote.shipping_tax_excl via snt_quote.id_cart
 ├─ PreflightTotalsCheck      → Cart::getOrderTotal(...) vs QuoteTotals (HT, TVA, TTC, port) au centime
 ├─ $module->validateOrder(cart, id_order_state paramétré, total TTC devis, libellé mode, …)
 ├─ PostProcessor
 │    ├─ UPDATE orders / order_carrier SET id_carrier = id_carrier_real (+ recalcul poids)
 │    ├─ copie point relais si transporteur relais (cf. Q4)
 │    ├─ snt_quote.id_order, statut ordered, historique, webhook quote.ordered
 │    └─ suppression SpecificPrice techniques
 └─ PostflightTotalsCheck     → Order vs devis au centime ; écart ⇒ rollback (voir ci-dessous)
```

\* Le produit « Ligne libre » a une `id_tax_rules_group` fixe : on crée **un produit technique par règle de taxe utilisée** (créé à la demande, invisible, `visibility=none`, `active=1`, stock non géré). Plus simple et plus juste que des déclinaisons.

**Rollback** : `validateOrder()` n'est pas transactionnel côté PS (hooks, mails). Stratégie :
1. Contrôle **préalable** sur le panier (bloquant, aucune commande créée) — couvre 99 % des cas.
2. Si l'écart apparaît seulement après création : la commande passe à un statut dédié « Erreur conversion devis » (créé à l'install, non `logable`), le devis reste `accepted`, historique + alerte BO. Pas de `DELETE` sur `orders` (traçabilité compta / OASIS).

**Neutralisation des modules tiers sur le panier technique** : le panier porte un marqueur (`snt_quote_cart`) ; `orderfees_shipping` et ASC sont vérifiés en test. Si l'un modifie le port du transporteur technique, on l'exclut via sa propre config (règle « sauf transporteur X ») — à valider (Q6).

**E-mail `order_conf`** : envoyé par `validateOrder` *avant* le post-traitement, donc avec le nom du transporteur technique (« Livraison selon devis »). Option : intercepter via `actionEmailSendBefore` pour y injecter le nom du transporteur réel (Q8).

---

## 2. Arborescence

```
snt_quotes/
├── snt_quotes.php                     # Module (extends PaymentModule) : install, hooks, paiement, getOrderShippingCost
├── config.xml / logo.png / index.php
├── composer.json                      # autoload PSR-4 Snt\Quotes\ => src/ ; classmap classes/
├── config/
│   ├── services.yml                   # services BO (+ admin.yml / front.yml si besoin)
│   ├── routes.yml
│   └── admin/services.yml             # grid definitions, form types
├── classes/                           # ObjectModel (namespace global, préfixe SntQuote*)
│   ├── SntQuote.php  SntQuoteLine.php  SntQuoteDiscount.php
│   ├── SntQuoteHistory.php  SntQuoteMessage.php
│   ├── SntQuotePaymentLink.php  SntQuotePdf.php  SntQuoteWebhookLog.php
│   └── pdf/HTMLTemplateSntQuote.php   # HTMLTemplate natif (nom imposé par PDF::render)
├── src/
│   ├── Install/       Installer, SchemaInstaller, TabInstaller, CarrierInstaller, TechnicalProductInstaller, OrderStateInstaller, ConfigInstaller
│   ├── Domain/
│   │   ├── QuoteStatus.php            # enum PHP 8.1 + couleurs/labels badges
│   │   ├── LineType.php               # product | free | section | comment
│   │   ├── Money.php  TaxBreakdown.php  QuoteTotals.php
│   │   └── Exception/                 # QuoteNotConvertibleException, TotalsMismatchException, …
│   ├── Calculator/
│   │   ├── QuoteCalculator.php        # lignes → remises ligne → remise globale → port → TVA par taux → totaux
│   │   ├── TaxResolver.php            # taux par ligne selon adresse (pays, intracom via snt_inscription_pro)
│   │   └── CatalogPriceProvider.php   # prix client (groupe, SpecificPrice, dégressifs), prix d'achat
│   ├── Repository/    QuoteRepository, QuoteLineRepository, HistoryRepository, PaymentLinkRepository, StatsRepository
│   ├── Service/
│   │   ├── QuoteFactory.php           # création / duplication / nouvelle version / depuis panier|commande
│   │   ├── QuoteEditor.php            # applique un payload d'édition validé (DTO) → recalcul → persist
│   │   ├── QuoteStateMachine.php
│   │   ├── ReferenceGenerator.php     # DEV-{SHOP}-{AAAA}-{n°}, compteur par boutique+année (table snt_quote_sequence)
│   │   ├── CarrierEligibilityChecker.php  # transporteur éligible ? + tarif calculé (sans le bloquer)
│   │   ├── DiscountGuard.php          # seuil remise, marge < prix d'achat, droit employé
│   │   ├── BulkLineImporter.php       # « réf ; qté » et CSV
│   │   ├── QuoteMailer.php            # Mail::send + pièces jointes + log historique
│   │   ├── QuotePdfGenerator.php      # aperçu à la volée + archive versionnée (sha256)
│   │   ├── PaymentLinkService.php     # token 32 o, hash sha256, expiration, usage unique
│   │   ├── Webhook/WebhookDispatcher.php, WebhookSigner.php (HMAC, X-Snt-Signature, retry ×3)
│   │   ├── Cron/CronRunner.php        # relances J-x, expirations, purge, retry webhooks
│   │   └── Permission/QuotePermissions.php  # droits par profil (créer, remise > seuil, convertir, voir marge)
│   ├── Conversion/
│   │   ├── QuoteToOrderConverter.php
│   │   ├── TechnicalCartBuilder.php  DiscountApplier.php  OrderPostProcessor.php  TotalsComparator.php
│   ├── Payment/
│   │   ├── PaymentMethodRegistry.php  # quote_pending | transfer | deferred_b2b | hipay_card
│   │   └── HiPay/HiPayHostedPageClient.php, HiPayMotoFallback.php, HiPayNotificationHandler.php
│   ├── Grid/          Definition/Factory/QuoteGridDefinitionFactory.php, Query/QuoteQueryBuilder.php, Filters/QuoteFilters.php
│   ├── Form/          QuoteConfigurationType (onglets), QuoteHeaderType, QuickCustomerType, QuickAddressType, SendQuoteType, DedicatedVoucherType
│   ├── Controller/Admin/
│   │   ├── QuoteController.php        # index (grid + onglets rapides), view, edit, create, delete, duplicate, pdf
│   │   ├── QuoteApiController.php     # JSON : recherche client/produit/bon, recalcul, autosave, lignes, import
│   │   ├── QuoteActionController.php  # send, convert, payment-link, expire, relance, transitions
│   │   ├── ConfigurationController.php
│   │   └── StatsController.php        # jalon 4
│   └── Hook/          AbstractHook + 1 classe par groupe (CartHooks, CustomerAccountHooks, AdminCustomerHooks, AdminOrderHooks, OrderStatusHooks)
├── controllers/front/
│   ├── request.php      # FO-01 panier → demande
│   ├── list.php         # FO-02 Mes devis
│   ├── view.php         # FO-03/04/06 détail, accepter, refuser, modification, PDF
│   ├── pay.php          # FO-05 page de paiement par lien signé (sans login)
│   ├── payreturn.php    # retours HiPay (accept/decline/cancel) + notification serveur
│   └── cron.php         # endpoint n8n
├── views/
│   ├── templates/admin/ (Twig)  quote/index, edit, view, _summary, _timeline, _messages, _payment_link, modals/*, configuration/*, hook/*
│   ├── templates/front/ (Smarty, surchargeables)  request, request-confirm, list, view, pay, pay-expired, pay-done, _badge, _lines, _totals
│   ├── templates/hook/  shopping-cart-button.tpl, customer-account-link.tpl, product-button.tpl
│   ├── templates/pdf/   quote.tpl, quote-header.tpl, quote-footer.tpl, quote-lines.tpl, quote-tax.tpl, quote-cgv.tpl
│   ├── css/  admin.css, front.css   (variables CSS pour badges)
│   └── js/   admin/editor.js, admin/customer-search.js, admin/product-search.js, admin/view.js, front/quote.js
├── mails/{fr,en}/  snt_quote_sent, _new_version, _reminder, _request_received, _revision_requested,
│                   _accepted_customer, _accepted_employee, _declined, _payment_link, _paid, _expired  (.html + .txt)
├── translations/  (catalogues xlf générés)
├── upgrade/       upgrade-x.y.z.php
├── sql/           install.sql, uninstall.sql (lus par SchemaInstaller)
├── tests/
│   ├── Unit/      Calculator/*, Money, ReferenceGenerator, WebhookSigner, PaymentLinkService, StateMachine
│   ├── Integration/ QuoteToOrderConverterTest (bootstrap PS DEV), fixtures/
│   └── manual/    checklist-recette.md
├── phpstan.neon (niveau 6, extension prestashop/phpstan-prestashop) · .php-cs-fixer.dist.php · phpunit.xml
├── README.md
└── docs/  PLAN.md, guide-utilisateur.md, webhooks.md
```

---

## 3. Schéma SQL (préfixe `PREFIX_`, InnoDB, utf8mb4)

```sql
CREATE TABLE PREFIX_snt_quote (
  id_quote INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  id_parent_quote INT UNSIGNED NULL,               -- version précédente
  id_root_quote INT UNSIGNED NULL,                 -- 1re version (regroupement)
  id_shop INT UNSIGNED NOT NULL, id_lang INT UNSIGNED NOT NULL, id_currency INT UNSIGNED NOT NULL,
  conversion_rate DECIMAL(13,6) NOT NULL DEFAULT 1,
  id_customer INT UNSIGNED NOT NULL, id_employee INT UNSIGNED NULL,
  id_address_delivery INT UNSIGNED NULL, id_address_invoice INT UNSIGNED NULL,
  project_name VARCHAR(128) NULL,
  id_carrier_real INT UNSIGNED NULL,               -- id_reference recommandé ? cf. note
  id_carrier_reference INT UNSIGNED NULL,          -- robustesse si le carrier est réédité
  carrier_label VARCHAR(128) NULL,                 -- figé (affichage PDF/FO)
  delivery_mode ENUM('carrier','pickup','special') NOT NULL DEFAULT 'carrier',
  relay_data JSON NULL,                            -- point relais figé si transporteur relais
  shipping_tax_excl DECIMAL(20,6) NOT NULL DEFAULT 0,
  shipping_discount_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  id_tax_rules_group_shipping INT UNSIGNED NULL,
  shipping_tax_rate DECIMAL(10,3) NOT NULL DEFAULT 0,
  global_discount_type ENUM('percent','amount') NULL, global_discount_value DECIMAL(20,6) NULL,
  total_products_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  total_discount_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  total_shipping_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  total_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  total_tax DECIMAL(20,6) NOT NULL DEFAULT 0,
  total_ti DECIMAL(20,6) NOT NULL DEFAULT 0,
  tax_breakdown JSON NULL,                         -- [{rate, base_te, tax}] figé
  vat_number VARCHAR(32) NULL, tax_exempt TINYINT(1) NOT NULL DEFAULT 0,  -- autoliquidation figée
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  pending_internal_approval TINYINT(1) NOT NULL DEFAULT 0,  -- remise > seuil
  valid_until DATE NULL,
  payment_mode VARCHAR(32) NULL,                   -- mode retenu à l'acceptation
  allowed_payment_methods JSON NULL,
  customer_message TEXT NULL, internal_note TEXT NULL,
  id_cart INT UNSIGNED NULL,                       -- panier technique (lien carrier technique)
  id_cart_source INT UNSIGNED NULL, id_order_source INT UNSIGNED NULL,  -- BO-13 / FO-01
  id_order INT UNSIGNED NULL,
  sent_at DATETIME NULL, viewed_at DATETIME NULL,
  accepted_at DATETIME NULL, accepted_ip VARCHAR(45) NULL, accepted_cgv_version VARCHAR(32) NULL,
  last_reminder_at DATETIME NULL,
  source ENUM('bo','fo_cart','fo_product','opart_import') NOT NULL DEFAULT 'bo',
  date_add DATETIME NOT NULL, date_upd DATETIME NOT NULL,
  UNIQUE KEY uk_reference_version (id_shop, reference, version),
  UNIQUE KEY uk_id_order (id_order),
  KEY k_customer (id_customer), KEY k_status (id_shop, status), KEY k_valid (valid_until), KEY k_cart (id_cart)
);

CREATE TABLE PREFIX_snt_quote_line (
  id_quote_line INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL,
  line_type ENUM('product','free','section','comment') NOT NULL,
  id_product INT UNSIGNED NULL, id_product_attribute INT UNSIGNED NULL,
  label VARCHAR(255) NULL, reference VARCHAR(64) NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 0,
  catalog_price_te DECIMAL(20,6) NULL,             -- prix catalogue au moment du gel (affichage barré)
  unit_price_te DECIMAL(20,6) NOT NULL DEFAULT 0,   -- prix saisi avant remise ligne
  reduction_type ENUM('percent','amount') NULL, reduction_value DECIMAL(20,6) NULL,
  unit_net_te DECIMAL(20,6) NOT NULL DEFAULT 0,     -- figé
  total_te DECIMAL(20,6) NOT NULL DEFAULT 0,
  id_tax_rules_group INT UNSIGNED NULL, tax_rate DECIMAL(10,3) NOT NULL DEFAULT 0,
  ecotax DECIMAL(17,6) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(20,6) NULL,              -- BO uniquement
  comment TEXT NULL,                               -- visible client
  position INT UNSIGNED NOT NULL DEFAULT 0,
  KEY k_quote (id_quote, position)
);

CREATE TABLE PREFIX_snt_quote_discount (
  id_quote_discount INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL,
  scope ENUM('global','shipping','voucher') NOT NULL,  -- remise « line » portée par snt_quote_line
  id_cart_rule INT UNSIGNED NULL, is_dedicated TINYINT(1) NOT NULL DEFAULT 0,
  code VARCHAR(254) NULL, label VARCHAR(255) NOT NULL,
  amount_te DECIMAL(20,6) NOT NULL, amount_ti DECIMAL(20,6) NOT NULL,
  KEY k_quote (id_quote)
);

CREATE TABLE PREFIX_snt_quote_history (
  id_quote_history INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,                      -- status_change | email_sent | pdf_archived | payment_link | note …
  status_from VARCHAR(24) NULL, status_to VARCHAR(24) NULL,
  actor_type ENUM('employee','customer','system') NOT NULL, actor_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL, comment TEXT NULL, payload JSON NULL,
  date_add DATETIME NOT NULL, KEY k_quote (id_quote, date_add)
);

CREATE TABLE PREFIX_snt_quote_message (
  id_quote_message INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL,
  author_type ENUM('employee','customer') NOT NULL, author_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL, attachment VARCHAR(255) NULL, attachment_name VARCHAR(255) NULL,
  read_at DATETIME NULL, date_add DATETIME NOT NULL, KEY k_quote (id_quote)
);

CREATE TABLE PREFIX_snt_quote_payment_link (
  id_quote_payment_link INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,                    -- sha256(token)
  purpose ENUM('view','pay') NOT NULL DEFAULT 'pay',
  methods JSON NULL, expires_at DATETIME NOT NULL,
  used_at DATETIME NULL, revoked_at DATETIME NULL,
  hipay_transaction_ref VARCHAR(64) NULL,
  date_add DATETIME NOT NULL,
  UNIQUE KEY uk_token (token_hash), KEY k_quote (id_quote)
);

CREATE TABLE PREFIX_snt_quote_pdf (
  id_quote_pdf INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_quote INT UNSIGNED NOT NULL, version SMALLINT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,                 -- hors webroot si possible (cf. Q5)
  sha256 CHAR(64) NOT NULL, date_add DATETIME NOT NULL, KEY k_quote (id_quote)
);

CREATE TABLE PREFIX_snt_quote_sequence (            -- numérotation sans trou par boutique/année
  id_shop INT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL, last_number INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_shop, year)
);

CREATE TABLE PREFIX_snt_quote_webhook_log (
  id_webhook_log INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_shop INT UNSIGNED NOT NULL, id_quote INT UNSIGNED NULL,
  event VARCHAR(32) NOT NULL, payload JSON NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, http_code SMALLINT NULL, response TEXT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  next_retry_at DATETIME NULL, date_add DATETIME NOT NULL, date_upd DATETIME NOT NULL,
  KEY k_status (status, next_retry_at)
);
```

Écarts volontaires vs le tableau du prompt : remises de ligne stockées sur la ligne (pas dans `snt_quote_discount`) ; ajout de `tax_breakdown`, `carrier_label`, `relay_data`, `id_cart`, `snt_quote_sequence`, `snt_quote_webhook_log` (journal exigé §7).

**Statuts** (`QuoteStatus` enum) : `draft, pending_approval, sent, viewed, revision_requested, accepted, declined, expired, ordered, paid, cancelled`.
`pending_approval` = « En attente de validation interne » (§5.B). `cancelled` = remplacé par une nouvelle version.

Transitions autorisées (tout le reste est refusé) :

```
draft → pending_approval | sent
pending_approval → draft | sent
sent → viewed | accepted | declined | expired | revision_requested | cancelled
viewed → accepted | declined | expired | revision_requested | cancelled
revision_requested → draft (même version) | cancelled (nouvelle version créée)
accepted → ordered | expired(non) | cancelled
ordered → paid
```

---

## 4. Routes BO (`config/routes.yml`, préfixe `admin_snt_quotes_`)

| Route | Méthode | Chemin | Action |
|---|---|---|---|
| `index` | GET | `/sntquotes` | Grid + onglets (`?tab=todo|waiting|all`) |
| `search` / `filter` | POST | `/sntquotes/search` | Filtres grid |
| `create` | GET | `/sntquotes/new?id_customer=` | Nouveau devis (brouillon créé au 1er enregistrement) |
| `edit` | GET | `/sntquotes/{id}/edit` | Éditeur |
| `view` | GET | `/sntquotes/{id}` | Fiche consultation |
| `save` | POST | `/sntquotes/{id}/save` | Enregistrer (form) |
| `api_recalc` | POST | `/sntquotes/api/recalculate` | Recalcul récap (JSON) |
| `api_autosave` | POST | `/sntquotes/{id}/api/autosave` | Autosave 30 s |
| `api_customer_search` / `api_product_search` / `api_voucher_search` | GET | `/sntquotes/api/…` | Autocomplétions |
| `api_carriers` | GET | `/sntquotes/{id}/api/carriers` | Liste + éligibilité + tarif calculé |
| `api_bulk_lines` | POST | `/sntquotes/{id}/api/bulk-lines` | Coller / CSV |
| `quick_customer` / `quick_address` | POST | `/sntquotes/api/customer`, `/address` | Créations rapides |
| `pdf` | GET | `/sntquotes/{id}/pdf` | Aperçu / téléchargement |
| `send_preview` | POST | `/sntquotes/{id}/send/preview` | Aperçu e-mail + PDF |
| `send` | POST | `/sntquotes/{id}/send` | Envoi |
| `convert` | POST | `/sntquotes/{id}/convert` | Conversion (droit dédié) |
| `payment_link` | POST | `/sntquotes/{id}/payment-link` | Générer / renvoyer |
| `transition` | POST | `/sntquotes/{id}/status/{to}` | Expirer, relancer, approuver… |
| `duplicate` / `new_version` | POST | `/sntquotes/{id}/duplicate`, `/version` | BO-12 |
| `from_cart` / `from_order` | POST | `/sntquotes/from-cart/{id}`, `/from-order/{id}` | BO-13 |
| `message` | POST | `/sntquotes/{id}/message` | Réponse équipe |
| `bulk_remind` / `bulk_expire` | POST | `/sntquotes/bulk/…` | Actions de masse |
| `config` | GET/POST | `/sntquotes/configuration` | Onglets config |
| `webhook_test` | POST | `/sntquotes/configuration/webhook-test` | Bouton « Tester » |
| `stats` | GET | `/sntquotes/stats` | BO-15 |

Toutes les routes : `_legacy_controller: AdminSntQuotes`, attribut `#[AdminSecurity]` (PS 9) / annotation (PS 8) — on utilise la **clé `_legacy_link` + vérification manuelle `isGranted`** dans un `AbstractQuoteController` pour rester compatible des deux.

Onglets BO : `AdminSntQuotes` (parent : Commandes > Devis) + `AdminSntQuotesConfiguration` (masqué, via le bouton Configurer).

**Routes FO** (`moduleRoutes`) : `/mes-devis`, `/mes-devis/{reference}`, `/devis/payer/{token}`, `/devis/demande`, `module/snt_quotes/cron?token=`.

---

## 5. Hooks enregistrés

`displayShoppingCart` (bouton), `displayCustomerAccount`, `moduleRoutes`, `actionFrontControllerSetMedia`, `displayAdminCustomers`, `displayAdminOrderMain`, `actionOrderStatusPostUpdate` (→ `paid`), `actionObjectOrderAddAfter`, `paymentOptions` (retourne `[]` : moyens jamais visibles dans le tunnel), `displayProductAdditionalInfo` (FO-07), `actionCarrierUpdate` (suivi du carrier technique s'il est réédité), `actionEmailSendBefore` (option : adapter `order_conf`), `actionCartSave`? **non** (pas de hook panier pour coexister avec Opart).

---

## 6. Configuration (clés `SNT_QUOTES_*`, par boutique)

Général : `VALIDITY_DAYS`(30), `REMINDER_DAYS`(7), `REF_PATTERN`, `SHOP_CODE`, `STOCK_RESERVATION`(0), `FO_REQUEST_ENABLED`, `FO_PRODUCT_BUTTON`(0), `RESPONSE_DELAY_DAYS`.
Paiement : `METHODS_DEFAULT`(`hipay_card,transfer`), `OS_QUOTE_PENDING`, `OS_TRANSFER`, `OS_DEFERRED`, `OS_HIPAY_PAID`, `PAYLINK_TTL_DAYS`, `HIPAY_*` (si non relus depuis le module HiPay), `IBAN`, `PAYMENT_TERMS`.
PDF/e-mails : `PDF_COLOR`, `PDF_LEGAL`, `PDF_CGV_SOURCE`(none|text|cms), `PDF_CGV_ID_CMS`, `MAIL_FROM`(shop|employee), `MAIL_ENABLED_{event}`, `MAIL_CC_EMPLOYEE`.
Droits : `DISCOUNT_THRESHOLD`(15), `PROFILES_APPROVE`, `PROFILES_CONVERT`, `PROFILES_SEE_MARGIN`.
Webhooks : `WEBHOOK_URL`, `WEBHOOK_SECRET`, `WEBHOOK_EVENTS`, `CRON_TOKEN`.
Technique (non éditable) : `CARRIER_ID_REFERENCE`, `FREE_LINE_PRODUCTS` (JSON taxe→id_product), `OS_CONVERSION_ERROR`.

---

## 7. Jalons et découpage des commits

**Jalon 1 — BO interne**
1. Squelette, composer, outillage qualité (phpstan, cs-fixer, phpunit), install/uninstall/upgrade, tables, onglets, config.
2. Domaine + `QuoteCalculator` + tests unitaires (TVA, remises, port, arrondis par ligne vs total — cf. Q3).
3. Grid + onglets rapides + actions de ligne.
4. Éditeur (client, lignes, livraison, remises, conditions, récap AJAX, autosave).
5. Fiche consultation + `QuoteStateMachine` + historique.
6. PDF + archivage. 7. E-mails + modale d'envoi.

**Jalon 2 — Conversion & paiement**
8. Carrier technique + produits techniques + statuts de commande. 9. `QuoteToOrderConverter` + tests intégration. 10. Module de paiement intégré. 11. Liens de paiement + HiPay. 12. Intégrations fiche client / commande.

**Jalon 3 — FO & cycle de vie**
13. Panier → demande. 14. Mes devis / détail / accepter / refuser / modification. 15. Page de paiement par lien. 16. Cron (relances, expirations) + webhooks signés + journal.

**Jalon 4 — Options**
17. Versionnage complet. 18. Depuis panier/commande. 19. Stats. 20. Bouton fiche produit. 21. Script reprise Opart.

---

## 8. Risques identifiés

| Risque | Mitigation |
|---|---|
| `validateOrder()` recalcule et diffère de 1 ct (arrondi PS par ligne/total/article) | Lire `PS_ROUND_TYPE` et `PS_PRICE_ROUND_MODE` et calculer **exactement comme le cœur** dans `QuoteCalculator` ; test d'égalité sur chaque mode |
| `orderfees_shipping` / ASC modifient le port ou ajoutent des frais sur le panier technique | Test dédié jalon 2, exclusion du carrier technique dans leur config |
| `snt_carriers::hookActionValidateOrder` matérialise l'adresse relais à partir du carrier du panier (technique) → rien n'est matérialisé | Le post-processeur appelle `RelayAddressMaterializer` avec le carrier réel et `relay_data` du devis (cf. Q4) |
| Réaffecter `id_carrier` après coup : le module HiPay / OASIS / n8n a déjà lu la commande via `actionValidateOrder` | Webhook `quote.ordered` émis **après** post-traitement ; vérifier ce que lit le flux n8n commande (Q7) |
| Le carrier technique doit être éligible mais jamais affiché en FO | Actif, toutes zones/groupes (requis par `validateOrder`), et **retiré du tunnel** via `actionFilterDeliveryOptionList` (PS 8 et 9) sauf sur un panier technique |
| Prix TTC du groupe client (affichage) vs prix HT figé | Prix toujours figés HT, TTC dérivé |
| Multidevise | Devis dans la devise du client, taux figé (`conversion_rate`) |

---

## 9. Décisions validées (réponses du 2026-11-06)

Ces décisions **priment** sur les sections précédentes en cas d'écart.

### D1 — Paiement en ligne : on s'appuie sur le module de paiement de chaque boutique, via un adaptateur

Deux modules en place : **PayPlug** (defense-privee) et **HiPay Payments** `hipaypayments` (nouveau module HiPay, v4.x pour PS 8, v5.x pour PS 9) sur les autres boutiques. `hipay_enterprise` est hors périmètre.
Interface `Snt\Quotes\Payment\Gateway\PaymentGatewayInterface` (`supports()`, `startPayment(Quote)`, `getPaymentUrl(Quote)`, `isAvailable(shop)`), une implémentation par module, choisie dans la config par boutique. **Aucun identifiant de paiement n'est stocké dans `snt_quotes`** : on utilise ceux déjà configurés dans le module de paiement.

**HiPay Payments (`hipaypayments` 4.4.3, code lu)**
- La notification (`controllers/front/notify.php`) retrouve le **panier** depuis `orderid = "{id_cart}-{aléa}"`. S'il n'y a pas de commande pour ce panier, `TransactionPresenter::presentNewOrder` → **`validateOrder()` par le module HiPay**. S'il y en a une, elle n'est mise à jour **que si `order->module === 'hipaypayments'`**.
- Flux « Accepter et payer » :
  1. la page de paiement de `snt_quotes` prépare le **panier technique** (figé, jamais rattaché au cookie client) ;
  2. `snt_quotes` construit **lui-même** la requête de page hébergée (`HiPay\Fullservice\Gateway\Request\Order\HostedPaymentPageRequest`, SDK embarqué par le module HiPay) : `orderid = "{id_cart}-{aléa 8}"` (format attendu par `notify.php`), montant et devise du devis, infos client et adresses, `eci = 7` (e-commerce, 3DS selon la config HiPay), produits carte actifs, URLs de retour vers nos pages (le client n'est pas forcément connecté). Elle est envoyée via le service **`hp.sdk.gateway`** (`PrestaShopSDK::init(shop, group, CREDENTIALS_TYPE_MAIN)->server()->requestHostedPaymentPage()`), qui porte les identifiants du module ;
  3. HiPay notifie le module HiPay → `validateOrder()` sur le panier technique → le transporteur technique renvoie le port du devis → totaux identiques ;
  4. notre hook `actionValidateOrder` fait le post-traitement (D2).
- Avantages : la commande appartient à `hipaypayments` (capture, remboursement, statuts gérés par HiPay) ; aucune commande impayée avant le paiement.
- Repli équipe : le bloc MO/TO du module HiPay n'apparaît que pour une commande créée par `hipaypayments` (table `hipaypayments_moto_order`). L'option « Convertir et encaisser par téléphone » crée la commande avec le module `hipaypayments` + une ligne `hipaypayments_moto_order` ; l'équipe utilise ensuite le bloc HiPay de la fiche commande.
- Comparaison v4.4.3 (PS 8) / **v5.2.1** (PS 9, compatibilité 9.0 → 9.3), faite le 2026-11-06 :
  - **identiques** : `PrestaShopSDK` (`hp.sdk.gateway`), `HiPayPaymentsMotoOrder`, le format `orderid` et la logique de `notify.php` ;
  - **différent** : `CardRequestBuilder`. La v5 n'a plus l'option `forceHostedPage` : si la boutique affiche la carte en « champs intégrés », le builder produit une requête directe à jeton JS, inutilisable pour un lien. Seul le mode `moto` force la page hébergée, mais avec `eci=1` (MO/TO, sans 3DS), ce qui ne convient pas à un paiement fait par le client ;
  - **conséquence** : on **n'utilise pas** `hp.payment_request.builder`. La requête est construite par notre `HiPayHostedPageRequestFactory`, et seul `hp.sdk.gateway` est réutilisé. Cela fonctionne de la même façon en v4 et en v5, quel que soit le mode d'affichage choisi dans HiPay ;
  - **test de non-régression jalon 2** : à chaque mise à jour de `hipaypayments`, vérifier `notify.php` (format `orderid`, `presentNewOrder`) et la signature de `PrestaShopSDK::init()`.

**PayPlug (defense-privee, code lu en v4.22.0 et v5.1.1 : logique identique)**
- `Hook::actionOrderStatusUpdate` → `createPaymentLinkAction()` : si `order->module === 'payplug'` **et** que le statut vaut `order_state_email_link` (ou `sms_link`), PayPlug crée la ressource de paiement sur le panier de la commande et **envoie lui-même le lien** au client.
- Flux : à l'acceptation (ou par l'équipe), le converter appelle `validateOrder()` **avec l'instance du module `payplug`** et le statut « lien de paiement par e-mail » configuré dans PayPlug. Le montant est celui du panier technique, donc égal au devis. Le lien est relu dans la ressource PayPlug (retrouvée par `id_cart` dans le `PaymentRepository` de PayPlug, puis `hosted_payment.payment_url`) pour l'afficher sur la fiche devis et la page FO.
- Garde-fou PayPlug : une seule ressource de lien par panier. Une nouvelle version du devis implique donc un nouveau panier technique.
- La commande existe **avant** le paiement (statut « attente lien PayPlug »). **Confirmé : ce statut n'est pas synchronisé vers OASIS par n8n.** La commande ne part vers l'ERP qu'une fois payée.

**Moyens hors carte** (virement, paiement différé B2B, « en attente ») : conversion par le module de paiement intégré à `snt_quotes`, inchangé.

### D2 — Post-traitement dans `actionValidateOrder`
Le post-traitement (réaffectation `orders.id_carrier` + `order_carrier.id_carrier`) est exécuté **dans le hook `actionValidateOrder`** pour tout panier marqué devis, quel que soit le module de paiement. Ce hook s'exécute **avant** l'écriture du premier statut de commande : n8n (synchro par statut) voit donc toujours le transporteur réel.

### D3 — Convertir un panier client existant en devis (promu en jalon 1)
Dans l'éditeur, section Client : « Reprendre un panier du client » liste ses paniers non commandés récents (date, nb d'articles, montant) ; un clic importe les lignes au prix catalogue client. Aussi accessible depuis la fiche client. Le panier d'origine n'est pas modifié. n8n / OASIS ne lisant que les commandes, les paniers techniques sont sans impact.

### D4 — Arrondis : logique native PrestaShop
`QuoteCalculator` applique `PS_ROUND_TYPE` (article / ligne / total), `PS_PRICE_ROUND_MODE` et la précision de la devise via `Tools::ps_round()` et `Context::getComputingPrecision()`, comme `Cart::getOrderTotal()`. Tests unitaires sur les trois modes. La vérification finale compare de toute façon au total calculé par le cœur sur le panier technique.

### D5 — Point relais
`snt_carriers` matérialise le relais à partir de la table `snt_cart_relay` (clé `id_cart`) : adresse `deleted=1` au nom du client, `company` = nom du relais, `other` = `provider:relay_id` (lu par l'ERP).
→ Le devis stocke le relais choisi (`relay_data` JSON : provider, relay_id, name, address, zip, city). À la conversion, on **écrit une ligne `snt_cart_relay` pour le panier technique** : le hook existant de `snt_carriers` fait la matérialisation, sans dupliquer sa logique. Dans l'éditeur, le choix du relais réutilise le provider relais de `snt_carriers` (recherche par code postal) quand le transporteur choisi est de type `relay`.

### D6 — PDF sur Object Storage Scaleway
Archive dans un bucket unique : `{bucket}/{code_site}/Devis-{reference}-v{version}.pdf` (le numéro de version est conservé dans le nom pour garder chaque envoi). Bucket privé, téléchargement BO/FO via **URL présignée** courte. Client S3 inspiré de `snt_doc_checker/s3/S3Utils.php` (signature SigV4 maison, sans SDK AWS), réécrit typé dans `Snt\Quotes\Storage\S3Storage`. Config : `S3_ENDPOINT` (défaut `https://s3.fr-par.scw.cloud`), `S3_REGION` (`fr-par`), `S3_BUCKET`, `S3_KEY`, `S3_SECRET`, `S3_SITE_CODE`. Le sha256 reste stocké en base.

### D7 — Port : règles `orderfees_shipping` pour proposer, le devis décide
Le bouton « Tarif calculé » simule un panier avec le transporteur réel et lit le coût via le calcul natif (donc avec les règles `orderfees_shipping` / ASC) : l'opérateur voit le vrai coût, puis garde, modifie ou offre le port. Le transporteur technique doit être **exclu des règles `orderfees_shipping`** (réglage côté module, documenté dans le README) ; le contrôle des totaux avant conversion le détecte si ce n'est pas fait.

### D8 — E-mail `order_conf`
Le nom du transporteur réel est injecté via `actionEmailSendBefore` (variable `{carrier}`) quand la commande vient d'un devis.

### Questions encore ouvertes (non bloquantes pour le jalon 1)
- Code site par boutique pour le bucket et la référence (`DEV-{SHOP}-…`) : proposition = même code (d'après les sauvegardes `snt_tools` : `ABOT`, `ARS`, `DEF`, `DISTRI`, `EVO`, `EZ`, `TAG`), à confirmer.

---

## 10. Avancement

### Jalon 1 — livré le 2026-11-06 (v0.1.0)

Fait : installation (tables, onglet, configuration par boutique, hook `actionSntQuoteStatusUpdate`), droits, liste Grid avec
onglets rapides et actions de masse, éditeur une page (client, produits, livraison, remises, conditions, récap AJAX, autosave),
fiche avec barre d'actions contextuelle et historique, PDF TCPDF surchargeable + archive Scaleway SHA-256, envoi d'e-mail avec
aperçu, configuration Symfony, catalogue FR.

Écarts au plan, volontaires :
- contrôleurs basés sur `FrameworkBundleAdminController` (présent en 8.2 et 9.x) avec injection par constructeur, droits
  vérifiés dans le code : la syntaxe `@AdminSecurity` / `#[AdminSecurity]` diffère entre les deux versions ;
- modales en `<dialog>` natif et JS sans build ni jQuery ;
- remise sur le port portée par le devis (`shipping_discount_te`), pas par une règle panier : à la conversion, le transporteur
  technique renverra directement le port net ;
- « Convertir en commande » est visible mais désactivé jusqu'au jalon 2.

Vérifié : PHPUnit (30 tests), PHPStan niveau 6 sur tout le module contre les sources PS 8.2.3, php-cs-fixer, analyse
syntaxique des gabarits Twig avec le Twig de PS 8.
**Non vérifié** : installation et parcours dans une vraie boutique (8.2.6 et 9.2), à dérouler avec
`tests/manual/checklist-recette.md`.
