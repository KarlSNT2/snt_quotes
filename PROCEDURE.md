# Devis — procédure opérateurs

Aide-mémoire pour le back office (Commandes > Devis). Le guide complet est dans [docs/guide-utilisateur.md](docs/guide-utilisateur.md).

## La règle à retenir

**Un devis = une seule commande.** Pour la créer, il y a deux chemins, à ne jamais cumuler sur le même devis :

1. **Lien de paiement** : le client agit seul, la commande se crée toute seule.
2. **Convertir en commande** : l'opérateur crée la commande à la place du client.

## Quel chemin choisir ?

| Situation | Ce que je fais |
|---|---|
| Le client doit payer en ligne, seul | J'envoie le lien de paiement et **j'attends** |
| Le client a donné son accord par téléphone, e-mail ou bon pour accord signé | Je **convertis en commande** avec le mode convenu |
| Lien envoyé, mais le client veut finalement payer par virement | Il le choisit sur la page du lien si « virement » est coché sur le devis ; sinon je convertis en « Virement » |
| Lien expiré, ou paiement carte abandonné | Je renvoie un lien, ou je convertis |

## Chemin 1 — Lien de paiement

1. Le devis doit être **envoyé** au client (statut Envoyé, Vu ou Accepté).
2. Fiche devis > **Lien de paiement** > envoyer par e-mail (ou copier le lien).
3. **Ne pas convertir le devis ensuite** : le lien deviendrait inutilisable.

Ce que fait le client sur la page du lien, et le résultat :

| Choix du client | Commande créée | Statut de la commande |
|---|---|---|
| Carte bancaire | À la confirmation du paiement | Payée |
| Virement | Dès qu'il valide | En attente de virement |
| Paiement sur facture (B2B) | Dès qu'il valide | Paiement différé |

À savoir :

- Le lien **n'est affiché qu'une fois**, à sa création. S'il est perdu, en créer un nouveau.
- Créer un nouveau lien **désactive le précédent**.
- Le lien **expire** (date indiquée sur la fiche devis et dans l'e-mail).
- Le client ne voit que les moyens de paiement **cochés sur le devis**.

## Chemin 2 — Convertir en commande

Fiche devis > **Convertir en commande**, puis choisir le mode :

| Mode | Quand l'utiliser | Ensuite |
|---|---|---|
| Virement | Le client annonce un virement | À réception des fonds, passer la commande en « Paiement accepté » |
| Paiement différé B2B | Client professionnel, paiement sur facture | Rien : la commande est validée |
| Carte par téléphone | Le client dicte sa carte | Fiche commande > onglet « HiPay Payments » > « Accéder à la page de paiement », puis saisir la carte. Ne jamais noter le numéro ailleurs |
| En attente de paiement | Le moyen de paiement reste à définir | Encaisser puis passer la commande en « Paiement accepté » |

À savoir :

- Convertir **ne constate pas un paiement** : cela crée la commande qui attend ce paiement (sauf paiement différé).
- Si le devis n'est pas encore accepté, cocher **« Le client a donné son accord »**.
- Vérifier les **avertissements de stock** affichés avant de valider.

## Ce qui se passe automatiquement

- La commande reprend **les prix, les remises, le port et le transporteur du devis**, tels quels.
- Le devis passe en **Commandé**, puis en **Payé** quand la commande passe à un statut payé.
- La commande affiche un encart **« Issue du devis … »**, et le devis un lien vers la commande.
- Le commercial reçoit un e-mail quand le client crée la commande par le lien.

## Côté client : « Mes devis »

Le client retrouve ses devis dans **Mon compte > Mes devis**. Il peut :

| Action du client | Ce qui se passe | Ce que je fais |
|---|---|---|
| Accepter et payer | Un nouveau lien de paiement est créé (l'ancien est désactivé) et le client paie | Rien : la commande se crée seule |
| Demander une modification | Devis en « Modification demandée », e-mail reçu | Nouvelle version, puis envoi |
| Refuser | Devis en « Refusé », e-mail reçu avec le motif | Relancer le client si besoin |
| Écrire un message | E-mail reçu | Répondre depuis le bloc « Échanges avec le client » de la fiche devis |

## Points de vigilance

- **Devis « Accepté » sans commande** : souvent un paiement carte abandonné. Le devis passe en Accepté dès que le client valide la page, avant de payer. Relancer le client ou renvoyer un lien.
- **Commande au statut « Erreur conversion devis »** : ne pas l'expédier. Soit le devis a été payé deux fois (rembourser ou annuler la seconde commande), soit les totaux diffèrent du devis. Prévenir le responsable.
- **Message « La commande ne correspondrait pas au devis au centime »** : aucune commande n'est créée. Transmettre le message au responsable technique.
- **Message « Le transporteur Livraison selon devis n'est pas disponible »** : aucune commande n'est créée. Transmettre au responsable technique (le détail est dans Paramètres avancés > Journaux).
- **Modifier un devis déjà envoyé** : créer une nouvelle version. L'ancienne passe en « Remplacé » et son lien de paiement ne fonctionne plus.
- **Même produit à deux prix différents** sur un devis : la conversion le refuse. Fusionner les lignes dans une nouvelle version.
- **Ne jamais désinstaller le module** : cela supprime tous les devis.
