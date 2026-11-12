# Guide utilisateur — Devis (équipes commerciales et ADV)

> Version jalon 2 : création, envoi, suivi, conversion en commande et paiement par lien.
> Jalon 3 (en cours) : espace client « Mes devis » et échanges avec le client.
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
- Transporteur en point relais (Chronopost, Colissimo, GLS Relais) : un bloc « Point relais » s'ouvre sous la liste. Saisissez le code postal, cherchez, puis choisissez le point relais. À la conversion, la commande est livrée à l'adresse du relais au nom du client ; les étiquettes restent générées par l'ERP.
- **Frais de port HT** : saisissez le montant, ou cliquez sur **Proposer le tarif calculé** pour reprendre le tarif habituel de la boutique.
- **Port offert** ou **remise sur le port** au besoin.
- **Retrait en magasin** : choisissez le transporteur correspondant dans la liste (il n'y a pas de mode « Retrait » séparé).
- **Livraison spéciale** : saisissez le libellé qui sera affiché au client.

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
| Accepté | Convertir en commande, Lien de paiement |
| Modification demandée, Refusé, Expiré | Rouvrir en brouillon, Nouvelle version |
| Commandé / Payé | Voir la commande |

Depuis la **fiche client**, le bloc « Devis » liste les 5 derniers devis et propose **Nouveau devis pour ce client**.

## 5. Transformer un devis en commande

Sur la fiche d'un devis **accepté**, cliquez sur **Convertir en commande**. Si le client a donné son accord par téléphone ou
par e-mail sur un devis encore « Envoyé », utilisez **Convertir en commande (accord du client)** et cochez la case d'accord.

1. La fenêtre résume ce qui va se passer : « Une commande de 1 245,60 € TTC sera créée pour Société X, livrée par … ».
   Les articles en stock insuffisant sont signalés (sans bloquer).
2. Choisissez **comment la commande sera réglée** :
   - **Virement**, **Paiement différé (B2B)** ou **En attente de paiement** : la commande est créée tout de suite ;
   - **Carte par téléphone** : la commande est créée, puis vous saisissez la carte depuis le bloc HiPay de la fiche commande ;
   - **Lien PayPlug** (defense-privee) : PayPlug envoie lui-même le lien de paiement au client.
3. Vérifiez le **statut initial** proposé, ajoutez un commentaire si besoin, puis cliquez sur **Créer la commande**.

La commande reprend **exactement** le devis : prix, remises, port et transporteur, même si ce transporteur ne livre pas
normalement cette zone. Si PrestaShop trouvait un écart, même d'un centime, aucune commande n'est créée et un message explique pourquoi.

## 6. Envoyer un lien de paiement

Bouton **Lien de paiement** (devis envoyé ou accepté). Le client ouvre le lien, voit le devis, coche les CGV et paie, **sans se
connecter**. La commande est créée automatiquement et vous recevez un e-mail « Commande créée depuis le devis ».

- Laissez cochée l'option **L'envoyer au client par e-mail**, ou copiez le lien pour l'envoyer vous-même.
- Par sécurité, le lien n'est affiché **qu'une fois**. Si vous le perdez, créez-en un nouveau : l'ancien est désactivé.
- Le bloc « Lien de paiement » de la fiche indique son état : actif, utilisé, désactivé ou expiré.

## 7. Ce que voit le client et les échanges

Le client retrouve ses devis dans **Mon compte > Mes devis** (le bouton « Voir mon devis en ligne » des e-mails y mène).
Il peut télécharger le PDF, **accepter et payer**, **demander une modification**, **refuser**, ou vous **écrire**.

- **Demande de modification** : vous recevez un e-mail, le devis passe en « Modification demandée » (onglet « À traiter »).
  Créez une nouvelle version, puis envoyez-la.
- **Refus** : vous recevez un e-mail avec le motif éventuel ; le lien de paiement est désactivé.
- **Message** : vous recevez un e-mail. Répondez depuis le bloc **« Échanges avec le client »** de la fiche devis : le client
  reçoit votre réponse par e-mail. Répondre directement à l'e-mail reçu écrit aussi au client, mais n'apparaît pas dans le fil.

Les brouillons et les devis en attente de validation interne ne sont jamais visibles du client.

### Demande de devis depuis le panier

Sous le bouton « Commander », le panier affiche **« Demander un devis »** (désactivable par boutique dans la configuration :
« Permettre aux clients de demander un devis depuis leur panier »). Le client connecté indique un nom de projet, un message, une pièce jointe
(plan, cahier des charges… 10 Mo max.) et ses adresses. Son panier n'est **pas modifié**.

- Un **brouillon** est créé avec les articles du panier au prix du client (source « panier »). Il apparaît dans l'onglet
  **« À traiter »** et vous recevez un e-mail (au commercial du devis, sinon à l'adresse de la boutique).
- Le message et la pièce jointe ouvrent le bloc « Échanges avec le client » de la fiche (pièce jointe téléchargeable).
- Complétez le brouillon (port, remises, conditions) puis **envoyez-le** : le client le reçoit comme n'importe quel devis.
- Côté client, la demande apparaît dans « Mes devis » avec le statut « Demande envoyée » (sans montant). Sa page montre
  les articles demandés (sans prix) et les échanges : le client peut vous écrire et joindre un fichier, vos réponses
  depuis la fiche y mènent. Une fois le brouillon envoyé, la même page devient le devis complet.
- Une seule demande par panier tant que le panier n'a pas changé ; 10 demandes maximum par client et par 24 h.

### Relances et expiration automatiques

Si la tâche planifiée est en place (voir le README) :
- **N jours avant la fin de validité** (Configuration > « Relance automatique »), le client reçoit une relance par e-mail
  avec le PDF, une seule fois par envoi ;
- **le lendemain de la date de validité**, un devis encore « Envoyé » ou « Vu » passe en **« Expiré »** et son lien de paiement
  est désactivé. Pour le relancer : « Rouvrir en brouillon » ou « Nouvelle version », ajustez la validité, renvoyez.

## 8. Couleurs des statuts

Brouillon (gris) · En attente de validation interne (orange foncé) · Envoyé (bleu) · Vu (bleu clair) · Modification demandée (orange) ·
Accepté (vert) · Refusé (rouge) · Expiré (gris barré) · Commandé (violet) · Payé (vert foncé) · Remplacé (gris clair barré).
