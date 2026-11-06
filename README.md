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
| 2 — Conversion et paiement | `QuoteToOrderConverter`, transporteur technique, paiement intégré, liens HiPay Payments / PayPlug | à faire |
| 3 — Front office et cycle de vie | Panier → devis, Mes devis, acceptation, page de paiement, cron, webhooks n8n | à faire |
| 4 — Options | Versions avancées, statistiques, bouton fiche produit, reprise Opart | à faire |

## Installation

1. Copier le dossier `snt_quotes` dans `modules/` (ou importer le zip depuis le gestionnaire de modules).
2. Dépendances : aucune bibliothèque tierce à l'exécution. `composer install --no-dev -o` génère un autoloader optimisé ;
   sans `vendor/`, le module utilise son propre autoloader (PSR-4 + classes).
3. Installer le module. Il crée :
   - les tables `snt_quote*` (devis, lignes, remises, historique, messages, liens, archives PDF, compteurs, journal des webhooks) ;
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
| Général | Validité par défaut, **code site** (utilisé dans `DEV-{SHOP}-{AAAA}-{n°}` et le dossier S3), format de référence |
| Paiement | Module de paiement en ligne (HiPay Payments / PayPlug), moyens proposés par défaut, conditions de règlement, IBAN/BIC |
| PDF et e-mails | Couleur, mentions légales, CGV (texte ou page CMS), expéditeur (boutique ou commercial), e-mails actifs |
| Stockage des PDF | Object Storage Scaleway : endpoint `https://s3.fr-par.scw.cloud`, région `fr-par`, bucket, clés. Bouton **Tester la connexion** |
| Droits | Seuil de remise soumis à validation, profils autorisés à valider / convertir / voir les marges |
| Webhooks n8n | URL + secret HMAC (envois actifs à partir du jalon 3) |

Les droits lire / créer / modifier / supprimer se règlent comme d'habitude dans **Paramètres avancés > Équipe > Permissions**,
sur l'onglet « Devis ».

### Stockage des PDF (Scaleway)

Chaque PDF envoyé est archivé tel quel dans `{bucket}/{code site}/Devis-{référence}-v{version}.pdf`, avec son empreinte
SHA-256 en base. Un renvoi de la même version ajoute un horodatage au nom. Le bucket reste privé : les téléchargements
passent par des URL présignées de 10 minutes. Sans bucket configuré (DEV), les PDF vont dans `var/modules/snt_quotes/`.

Droits nécessaires pour la clé API : lecture et écriture d'objets sur le bucket.

### Paiement (jalon 2)

Rien à saisir dans `snt_quotes` : les identifiants restent ceux du module de paiement de la boutique.
- **HiPay Payments** (`hipaypayments` v4 sur PS 8, v5 sur PS 9) : la page de paiement est créée sur le panier technique du devis ;
  à la notification HiPay, c'est le module HiPay qui crée la commande.
- **PayPlug** (defense-privee) : la commande est créée au nom de PayPlug avec son statut « lien de paiement par e-mail » ; PayPlug envoie le lien.

Détails dans [docs/PLAN.md §9](docs/PLAN.md).

## Intégrations existantes

- **snt_carriers** : les transporteurs relais déclarés dans `config/carriers.php` sont reconnus. Le commercial choisit le point
  relais dans l'éditeur, via le même fournisseur de recherche (`RelayProviderFactory`).
- **orderfees_shipping / Advanced Shipping Cost** : le bouton « Proposer le tarif calculé » applique leurs règles sur un panier
  temporaire. Le port saisi dans le devis reste celui appliqué. **À faire avant le jalon 2** : exclure le transporteur technique
  « Livraison selon devis » de leurs règles.
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
```

Sans `PS_ROOT_DIR`, PHPStan n'analyse que les couches pures (`src/Domain`, `src/Calculator`).
Traductions : chaînes sources en anglais, catalogue français dans `translations/fr-FR/` (voir `tools/i18n/README.md`).

Arborescence : `classes/` (ObjectModel + gabarit PDF), `src/` (services, contrôleurs Symfony, Grid), `config/` (services, routes),
`views/` (Twig BO, Smarty PDF et hooks, JS sans build, CSS), `mails/`, `translations/`, `tests/`.
