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

## Jalon 2 — Conversion et paiement *(à compléter)*
- [ ] Transporteur hors règles → commande créée, `order_carrier` = transporteur réel
- [ ] Bon de réduction et ligne libre reportés à l'identique ; totaux au centime
- [ ] HiPay Payments v4 (PS 8) et v5 (PS 9) ; PayPlug (defense-privee)
- [ ] `orderfees_shipping` n'altère pas le port du panier technique

## Jalon 3 — Front office *(à compléter)*
- [ ] Multiboutique, mobile, accessibilité clavier
