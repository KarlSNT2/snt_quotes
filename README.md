# snt_quotes — Devis sans tunnel de commande

Module PrestaShop SNT2 qui remplace Opart Devis. Un devis est un **document figé** (lignes, prix HT, TVA,
transporteur, port, remises). À terme, sa conversion crée la commande **directement**, sans repasser par le tunnel
ni par les règles transporteurs.

- PrestaShop **8.2.x** et **9.x**, PHP **8.1 à 8.4**
- Aucun override. Back office en Symfony (Grid, Twig, formulaires), front office en `ModuleFrontController` + Smarty
- Architecture et décisions : [docs/PLAN.md](docs/PLAN.md) · Guide des équipes : [docs/guide-utilisateur.md](docs/guide-utilisateur.md)

## État d'avancement

| Jalon | Contenu | État |
|---|---|---|
| 1 — BO interne | Installation, tables, configuration, droits, liste, éditeur, fiche, PDF, envoi e-mail | ✅ livré (v0.1.0) |
| 2 — Conversion et paiement | Conversion sans tunnel, transporteur technique, paiement intégré, liens de paiement HiPay Payments / PayPlug, page de paiement client | ✅ livré (v0.2.0, correctifs v0.2.1), recette DEV en cours |
| 3 — Front office et cycle de vie | Espace « Mes devis » (consultation, PDF, accepter et payer, demande de modification, refus, échanges), affichage TTC/HT par boutique | ✅ codé : « Mes devis » (v0.3.0), demande depuis le panier (v0.3.1), tâche planifiée (v0.3.2). Recette DEV en cours. Webhooks n8n abandonnés |
| 4 — Options | Relais GLS (v0.3.2) ; statistiques ; reprise Opart facultative (coexistence prévue) ; pas de bouton fiche produit | 🟡 GLS codé, reste les statistiques |

## Installation

1. Copier le dossier `snt_quotes` dans `modules/` (ou importer le zip depuis le gestionnaire de modules).
2. Dépendances : aucune bibliothèque tierce à l'exécution ; le module charge ses classes lui-même et **ne lit jamais
   `vendor/`**. Ne livrez pas `vendor/` (outils de développement, des milliers de fichiers), ni `.git`. Le script
   `tools/build-zip.ps1` fabrique un zip propre (`build/snt_quotes-<version>.zip`, chemins compatibles Linux).
3. Installer le module. Il crée :
   - les tables `snt_quote*` (devis, lignes, remises, historique, messages, liens, archives PDF, compteurs, paniers techniques) ;
   - l'onglet **Commandes > Devis** (`AdminSntQuotes`) ;
   - la configuration par défaut **pour chaque boutique** (validité 30 j, seuil de remise 15 %, CB + virement…) ;
   - le hook `actionSntQuoteStatusUpdate`, émis à chaque changement de statut.
4. Vider le cache (Paramètres avancés > Performances).

> ⚠️ La désinstallation **supprime les tables et donc tous les devis**. Pour une mise à jour, utiliser « Mettre à jour », jamais désinstaller puis réinstaller.

### Coexistence avec Opart Devis

Le jalon 1 n'utilise aucun hook panier ni front office : les deux modules peuvent rester installés côte à côte.

## Configuration (Commandes > Devis > bouton « Configurer », ou gestionnaire de modules)

La configuration est **par boutique** : en multiboutique, choisir la boutique dans le sélecteur avant d'enregistrer.

| Onglet | Points clés |
|---|---|
| Général | Validité par défaut, **code site** (utilisé dans `DEV-{SHOP}-{AAAA}-{n°}` et le dossier S3), format de référence, **prix affichés aux clients** (TTC d'abord, HT d'abord, ou selon le groupe du client) |
| Paiement | Module de paiement en ligne (HiPay Payments / PayPlug), moyens proposés par défaut, conditions de règlement, IBAN/BIC |
| PDF et e-mails | Couleur, mentions légales, CGV (texte ou page CMS), expéditeur (boutique ou commercial), e-mails actifs |
| Stockage des PDF | Object Storage Scaleway : endpoint `https://s3.fr-par.scw.cloud`, région `fr-par`, bucket, clés. Bouton **Tester la connexion** |
| Droits | Seuil de remise soumis à validation, profils autorisés à valider / convertir / voir les marges |

Les droits lire / créer / modifier / supprimer se règlent comme d'habitude dans **Paramètres avancés > Équipe > Permissions**,
sur l'onglet « Devis ».

### Stockage des PDF (Scaleway)

Chaque PDF envoyé est archivé tel quel dans `{bucket}/{code site}/Devis-{référence}-v{version}.pdf`, avec son empreinte
SHA-256 en base. Un renvoi de la même version ajoute un horodatage au nom. Le bucket reste privé : les téléchargements
passent par des URL présignées de 10 minutes. Sans bucket configuré (DEV), les PDF vont dans `var/modules/snt_quotes/`.

Droits nécessaires pour la clé API : lecture et écriture d'objets sur le bucket.

### Conversion en commande (jalon 2)

La conversion ne passe jamais par le tunnel :
1. un **panier technique** est construit avec les lignes du devis au prix figé (prix imposés liés au panier et au client,
   compensant remise de groupe et écotaxe), les remises regroupées dans une règle panier HT dédiée (code `SNTQ-…`) et le
   transporteur **« Livraison selon devis »**, qui renvoie le port HT du devis ;
2. les totaux calculés par PrestaShop sont comparés au devis **au centime** : en cas d'écart, aucune commande n'est créée et
   le message indique les montants en cause ;
3. la commande est validée par le module de paiement choisi ;
4. avant l'écriture du premier statut, le module remet le **transporteur réel** sur la commande, nomme les lignes libres,
   transmet le point relais au module du transporteur (Chronopost et Colissimo officiels, GLS `nkmgls` : adresse de
   livraison de la commande remplacée par celle du relais ; `snt_carriers` sinon), décompte les bons d'origine et lie le devis à la commande.

Objets créés à l'installation / mise à jour 0.2.0 : le transporteur « Livraison selon devis » (masqué du tunnel), des produits
techniques « Ligne libre de devis » (un par règle de taxe, invisibles, exclus de la recherche) et trois statuts de commande :
« Devis — en attente de paiement », « Paiement différé B2B », « Erreur conversion devis ».

| Mode (modale « Convertir en commande ») | Module qui crée la commande | Statut initial |
|---|---|---|
| Virement | `snt_quotes` | configurable (défaut : en attente de virement) |
| Paiement différé B2B | `snt_quotes` | configurable |
| En attente de paiement | `snt_quotes` | configurable |
| Carte par téléphone | `hipaypayments` (bloc MO/TO de la fiche commande) | configurable |
| Lien PayPlug | `payplug` (envoie le lien) | statut « lien de paiement par e-mail » de PayPlug |
| Carte en ligne (page de paiement client) | `hipaypayments`, à la notification | selon HiPay |

### Paiement en ligne et liens de paiement

Rien à saisir dans `snt_quotes` : les identifiants restent ceux du module de paiement de la boutique (configuration,
onglet Paiement, « Module de paiement en ligne »).
- **Lien de paiement** (fiche devis) : jeton aléatoire dont seule l'empreinte est stockée, expiration paramétrable, un seul
  lien actif par devis, consommé dès qu'une commande est créée. Le lien n'est affiché qu'à sa création.
- **Page client** (`module/snt_quotes/pay`) : sans connexion, récapitulatif figé, case CGV obligatoire, moyens cochés sur le devis.
- **HiPay Payments** (`hipaypayments` v4 sur PS 8, v5 sur PS 9) : page hébergée sur le panier technique ; la notification
  arrive au module HiPay, qui crée la commande.
- **PayPlug** (defense-privee) : commande au nom de PayPlug avec son statut « lien de paiement par e-mail » ; PayPlug envoie le lien.

### Espace client « Mes devis » (jalon 3)

- Lien **« Mes devis »** dans « Mon compte » (avec le nombre de devis qui attendent une réponse), URL `/mes-devis` et
  `/mes-devis/{id}`. Connexion requise ; un client ne voit que ses devis de la boutique, jamais les brouillons, les devis en
  validation interne ni les versions remplacées (il est redirigé vers la version en cours).
- Détail : récapitulatif identique au PDF, téléchargement du PDF envoyé, **Accepter et payer** (crée un lien de paiement et
  ouvre la page de paiement : CGV, moyen de paiement), **Demander une modification**, **Refuser** (motif facultatif),
  **messages** avec l'équipe.
- Fiche devis BO : bloc **« Échanges avec le client »** ; la réponse part par e-mail avec un lien vers le devis.
- Les e-mails d'envoi et de relance contiennent un bouton **« Voir mon devis en ligne »**.
- Gabarits surchargeables : `themes/<thème>/modules/snt_quotes/views/templates/front/{quotes,quote,_quote-summary,pay}.tpl`.

### Tâche planifiée (v0.3.2)

URL dans Configuration > Général (« URL de la tâche planifiée »), **une par boutique** (jeton propre). À appeler toutes les
heures depuis la crontab du serveur, par exemple :

```
0 * * * * curl -fsS "https://boutique.example/module/snt_quotes/cron?token=…" > /dev/null
```

À chaque passage : devis envoyés / vus dont la validité est dépassée → « Expiré » et liens de paiement désactivés ;
relance automatique (une par envoi) N jours avant l'expiration (réglage « Relance automatique », 0 = aucune) ;
liens encore actifs des devis refusés, expirés ou remplacés désactivés. 100 devis maximum par étape et par passage ;
la date du dernier passage s'affiche dans la configuration. Réponse JSON (`expired`, `reminded`, `links_revoked`, `errors`).

### Points d'attention

- **orderfees_shipping / Advanced Shipping Cost** : aucun réglage à faire. Depuis la 0.2.1, pour un panier de devis, le module
  impose le port du devis via le hook `actionCartGetPackageShippingCost` : les règles qui désactivent un transporteur ou
  ajoutent des frais ne s'appliquent pas à « Livraison selon devis ». Ce hook doit passer **après** celui de
  orderfees_shipping ; le module le replace en dernière position à chaque conversion. Si la conversion affiche
  « transporteur non disponible », le journal (`[snt_quotes] Technical carrier …`) détaille la cause.
  Ce hook n'existe pas dans le cœur : il est déclenché par la surcharge `Cart` d'orderfees_shipping. Sans ce module, le
  transporteur technique fournit le port lui-même (`getOrderShippingCost`).
- **Seuils de port gratuit** (`PS_SHIPPING_FREE_PRICE`, `PS_SHIPPING_FREE_WEIGHT`) : le cœur ne taxerait pas le port du devis ;
  ils sont neutralisés en mémoire, uniquement pendant le traitement d'un panier de devis (conversion, page de paiement,
  notification HiPay). Rien n'est modifié en base.
- **HiPay en mode « notification par cron »** : la neutralisation ci-dessus ne s'applique pas à ce traitement différé. Si la
  boutique a un seuil de port gratuit, gardez les notifications HiPay en temps réel.
- **`PS_ATCP_SHIPWRAP`** (TVA du port au prorata des produits) : non pris en charge ; le contrôle au centime le détecte.
- Deux lignes du **même produit à des prix différents** ne peuvent pas aller dans une même commande : la conversion le signale.

Détails et décisions : [docs/PLAN.md §9-10](docs/PLAN.md).

## Intégrations existantes

- **snt_carriers** : les transporteurs relais déclarés dans `config/carriers.php` sont reconnus. Le commercial choisit le point
  relais dans l'éditeur, via le même fournisseur de recherche (`RelayProviderFactory`).
- **orderfees_shipping / Advanced Shipping Cost** : le bouton « Proposer le tarif calculé » applique leurs règles sur un panier
  temporaire. Le port saisi dans le devis reste celui appliqué, y compris à la conversion (voir Points d'attention).
- **snt_inscription_pro** : le n° de TVA intracom de l'adresse déclenche l'autoliquidation, avec la même règle que le cœur
  (`VATNUMBER_MANAGEMENT`, pays ≠ `VATNUMBER_COUNTRY`).

## Surcharges par le thème

- PDF : `themes/<thème>/modules/snt_quotes/views/templates/pdf/{quote,quote-header,quote-footer,quote-pagination}.tpl`.
  Le thème enfant passe avant le thème parent (ex. ZOneChild puis ZOne).
- E-mails : `themes/<thème>/modules/snt_quotes/mails/{fr,en}/snt_quote_*.html|.txt`, ou International > Traductions > E-mails.

## Développement

```bash
composer install
composer test                                       # PHPUnit (calculs, statuts, références, signature S3)
PS_ROOT_DIR=/chemin/vers/prestashop composer stan   # PHPStan niveau 6, tout le module
composer cs                                         # php-cs-fixer (PSR-12)
php tools/twig-lint.php /chemin/vers/prestashop .   # syntaxe des gabarits Twig
php tools/smarty-lint.php /chemin/vers/prestashop . # compilation des gabarits Smarty
```

Pour analyser l'intégration HiPay, pointer PHPStan vers les sources du module : `HIPAY_DIR=/chemin/hipaypayments`.

Sans `PS_ROOT_DIR`, PHPStan n'analyse que les couches pures (`src/Domain`, `src/Calculator`).
Traductions : chaînes sources en anglais, catalogue français dans `translations/fr-FR/` (voir `tools/i18n/README.md`).

Arborescence : `classes/` (ObjectModel + gabarit PDF), `src/` (services, contrôleurs Symfony, Grid), `config/` (services, routes),
`views/` (Twig BO, Smarty PDF et hooks, JS sans build, CSS), `mails/`, `translations/`, `tests/`.
