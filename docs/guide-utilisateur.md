# Guide utilisateur — Devis (équipes commerciales et ADV)

> Version jalon 1 : création, envoi et suivi des devis dans le back office.
> La conversion en commande et le paiement en ligne arrivent au jalon 2.
> *[Captures à compléter]* marque l'emplacement des captures d'écran.

## 1. Retrouver ses devis

Menu **Commandes > Devis**.

- **À traiter** (onglet par défaut) : devis avec une modification demandée, en attente de validation interne, ou acceptés mais pas encore commandés.
- **En attente client** : devis envoyés ou vus par le client, sans réponse pour l'instant.
- **Tous** : tous les devis de la boutique.

Une date de validité **en rouge** signifie qu'il reste moins de 7 jours. Les filtres au-dessus de la liste permettent de chercher
par référence, client (société, nom, e-mail), commercial, statut, dates ou montant (`>1000`, `500-1000`).

Pour agir sur plusieurs devis à la fois (relancer, expirer) : cocher les lignes, puis **Actions groupées**.

*[Capture à compléter : la liste avec les onglets]*

## 2. Créer un devis

Bouton **Nouveau devis**, en haut à droite. La page se lit de haut en bas, en 5 étapes. Le récapitulatif à droite se met à jour tout seul.

### Étape 1 — Client
- Tapez un nom, un e-mail, une société ou un SIRET, puis choisissez dans la liste.
- Client inconnu ? **Créer un client**. Il recevra ses accès via « Mot de passe oublié ».
- Choisissez les adresses de livraison et de facturation, ou **Nouvelle adresse**.
- Si le client a des paniers en cours, cliquez sur l'un d'eux pour **reprendre ses articles**.
- Avec un n° de TVA intracommunautaire sur l'adresse (client UE hors France), la TVA passe automatiquement à 0 (autoliquidation).

### Étape 2 — Produits
- Tapez un nom, une référence ou un EAN : la ligne est ajoutée **directement**.
- Modifiez la quantité, le **prix HT** et la **remise** (en % ou en €) dans la ligne. Le prix catalogue du client reste affiché en gris.
- Pour un produit à déclinaisons, choisissez la taille, la couleur… dans la liste qui apparaît sous le nom.
- **Ajouter une ligne libre** : prestation, installation, frais de dossier… avec son taux de TVA.
- **Ajouter un titre** ou **un commentaire** pour structurer un devis long.
- **Ajout en masse** : collez des lignes « référence ; quantité » ou importez un CSV.
- Les flèches ↑ ↓ (ou la poignée ⋮⋮) changent l'ordre des lignes.

Si votre profil le permet, vous voyez aussi le prix d'achat et la marge. Une ligne en rouge est vendue **sous le prix d'achat**.

### Étape 3 — Livraison
- **Tous** les transporteurs sont proposés. Un bandeau orange signale un transporteur qui ne livre pas normalement cette zone ou ce poids : vous pouvez quand même le choisir, et le port du devis sera appliqué tel quel.
- Transporteur en point relais : saisissez le code postal, cherchez, puis choisissez le point relais.
- **Frais de port HT** : saisissez le montant, ou cliquez sur **Proposer le tarif calculé** pour reprendre le tarif habituel de la boutique.
- **Port offert** ou **remise sur le port** au besoin.
- **Retrait** : pas de port. **Livraison spéciale** : saisissez le libellé qui sera affiché au client.

### Étape 4 — Remises
- **Remise globale** sur les produits, en % ou en €.
- **Bon existant** : recherchez son code. Un bon expiré ou épuisé est refusé.
- **Bon dédié** : un bon réservé à ce client, créé au moment de la commande.

Au-delà du seuil de remise fixé par la direction (15 % par défaut), le devis devra être **validé par un responsable** avant l'envoi.

### Étape 5 — Conditions
- **Date de validité** : 30 jours par défaut.
- **Moyens de paiement** autorisés pour ce devis.
- **Message au client** : imprimé sur le PDF.
- **Note interne** : jamais visible par le client.

Le brouillon est **enregistré automatiquement toutes les 30 secondes**. Vous pouvez aussi cliquer sur **Enregistrer le brouillon**.

*[Capture à compléter : l'éditeur]*

## 3. Envoyer le devis

Bouton **Envoyer au client** (dans l'éditeur ou sur la fiche du devis).

1. Vérifiez le destinataire, l'objet et le message. L'aperçu de l'e-mail se met à jour à droite.
2. **Vérifier le PDF** ouvre le document qui sera joint.
3. Ajoutez au besoin des pièces jointes (fiche technique, CGV…).
4. Cliquez sur **Envoyer**.

Le PDF envoyé est **archivé tel quel** et reste téléchargeable depuis la fiche (bloc « PDF envoyés »). Une fois envoyé,
le devis n'est plus modifiable : pour le changer, créez une **nouvelle version**, qui remplacera la précédente à son envoi.

## 4. Suivre un devis (fiche)

La fiche affiche le montant, la validité, les lignes, les adresses et l'**historique** (qui a fait quoi, et quand).
La barre d'actions propose seulement ce qui est possible pour ce statut :

| Statut | Actions principales |
|---|---|
| Brouillon | Envoyer au client, Modifier |
| Envoyé / Vu | Relancer, Renvoyer, Marquer comme accepté / refusé, Expirer, Nouvelle version |
| Accepté | Convertir en commande *(jalon 2)* |
| Modification demandée, Refusé, Expiré | Rouvrir en brouillon, Nouvelle version |
| Commandé / Payé | Voir la commande |

Depuis la **fiche client**, le bloc « Devis » liste les 5 derniers devis et propose **Nouveau devis pour ce client**.

## 5. Couleurs des statuts

Brouillon (gris) · En attente de validation interne (orange foncé) · Envoyé (bleu) · Vu (bleu clair) · Modification demandée (orange) ·
Accepté (vert) · Refusé (rouge) · Expiré (gris barré) · Commandé (violet) · Payé (vert foncé) · Remplacé (gris clair barré).
