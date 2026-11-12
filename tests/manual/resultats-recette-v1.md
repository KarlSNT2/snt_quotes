# Résultats de recette V1 — DEV (TOM-Airgun), snt_quotes 0.3.3

Déroulée par Claude dans le navigateur intégré le 12/11/2026, sur `plan-de-test-v1.md`.
Client de test : Karl Duponchel (id 8299). Adresses de test ajoutées : Belgique (46275), Andorre (46276), Martinique (46277).
Légende : ✅ OK · ❌ KO · ⚠️ OK avec remarque · ⏭️ non testé (raison indiquée) · 👤 à faire par Karl.

## 0. Préparation

| # | Résultat | Détail |
|---|---|---|
| 0.1.1 | ✅ | Module 0.3.3 actif, menu Commandes > Devis |
| 0.1.2 | ✅ | Réglages conservés après l'import de la 0.3.3 (seuil de remise 30 %, différent du défaut 15 %) |
| 0.1.3 | ⚠️ | Passerelle HiPay, moyens par défaut carte + virement. **IBAN / BIC vides** : à renseigner (affichés au client pour le virement) |
| 0.1.4 | ✅ | Statuts virement / différé / en attente choisis |
| 0.1.5 | ⚠️ | S3 non configuré : PDF stockés en local (`var/modules/snt_quotes/`) — normal en DEV, à configurer en production |
| 0.1.6 | ⚠️ | Seuil de remise 30 % ; seuls les SuperAdmin peuvent valider, convertir et voir la marge |
| 0.1.7 | ✅ | Tâche planifiée déjà appelée (dernier passage affiché) |
| CGV | ⚠️ | « CGV sur le PDF » : aucune. À choisir (page CMS ou texte) avant la production |

## B. Calculs, remises et TVA

| # | Résultat | Détail |
|---|---|---|
| B1 | ✅ | 300,00 − 45,00 + 12,50 = **267,50 HT / 53,50 TVA / 321,00 TTC** |
| B2 | ✅ | Produit 449,90 TTC × 2 = 899,80. −10 % → 809,82 TTC ; −25 € HT/u → 839,80 TTC (= −30 € TTC/u) |
| B6 | ✅ | Port 20 € offert → port 0, total inchangé (899,80) |
| B7 | ⚠️ | Belgique **sans** n° de TVA : 20 % (règle de la boutique). Avec n° de TVA intracom : 👤 à tester par Karl (je ne saisis pas d'identifiant fiscal) |
| B8 | ✅ | Andorre et Martinique : TVA 0 % (267,50 HT = TTC), conforme aux règles de taxe de la boutique |

## A. Création et contenu (BO)

| # | Résultat | Détail |
|---|---|---|
| A1 | ✅ | Client choisi, référence attribuée au 1er enregistrement (DEV-TOMAIR-2026-00003) ; commercial par défaut = employé connecté |
| A2 | ✅ | Produit, ligne libre 250 € HT → 300 € TTC, titre, commentaire ; libellé obligatoire sur la ligne libre (message clair) |
| A3 | ✅ | Flèches haut / bas : ordre conservé à l'enregistrement |
| A4 | ✅ | Brouillon enregistré automatiquement (l'URL passe en /3/edit) |
| A8 | ✅ | « Ligne 1 : seulement 0 en stock (le devis peut quand même être envoyé) » |
| — | ❌→✅ | Bloc client : « TVA non facturée (autoliquidation) » affiché pour une société **française** tant que le devis est vide (total de TVA à 0). **Corrigé** : le libellé suit désormais l'exonération réelle calculée par le serveur |
| — | ✅ | « Proposer le tarif calculé » : 17,50 € HT (Chronopost Domicile 24h) ; transporteurs hors zone / hors groupe signalés |

## C. PDF et envoi

| # | Résultat | Détail |
|---|---|---|
| C1 | ✅ | PDF : en-tête, client, livraison, contact commercial, lignes, commentaire, titre, TVA par taux, totaux, bon pour accord. ⚠️ CGV et mentions légales vides (configuration) |
| C2 | ✅ | Envoi : statut « Envoyé », e-mail `snt_quote_sent` au client, PDF archivé, historique complet |
| C4 | ✅ | Demande du panier sans commercial : e-mail parti à l'adresse de la boutique |

## D. Espace client

| # | Résultat | Détail |
|---|---|---|
| D2 | ✅ | Ouverture → « Consulté » côté client, « Vu » côté BO ; récapitulatif = devis |
| D3 | ✅ | Devis en attente en tête (« Consulter et valider ») ; demande du panier « Demande envoyée » |
| D4 | ✅ | Devis d'un autre client (page et PDF) → retour à la liste ; pièce jointe inexistante → refusée |
| D6 | ✅ | « Modification demandée », message au commercial |
| D7 | ❌→✅ | v2 envoyée mais la v1 **refusée** restait visible (« Refusé ») et ne redirigeait pas vers la v2. **Corrigé** : un devis refusé / expiré passe en « Remplacé » quand sa nouvelle version est envoyée |
| D7 bis | ❌→✅ | « Modification demandée » : pas de bouton « Nouvelle version » (seulement Modifier). **Corrigé** : bouton ajouté |
| D8 | ✅ | « Refusé » avec motif |
| D9 | ✅ | Message client + pièce jointe PDF ; e-mail au commercial ; réponse de l'équipe ; e-mail au client ; téléchargement BO et FO |

## E. Demande depuis le panier

| # | Résultat | Détail |
|---|---|---|
| E1–E2 | ✅ | Demande DEV-TOMAIR-2026-00002 envoyée, confirmation avec délai |
| E3 | ⚠️→✅ | `.exe` refusé, saisie conservée. Message d'erreur affiché **deux fois** (module + thème). **Corrigé** (aussi sur les pages devis et paiement) |
| E4 | ✅ | Même panier → « Vous avez déjà demandé un devis pour ce panier » |
| E7 | ✅ | « Voir ma demande » |
| E-relais | ❌→✅ | Bloc point relais fermé sur une demande du panier (mode de livraison vide) — corrigé en 0.3.3, vérifié |

## F. Conversion

| # | Résultat | Détail |
|---|---|---|
| F1 | ⚠️→✅ | Devis 00004 → commande CVVZMHCZC : **703,41 € = devis** (produits 809,90, remise −121,49, port 15,00), transporteur réel Chronopost Domicile 24h, ligne libre nommée, remise « Remises du devis … ». Accord client obligatoire (case). **Anomalies corrigées** : (1) la ligne libre (produit technique sans stock) faisait passer la commande en « En attente de réapprovisionnement » avec une alerte de rupture ; (2) le commentaire de conversion et la note « Commande issue du devis » n'apparaissaient pas dans les messages de la commande (PrestaShop 8 n'affiche que les fils client) |

## G. Paiement en ligne

| # | Résultat | Détail |
|---|---|---|
| G1 | ✅ | Lien envoyé, valable 15 jours (réglage) |
| G2 | ✅ | Page sans connexion : récapitulatif 321,00 €, CGV obligatoires, moyens = cochés sur le devis (carte HiPay, virement, différé) |
| G3–G4, G6–G7 | 👤 | Paiements par carte (HiPay, PayPlug) : à faire par Karl (saisie de carte sur la page de la banque) |
| G5 | ✅ | Lien réutilisé → « Ce devis a déjà été commandé », aucun moyen proposé ; lien invalide → message clair |
| G8 | ✅ | Virement depuis la page → commande BILVJDHIF **321,00 € = devis**, devis « Commandé ». ⚠️ IBAN non affiché (non renseigné) ; même anomalie de stock que F1 (corrigée) |

## H. Relais et ERP

| # | Résultat | Détail |
|---|---|---|
| H1–H2 | ✅ (éditeur) | Bloc point relais ouvert pour Chronopost Point Relais (y compris sur une demande du panier) ; conversion relais Chronopost / Colissimo déjà validée par Karl au jalon 2 |
| H3 | ⚠️ | GLS Relais (nkmgls 3.1.2) : recherche OK (10 relais), devis 00011 → commande YFDBVHKLY **126,00 € = devis**, transporteur GLS Point Relais, **adresse de livraison = CARREFOUR CITY, 1170 route de Neufchâtel** au nom du client. Le bloc GLS de la fiche commande n'affiche pas le relais (« Information non disponible ») — sans effet sur l'ERP, qui lit l'adresse |
| H4 | ✅ | GLS Domicile : pas de bloc relais |
| H5 | 👤 | Arrivée dans l'ERP d'une commande relais payée par carte : à faire par Karl |

## I. Tâche planifiée

| # | Résultat | Détail |
|---|---|---|
| I1 | ⏭️ | Expiration : nécessite de modifier `valid_until` en base (voir SQL ci-dessous) ; l'envoi d'un devis à date dépassée est bien **bloqué** avec un message clair |
| I2 | ✅ | Devis valable jusqu'au 17/11 (relance à 7 j) → `reminded: 1`, e-mail `snt_quote_reminder` envoyé |
| I3 | ✅ | 2e appel → `reminded: 0` |
| I6 | ✅ | Lien de paiement d'un devis refusé dans le BO → `links_revoked: 1` ; la page affiche « lien plus valable ». **Amélioré** : « Marquer comme refusé / Expirer » dans le BO désactive désormais le lien immédiatement (comme le refus du client) |
| I8 | ✅ | Mauvais jeton → 403 |

SQL pour I1 (remplacer l'id) : `UPDATE ps_snt_quote SET valid_until = '2026-11-01' WHERE id_snt_quote = <id d'un devis envoyé>;` puis appeler l'URL de la tâche.

## J. Droits et cycle de vie BO

| # | Résultat | Détail |
|---|---|---|
| J1 | ✅ | « À traiter » = modification demandée + demande du panier ; « En attente client » = envoyés / vus ; « Tous » |
| J2 | ✅ | Dupliquer → nouveau brouillon DEV-TOMAIR-2026-00010 |
| J3 | ✅ | Nouvelle version d'un devis refusé → « 00007 v2 » (voir D7) |
| J5 | ✅ | Suppression d'un brouillon jamais envoyé |
| J6 | ✅ / ❌ | Bloc « Devis » sur la fiche client OK (client 22635) et sur la fiche commande (« Issue du devis … »). **La fiche du client 8299 renvoie 503** (plantage PHP). Ce n'est **pas** le bloc devis : l'erreur persiste avec le module décroché de ce hook. À investiguer avec les journaux PHP / Apache du serveur |
| J7 | 👤 | Employé sans droits : à tester par Karl avec un profil non SuperAdmin |

## K. Coexistence

| # | Résultat | Détail |
|---|---|---|
| K2 | ✅ | Tunnel normal : « Livraison selon devis » jamais proposé ; moyens de paiement = ceux de la boutique uniquement (HiPay, Alma, PayPal, virement) |
| K-panier | ⚠️→✅ | Bouton « Demander un devis » présent sous « Commander » mais pleine largeur sur ZOne (380 px contre 160 px). **Corrigé** : même gabarit que « Commander » (centré, largeur automatique) |
| Sécurité | ⚠️→✅ | La remise « SNTQ-… » d'un panier de devis restait utilisable sur une commande normale du client si le paiement par carte était abandonné. **Corrigé** : elle est limitée au transporteur technique |

## À faire par Karl

1. Installer la **0.3.4** et vider le cache (corrections ci-dessus ; le hook de la fiche client, décroché pour le diagnostic, est raccroché par la mise à jour).
2. Paiements par carte : G3, G4, G6 (HiPay MO/TO), G7 (PayPlug), puis H5 (ERP).
3. B7 avec un vrai n° de TVA intracom ; J7 avec un employé non SuperAdmin ; I1 avec le SQL ci-dessus.
4. Fiche client 8299 en 503 : regarder le journal d'erreurs PHP du serveur au moment de l'appel.
5. Configuration à compléter : IBAN / BIC, CGV et mentions légales du PDF, stockage S3.

Données de test laissées sur la DEV : devis 00002 à 00011, commandes CVVZMHCZC, BILVJDHIF, YFDBVHKLY (à annuler ou purger), 1 produit dans le panier du compte karl.d@snt2.fr.
