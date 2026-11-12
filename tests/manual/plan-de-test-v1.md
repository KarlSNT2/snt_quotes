# Plan de test V1 — snt_quotes 0.3.2

Plan de recette par scénarios, à dérouler sur DEV après purge des devis (`tools/sql/purge-devis-dev.sql`).
Chaque scénario donne les étapes et le résultat attendu ; noter le résultat dans la colonne **OK / KO** et, en cas de KO,
la référence du devis, une capture et le message d'erreur (Paramètres avancés > Journaux, filtre `snt_quotes`).

Règle d'or pour les montants : **le total TTC de la commande créée = le total TTC du devis, au centime**
(produits, remises, port, taxes). Le module bloque la conversion s'il y a un écart ; un blocage est donc un KO à remonter.

Durée estimée : une demi-journée pour A à H, une heure pour I à L.

---

## 0. Préparation

### 0.1 Module
| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| 0.1.1 | Importer `Modules\snt_quotes.zip`, vider le cache | Module en 0.3.2, actif, menu Commandes > Devis présent | |
| 0.1.2 | Configuration > Général : code site, validité 30 j, relance auto 7 j, délai de réponse 2 j, prix affichés « TTC d'abord » | Enregistré ; **réimporter le zip** puis vérifier que les réglages n'ont pas bougé | |
| 0.1.3 | Configuration > Paiement : passerelle HiPay (ou PayPlug selon la boutique), moyens par défaut carte + virement, IBAN / BIC, durée des liens 7 j | Enregistré | |
| 0.1.4 | Configuration > Commandes : statuts « virement », « différé », « en attente » choisis | Enregistré | |
| 0.1.5 | Configuration > Stockage : bucket S3, « Tester la connexion » | Connexion OK (ou stockage local en DEV, signalé) | |
| 0.1.6 | Configuration > Droits : seuil de remise 10 %, profils « valider », « convertir », « voir la marge » | Enregistré | |
| 0.1.7 | Appeler l'URL de la tâche planifiée dans le navigateur | JSON `"ok": true` ; « Dernier passage » affiché dans la configuration | |

### 0.2 Données de test
| Code | Donnée | Détail |
|---|---|---|
| **C1** | Client particulier France | groupe Client (prix TTC), adresse Lyon, mobile renseigné |
| **C2** | Client pro France | groupe B2B affichant les prix HT, société + SIRET, 2 adresses (livraison ≠ facturation) |
| **C3** | Client pro UE | adresse Belgique (ou Allemagne) avec n° de TVA intracom valide |
| **C4** | Client hors UE | adresse Suisse |
| **P1** | Produit simple | prix 100,00 € HT, TVA 20 %, stock 50 |
| **P2** | Produit à déclinaisons | 2 déclinaisons avec impact de prix différent |
| **P3** | Produit avec écotaxe | écotaxe renseignée |
| **P4** | Produit avec prix spécifique | -10 % pour le groupe B2B |
| **P5** | Produit en rupture | stock 0, commande hors stock refusée |
| **T1..T4** | Transporteurs | un transporteur classique, Chronopost Relais, Colissimo Relais, GLS Relais |

Employés : **E1** commercial (sans droit de valider ni de convertir), **E2** responsable (tous les droits).

---

## A. Création et contenu (BO)

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| A1 | E2 : Commandes > Devis > Nouveau, client C1, P1 × 3, transporteur T1 | Récapitulatif mis à jour à chaque saisie ; référence attribuée au 1er enregistrement (DEV-CODE-AAAA-00001) | |
| A2 | Ajouter P2 (choisir une déclinaison), P3, une **ligne libre** (« Pose », 250 € HT, TVA 20 %), une **section** et un **commentaire** | Lignes dans l'ordre ; écotaxe de P3 comprise ; ligne libre au bon taux | |
| A3 | Glisser-déposer pour réordonner, supprimer une ligne, modifier une quantité | Totaux recalculés | |
| A4 | Fermer l'onglet sans enregistrer puis rouvrir le devis | Brouillon auto-enregistré (≤ 30 s) | |
| A5 | Client C2 : ajouter P4 | Prix catalogue = prix B2B (-10 %) | |
| A6 | Reprendre un panier en cours de C1 (bloc « paniers du client ») | Articles importés au prix du client ; panier d'origine inchangé | |
| A7 | Ajouter P5 (rupture) | Avertissement de stock, devis enregistrable | |

## B. Calculs, remises et TVA

Calcul de référence (B1) — vérifiable à la main :

| Élément | HT | TVA 20 % | TTC |
|---|---|---|---|
| Ligne libre 100,00 € HT × 3 | 300,00 | | |
| Remise globale 15 % | -45,00 | | |
| Port 12,50 € HT | 12,50 | | |
| **Total** | **267,50** | **53,50** | **321,00** |

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| B1 | Client C1, uniquement la ligne libre ci-dessus, remise globale 15 %, port 12,50 € HT | Totaux exactement comme le tableau | |
| B2 | Remise par ligne (montant puis pourcentage) sur P1 | Prix barré + prix net ; total cohérent | |
| B3 | Bon de réduction existant de la boutique | Appliqué au devis ; consommé à la conversion | |
| B4 | Remise globale **12 %** (seuil 10 %) avec E1 → Envoyer | Devis « En attente de validation » ; pas d'e-mail client | |
| B5 | E2 valide le devis de B4 puis l'envoie | Envoyé ; historique : validation par E2 | |
| B6 | Port offert | Port à 0 ; total recalculé | |
| B7 | Même contenu que B1 pour **C3** (UE + n° TVA) | TVA à 0 si la boutique exonère l'intracom ; mention sur le PDF | |
| B8 | Même contenu pour **C4** (Suisse) | TVA à 0 (règles de taxe de la boutique) | |
| B9 | E1 sans droit « voir la marge » | Colonne marge absente ; E2 la voit | |

## C. PDF et envoi

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| C1 | Télécharger le PDF d'un brouillon | En-tête boutique, couleur, lignes / sections / commentaires, taxes par taux, validité, CGV, mentions légales | |
| C2 | Envoyer (modale) au client + une adresse en copie, message personnalisé | E-mail reçu avec PDF joint et bouton « Voir mon devis en ligne » ; statut « Envoyé » ; PDF archivé (S3) | |
| C3 | Expéditeur « commercial » dans la configuration | Nom du commercial affiché, réponse = son e-mail | |
| C4 | Envoyer un devis sans commercial (créé depuis le panier) | Le contact du devis = l'employé qui envoie | |
| C5 | « Relancer » depuis la fiche | E-mail de relance avec le PDF archivé | |
| C6 | Désactiver un e-mail dans la configuration, refaire l'action | Aucun e-mail | |

## D. Espace client « Mes devis »

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| D1 | C1 ouvre le lien de l'e-mail sans être connecté | Connexion puis retour sur le devis | |
| D2 | Ouverture | Statut « Vu » dans le BO ; récapitulatif identique au PDF | |
| D3 | Mon compte > Mes devis | Lien avec pastille du nombre de devis en attente ; devis en attente en tête | |
| D4 | Changer l'id dans l'URL vers le devis d'un autre client | Retour à la liste | |
| D5 | Brouillon / en validation / remplacé | Jamais visibles (sauf demandes du panier, cf. E) | |
| D6 | « Demander une modification » avec texte | « Modification demandée » ; e-mail au commercial ; onglet « À traiter » | |
| D7 | E2 crée une nouvelle version et l'envoie | Le client voit la v2 ; l'ancienne URL redirige vers la v2 ; l'ancien lien de paiement est désactivé | |
| D8 | « Refuser » avec motif | « Refusé » ; e-mail au commercial ; lien de paiement désactivé | |
| D9 | Message client + pièce jointe PDF ; réponse de E2 depuis la fiche | Fil des deux côtés, pièce jointe téléchargeable BO et FO, e-mails reçus | |
| D10 | Client C2 (groupe HT), réglage « selon le groupe » | Prix HT d'abord pour C2, TTC d'abord pour C1 | |
| D11 | Mobile (navigateur en largeur téléphone) | Cartes lisibles, boutons accessibles | |

## E. Demande de devis depuis le panier

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| E1 | Visiteur non connecté : panier P1 + P2 → « Demander un devis » | Connexion puis page `/demande-de-devis` avec les articles | |
| E2 | Projet, message, pièce jointe (PDF), adresses → Envoyer | Confirmation « Votre demande DEV-… a bien été envoyée », délai 2 jours | |
| E3 | Pièce jointe `.exe` ; fichier > 10 Mo | Refusés avec message clair | |
| E4 | Renvoyer une demande sur le même panier | « Déjà demandé » ; après modification du panier : nouvelle demande possible | |
| E5 | Panier client | Inchangé | |
| E6 | BO | Brouillon dans « À traiter », prix du client, message + pièce jointe dans les échanges, e-mail au commercial (ou à la boutique) | |
| E7 | « Mes devis » | « Demande envoyée », sans montant ; « Voir ma demande » → articles sans prix + échanges | |
| E8 | E2 répond depuis la fiche ; le client répond | E-mail au client avec lien vers la demande ; réponse visible dans le BO | |
| E9 | E2 complète et envoie | La même page devient le devis complet | |
| E10 | Désactiver la demande depuis le panier (configuration) | Bouton absent du panier | |

## F. Conversion en commande (BO)

Pour chaque ligne : convertir un devis **accepté** (ou envoyé) depuis la modale « Convertir en commande », puis ouvrir la
commande créée. Vérifier à chaque fois : **total au centime**, transporteur réel (pas « Livraison selon devis »),
adresses, lignes libres nommées, remise « SNTQ-… », statut attendu, devis « Commandé » et lié à la commande.

| # | Contenu du devis | Mode | Attendu | OK / KO |
|---|---|---|---|---|
| F1 | B1 (ligne libre, remise 15 %, port) | Virement | Statut « virement » ; IBAN dans l'e-mail de commande | |
| F2 | P1 + P3 (écotaxe) + P4, client C2 | Paiement différé (B2B) | Statut « différé » ; écotaxe et prix B2B corrects | |
| F3 | P2 déclinaison + bon de réduction | En attente de paiement | Bon consommé | |
| F4 | Port offert, transporteur T1 | Virement | Port 0 ; pas de frais ajoutés par orderfees / Advanced Shipping Cost | |
| F5 | Panier > seuil de livraison gratuite de la boutique, port facturé sur le devis | Virement | Le port du devis est conservé | |
| F6 | Devis déjà converti | Convertir à nouveau | Refusé (« déjà commandé ») | |
| F7 | E1 (sans droit de convertir) | — | Bouton absent / refusé | |
| F8 | P5 (rupture) | Virement | Comportement conforme au réglage de stock de la boutique | |

## G. Paiement en ligne

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| G1 | Fiche devis > « Envoyer un lien de paiement » | E-mail reçu ; lien valable 7 jours | |
| G2 | Ouvrir le lien **sans être connecté** | Page de paiement : récapitulatif, CGV obligatoires, moyens cochés sur le devis | |
| G3 | **HiPay** carte de test 3-D Secure acceptée | Retour « Merci » ; commande créée à la notification HiPay ; statut payé ; devis « Payé » | |
| G4 | HiPay carte refusée, puis annulation | Message d'échec ; aucune commande ; nouvelle tentative possible | |
| G5 | Double paiement (rouvrir le lien après paiement) | Page « déjà payé » ; aucun 2e débit | |
| G6 | HiPay **par téléphone** (MO/TO) depuis la conversion BO | Saisie carte côté BO ; commande payée | |
| G7 | **PayPlug** (boutique concernée) | Commande au nom de PayPlug ; lien PayPlug reçu par e-mail ; paiement → statut payé | |
| G8 | Virement depuis la page de paiement | Commande « virement » ; coordonnées bancaires affichées | |
| G9 | Lien après nouvelle version / refus / expiration | Page « remplacé » / « indisponible » / « expiré » | |

## H. Relais et ERP

| # | Transporteur | Attendu | OK / KO |
|---|---|---|---|
| H1 | Chronopost Relais : recherche par code postal, choix d'un point | Commande livrée à l'adresse du relais, au nom du client, n° du relais dans « Autre » ; fiche Chronopost du BO cohérente | |
| H2 | Colissimo Relais (code postal **et ville**) | Idem | |
| H3 | GLS Relais | Idem ; bloc GLS de la fiche commande affiche le relais | |
| H4 | Transporteur GLS domicile | Pas de bloc relais | |
| H5 | Une commande de H1/H2/H3 payée par carte HiPay (G3) | **Arrivée dans l'ERP avec l'adresse du relais** | |

## I. Tâche planifiée

Pour forcer les dates : modifier `valid_until` du devis en base (`ps_snt_quote`), puis appeler l'URL de la tâche.

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| I1 | Devis envoyé, `valid_until` = hier | « Expiré » (historique : système) ; lien de paiement désactivé ; JSON `expired: 1` | |
| I2 | Devis envoyé, `valid_until` = dans 5 jours (relance à 7 j) | E-mail de relance avec PDF ; `reminded: 1` | |
| I3 | Rappeler l'URL | Pas de 2e relance | |
| I4 | Renvoyer ce devis au client, rappeler l'URL | Nouvelle relance possible | |
| I5 | Relance automatique à 0 | Aucune relance | |
| I6 | Devis refusé avec lien encore actif | `links_revoked: 1` | |
| I7 | Brouillon / demande du panier / devis commandé avec date passée | Jamais modifiés | |
| I8 | URL avec mauvais jeton | Erreur 403 | |

## J. Droits et cycle de vie BO

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| J1 | Liste : onglets « À traiter », « En attente client », « Tous », filtres, tri, actions groupées « Expirer » | Compteurs justes | |
| J2 | Dupliquer un devis | Nouveau brouillon, nouvelle référence | |
| J3 | Nouvelle version d'un devis envoyé | Même référence v2 ; v1 « Remplacé » | |
| J4 | Rouvrir en brouillon un devis refusé / expiré | Brouillon modifiable | |
| J5 | Supprimer un brouillon | Disparu de la liste | |
| J6 | Fiche client BO / fiche commande BO | Blocs « Devis » avec liens | |
| J7 | Employé sans accès à l'onglet Devis | Menu et URL refusés | |

## K. Coexistence Opart

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| K1 | Opart actif : créer et payer un devis Opart | Fonctionne comme avant | |
| K2 | Commande classique du site (tunnel) | « Livraison selon devis » jamais proposé ; moyens de paiement « devis » jamais affichés | |
| K3 | Frais orderfees / Advanced Shipping Cost sur une commande classique | Inchangés | |

## L. Non-régression technique

| # | Étape | Attendu | OK / KO |
|---|---|---|---|
| L1 | Réimporter le même zip (mise à jour) | Pas d'erreur ; configuration et devis conservés | |
| L2 | Journaux PrestaShop après toute la recette | Aucune erreur `snt_quotes` non expliquée | |
| L3 | Analyse statique complète (PHPStan) côté dev | À lancer par Claude avant la mise en production | |

---

## Hors V1 (évolutions futures)
- Recette sur PrestaShop 9.2 (le code gère PS 8 et 9, aucun essai encore sur une boutique 9.2).
- Statistiques des devis.
- Créer un devis à partir d'une commande existante (la reprise d'un panier client existe déjà).
- Script de reprise des devis Opart (facultatif, coexistence prévue).
