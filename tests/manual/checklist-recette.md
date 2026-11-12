# Checklist de recette

À dérouler sur **PS 8.2.6 / PHP 8.1** et sur **PS 9.2 / PHP 8.4** (pilote defense-privee), en DEV puis en STAGING.
Cocher `[x]`, noter l'écart sinon.

## Jalon 1 — BO interne

> **Recette DEV du 2026-11-09 (Karl)** : parcours nominal validé — création d'un devis, envoi de l'e-mail avec le PDF en pièce jointe, passage manuel en « Accepté ». Correctif appliqué pendant la recette : injection du présentateur de Grid (commit affbc62).

### Installation
- [x] Installation sans erreur ; onglet **Commandes > Devis** présent ; tables `ps_snt_quote*` créées
- [ ] Configuration par défaut posée pour **chaque** boutique (code site deviné depuis le nom)
- [ ] Mise à jour du module (même version) sans perte de données
- [ ] Coexistence avec Opart Devis : panier et tunnel inchangés

### Liste
- [ ] Onglet « À traiter » par défaut s'il n'est pas vide, sinon « Tous »
- [ ] Filtres : référence, client (société / nom / e-mail), commercial, statut, montant (`>1000`, `500-1000`), dates
- [ ] Tri par chaque colonne sans erreur SQL
- [ ] Validité en rouge quand il reste moins de 7 jours (devis ouvert)
- [ ] Actions de ligne : Voir, Dupliquer, Télécharger le PDF
- [ ] Actions groupées : Relancer, Expirer (avec confirmation)
- [ ] Multiboutique : une boutique ne voit que ses devis

### Éditeur — scénarios fiscaux
- [ ] **FR TTC** : client particulier FR, 2 produits à 20 %, 1 produit à 5,5 %, port 9,90 € HT → TVA par taux correcte, total TTC = total PS attendu
- [ ] **B2B UE autoliquidé** : adresse BE avec n° TVA intracom (`VATNUMBER_MANAGEMENT` actif) → TVA 0, mention d'autoliquidation sur le PDF
- [ ] **Hors UE** (CH) : TVA selon les règles de taxe de la boutique (0 % si configuré)
- [ ] Changement d'adresse FR → BE : TVA recalculée ; retour BE → FR : TVA réappliquée
- [ ] Les 3 modes d'arrondi (`PS_ROUND_TYPE` par article / ligne / total) donnent les totaux du cœur

### Éditeur — contenu
- [ ] Recherche produit par nom, référence, EAN (produit et déclinaison)
- [ ] Produit à déclinaisons : sélecteur obligatoire, prix mis à jour
- [ ] Prix dégressif : passer la quantité au palier → prix catalogue mis à jour (si le prix n'a pas été modifié à la main)
- [ ] **Ligne libre** avec TVA au choix ; titre ; commentaire ; réordonner (↑ ↓ et glisser-déposer) ; dupliquer ; supprimer
- [ ] Ajout en masse : collage « réf;qté » et import CSV, avec erreurs ligne par ligne
- [ ] Reprise d'un panier client (le panier d'origine n'est pas modifié)
- [ ] Création rapide client et adresse
- [ ] Remise de ligne % et €, prix barré (BO, PDF) ; alerte sous prix d'achat (profil autorisé uniquement)
- [ ] **Bon de réduction** existant : valide accepté ; expiré / épuisé / réservé à un autre client refusé avec message clair
- [ ] Bon dédié (montant, %, date de fin, minimum) enregistré
- [ ] Remise globale % et € ; port offert ; remise sur le port
- [ ] **Transporteur hors règles** (zone non desservie) : sélectionnable, bandeau orange, port saisi conservé
- [ ] « Proposer le tarif calculé » = tarif `orderfees_shipping` / ASC du transporteur
- [ ] Transporteur relais `snt_carriers` : recherche par code postal, choix du point, affiché sur la fiche et le PDF
- [ ] Autosave toutes les 30 s ; avertissement en quittant une page modifiée
- [ ] Totaux du récapitulatif = totaux enregistrés (les montants envoyés par le navigateur sont ignorés)

### Remises et droits
- [ ] Remise > 15 % par un profil non autorisé → « En attente de validation interne », pas d'envoi
- [ ] Le même devis envoyé par un profil autorisé → envoyé
- [ ] Prix d'achat / marge invisibles pour un profil sans le droit (BO et API JSON)
- [ ] Permissions natives : un profil en lecture seule ne peut ni créer, ni modifier, ni envoyer

### PDF
- [ ] Logo, couleur, mentions légales, numéro et version, dates d'émission et de validité, commercial
- [ ] Sections, commentaires, remises barrées, port avec nom du transporteur, TVA par taux, totaux, zone « Bon pour accord »
- [ ] Devis de 60 lignes : en-tête de tableau répété, aucune ligne coupée, pagination « Page x / y »
- [ ] CGV en dernières pages (texte et page CMS)
- [ ] Nom de fichier `Devis-{référence}-v{version}.pdf`
- [ ] Surcharge du gabarit par le thème enfant ZOne

### Envoi
- [ ] Modale : destinataires modifiables, copie cachée, objet, message, aperçu, PDF joint, pièces jointes
- [ ] Expéditeur « commercial » : réponse au commercial ; copie au commercial
- [ ] PDF archivé sur Scaleway `bucket/{code}/Devis-…-v1.pdf`, empreinte SHA-256 contrôlée au téléchargement
- [ ] Renvoi de la même version : nouvelle archive horodatée, la première est conservée
- [ ] Nouvelle version (v2) envoyée → v1 passe « Remplacé »
- [ ] Historique : création, modifications, e-mails, PDF, changements de statut avec acteur et IP
- [ ] E-mails FR et EN selon la langue du client ; e-mail désactivé dans la configuration → aucun envoi

### Intégrations BO
- [ ] Fiche client : bloc « Devis » + « Nouveau devis pour ce client » (client présélectionné)
- [ ] Fiche commande : encart « Issue du devis » (vérifiable à partir du jalon 2)

## Jalon 2 — Conversion et paiement

### Mise à jour 0.1.0 → 0.2.0
- [ ] Mise à jour sans erreur ; transporteur « Livraison selon devis » créé (actif, toutes zones) ; 3 statuts de commande créés
- [ ] Le transporteur technique **n'apparaît pas** dans le tunnel d'un panier client normal
- [ ] `orderfees_shipping` / ASC : transporteur technique exclu de leurs règles

### Conversion (BO, modale « Convertir en commande »)
- [ ] Résumé correct (montant TTC/HT, client, transporteur) ; stock insuffisant signalé sans bloquer
- [ ] **FR TTC** → commande : totaux HT, TVA, TTC, port **identiques au devis au centime**
- [ ] **B2B UE autoliquidé** → commande à TVA 0, identique au devis
- [ ] **Transporteur hors règles** (zone non desservie) → commande créée, `orders.id_carrier` et `order_carrier` = transporteur réel
- [ ] Transporteur **relais** `snt_carriers` → adresse relais matérialisée sur la commande
- [ ] **Ligne libre** → ligne de commande avec le libellé et la référence du devis ; deux lignes libres de prix différents OK
- [ ] **Bon de réduction** existant → remise identique sur la commande ; quantité du bon décrémentée
- [ ] Remise globale %, remise de ligne, port offert, remise sur le port → reportés à l'identique
- [ ] Client d'un **groupe avec remise** (ex. -10 %) → prix de la commande = prix du devis (pas de double remise)
- [ ] Boutique avec **seuil de port gratuit** dépassé → port du devis appliqué et taxé
- [ ] Modes virement, paiement différé, en attente → bon statut initial ; mode téléphone → bloc MO/TO HiPay visible
- [ ] Lien PayPlug (defense-privee) → commande au nom de PayPlug, e-mail PayPlug reçu
- [ ] E-mail de confirmation de commande : nom du **transporteur réel** (pas « Livraison selon devis »)
- [ ] Message privé « Commande issue du devis … » sur la commande ; encart « Issue du devis » sur la fiche commande
- [ ] Devis → « Commandé », puis « Payé » quand la commande passe à un statut payé
- [ ] Double conversion impossible (deuxième clic, deux onglets) ; prix imposés et règle `SNTQ-…` nettoyés après commande
- [ ] Même produit sur deux lignes à prix différents → message clair, pas de commande
- [ ] Écart de totaux simulé (règle de frais active sur le transporteur technique) → aucune commande, message explicite

### Lien et page de paiement (sans connexion)
- [ ] Lien créé et envoyé (e-mail FR/EN) ; copie possible une seule fois ; nouveau lien → l'ancien est désactivé
- [ ] Page client : récapitulatif identique au PDF, case CGV obligatoire, moyens = ceux cochés sur le devis
- [ ] Ouverture du lien → devis « Vu » ; navigation privée (non connecté) OK ; mobile OK
- [ ] **HiPay** : paiement accepté → commande créée par HiPay, devis « Commandé » puis « Payé », lien « Utilisé »
- [ ] HiPay : paiement refusé / annulé → message, retour à la page, nouvel essai possible
- [ ] Virement depuis la page → commande créée, IBAN affiché
- [ ] Lien expiré, désactivé, devis remplacé par une nouvelle version, devis déjà payé → écran dédié
- [ ] E-mail « Commande créée depuis le devis » reçu par le commercial
- [ ] HiPay Payments v4 (PS 8.2) **et** v5 (PS 9.2)

## 0.3.1 — Demande de devis depuis le panier

- [ ] Mise à jour 0.3.0 → 0.3.1 sans erreur, vider le cache
- [ ] Panier (Classic / ZOne) : bouton « Demander un devis » sous « Commander », même largeur ; absent si panier vide ou option désactivée
- [ ] Non connecté → connexion → retour sur `/demande-de-devis` avec le panier
- [ ] Formulaire : articles du panier, adresses préremplies, envoi sans message ni pièce jointe accepté
- [ ] Pièce jointe : PDF/image acceptés ; `.exe` refusé ; > 10 Mo refusé
- [ ] Confirmation « Votre demande DEV-… a bien été envoyée », délai de réponse, lien « Mes devis »
- [ ] Panier inchangé après la demande ; 2ᵉ envoi sur le même panier → « déjà demandé »
- [ ] BO : brouillon dans « À traiter », source panier, prix = prix du client ; message + pièce jointe dans les échanges (téléchargement OK)
- [ ] E-mail au commercial (ou à la boutique) avec le message
- [ ] « Mes devis » : ligne « Demande envoyée », sans montant, « Voir ma demande » → articles sans prix + échanges
- [ ] Réponse de l'équipe sur le brouillon → e-mail au client, le lien ouvre la demande ; le client répond (avec pièce jointe)
- [ ] Après envoi du devis par l'équipe → même page = devis complet (payer, PDF…)

## Jalon 3 — Espace client « Mes devis » (v0.3.0)

### Mise à jour 0.2.1 → 0.3.0
- [ ] Mise à jour sans erreur ; onglet « Webhooks n8n » disparu de la configuration ; réglage « Prix affichés aux clients » présent
- [ ] Vider le cache, puis vérifier que `/mes-devis` répond (URL simplifiées actives)

### Liste et accès
- [ ] « Mon compte » : lien « Mes devis » avec le nombre de devis en attente de réponse
- [ ] Non connecté → redirection vers la connexion, puis retour
- [ ] Liste : devis envoyés, vus, acceptés, refusés, expirés, commandés ; **jamais** brouillons, en validation interne, remplacés
- [ ] Devis en attente de réponse en tête (« Consulter et valider ») ; validité en rouge à moins de 7 jours
- [ ] Mobile : cartes ; ordinateur : tableau
- [ ] Un client ne peut pas ouvrir le devis d'un autre (changer l'id dans l'URL → retour à la liste)
- [ ] Ancienne version (remplacée) → redirection vers la version en cours

### Détail et actions
- [ ] Ouverture d'un devis « Envoyé » → « Vu » dans le BO
- [ ] Récapitulatif identique au PDF ; PDF téléchargé = celui envoyé
- [ ] **Accepter et payer** → page de paiement (CGV, moyens cochés) ; l'ancien lien envoyé par l'équipe est désactivé
- [ ] **Demander une modification** → « Modification demandée », e-mail au commercial, message dans le fil
- [ ] **Refuser** (avec et sans motif) → « Refusé », e-mail au commercial, lien de paiement désactivé
- [ ] **Message** → e-mail au commercial ; réponse depuis la fiche BO → e-mail au client avec lien, message visible côté client
- [ ] Devis commandé → bouton « Voir ma commande » ; devis expiré → message, pas de paiement
- [ ] Texte avec balises HTML dans un message → affiché tel quel, jamais interprété

### Affichage des prix
- [ ] « TTC d'abord » (défaut) : page de paiement et espace client inchangés par rapport à la 0.2.1
- [ ] « HT d'abord » : prix HT en gras, TTC en rappel, total HT mis en avant
- [ ] « Selon le groupe du client » : client d'un groupe « Prix HT » → HT d'abord ; client particulier → TTC d'abord

### E-mails
- [ ] Envoi et relance d'un devis : bouton « Voir mon devis en ligne » → page du devis (après connexion)
- [ ] Nouveaux e-mails activables / désactivables dans la configuration (demande client, réponse de l'équipe)

### Reste du jalon 3 *(à venir)*
- [x] Panier → demande de devis (livré en 0.3.1, voir section 0.3.1)
- [x] Tâche planifiée : livrée en 0.3.2, voir section 0.3.2

## 0.3.2 — Tâche planifiée et relais GLS

### Tâche planifiée
- [ ] Configuration > Général : URL de la tâche planifiée ; « jamais tourné » avant le premier appel
- [ ] Mauvais jeton → 403 ; bon jeton → JSON `ok: true` ; date du dernier passage affichée
- [ ] Devis envoyé avec validité passée (modifier `valid_until` en base) → « Expiré » (historique : système), lien de paiement désactivé
- [ ] Devis qui expire dans N jours (N = relance automatique) → e-mail de relance avec PDF, une seule fois ; renvoyé au client → nouvelle relance possible
- [ ] Relance automatique à 0 → aucune relance
- [ ] Devis refusé avec lien encore actif → lien désactivé au passage suivant
- [ ] Brouillons, demandes du panier, devis commandés : jamais touchés

### Relais GLS (`nkmgls`)
- [ ] Éditeur : transporteur « En Relais GLS » → bloc « Point relais », recherche par code postal, choix d'un relais
- [ ] Transporteurs GLS non relais (domicile, entreprise, express) : pas de bloc relais
- [ ] Conversion : adresse de livraison de la commande = relais GLS au nom du client (n° du relais dans « Autre »)
- [ ] Fiche commande BO : bloc GLS affiche le relais ; commande reçue par l'ERP avec l'adresse du relais
- [ ] Multiboutique, accessibilité clavier
