"""Catalogue français — 0.3.1 : demande de devis depuis le panier, pièces jointes."""

FR_ADMIN_J31 = {
 "The file could not be uploaded. Please try again.": "Le fichier n’a pas pu être envoyé. Réessayez.",
 "The file is too large (10 MB maximum).": "Le fichier est trop volumineux (10 Mo maximum).",
 "This file is no longer available.": "Ce fichier n’est plus disponible.",
 "This file type is not accepted. Accepted: %types%.": "Ce type de fichier n’est pas accepté. Formats acceptés : %types%.",
}

FR_EMAILS_J31 = {
 "New quote request from the cart: complete the draft and send it to the customer.": "Nouvelle demande de devis depuis le panier : complétez le brouillon puis envoyez-le au client.",
}

# Messages d'erreur (QuoteException) affichés au client dans le domaine Shop : non détectés par l'extracteur.
FR_SHOP_EXCEPTIONS_J31 = {
 "The file could not be uploaded. Please try again.": "Le fichier n’a pas pu être envoyé. Réessayez.",
 "The file is too large (10 MB maximum).": "Le fichier est trop volumineux (10 Mo maximum).",
 "This file type is not accepted. Accepted: %types%.": "Ce type de fichier n’est pas accepté. Formats acceptés : %types%.",
 "This cart is empty.": "Ce panier est vide.",
 "This cart has already been ordered.": "Ce panier a déjà été commandé.",
 "This cart does not belong to the selected customer.": "Ce panier ne vous appartient pas.",
}

FR_SHOP_J31 = {
 "Add a new address": "Ajouter une adresse",
 "Add an address": "Ajouter une adresse",
 "Answer within %days% working days.": "Réponse sous %days% jours ouvrés.",
 "Answer within 1 working day.": "Réponse sous 1 jour ouvré.",
 "Attachment": "Pièce jointe",
 "Back to my cart": "Retour au panier",
 "Being prepared": "En préparation",
 "Delivery address": "Adresse de livraison",
 "Edit my cart": "Modifier mon panier",
 "Invoice address": "Adresse de facturation",
 "Items in your request": "Articles de votre demande",
 "Large order, special delivery? Our team prepares a quote for you. Your cart is kept.": "Grosse quantité, livraison particulière ? Notre équipe vous prépare un devis. Votre panier est conservé.",
 "Our team is preparing it. If you change your cart, you can send a new request.": "Notre équipe le prépare. Si vous modifiez votre panier, vous pourrez envoyer une nouvelle demande.",
 "Our team will send you a quote within %days% working days.": "Notre équipe vous envoie un devis sous %days% jours ouvrés.",
 "Our team will send you a quote within 1 working day.": "Notre équipe vous envoie un devis sous 1 jour ouvré.",
 "Plan, specifications, list… %size% MB maximum.": "Plan, cahier des charges, liste… %size% Mo maximum.",
 "Project name": "Nom du projet",
 "Quantities to confirm, delivery date, site access, questions…": "Quantités à confirmer, date de livraison, accès au chantier, questions…",
 "Quote request sent from my cart.": "Demande de devis envoyée depuis mon panier.",
 "Ref.": "Réf.",
 "Request a quote": "Demander un devis",
 "Request sent": "Demande envoyée",
 "Tell us about your project: our team checks availability, delivery and the best price, then sends you a quote. Your cart is kept as it is.": "Présentez-nous votre projet : notre équipe vérifie la disponibilité, la livraison et le meilleur prix, puis vous envoie un devis. Votre panier reste tel quel.",
 "This file is no longer available.": "Ce fichier n’est plus disponible.",
 "You have already requested a quote for this cart (%ref%).": "Vous avez déjà demandé un devis pour ce panier (%ref%).",
 "You have no quotes yet. Your sales representative will send them to you here. You can also request a quote from your cart.": "Vous n’avez pas encore de devis. Votre commercial vous les enverra ici. Vous pouvez aussi demander un devis depuis votre panier.",
 "You have no saved address yet: our team will ask you for the delivery address.": "Vous n’avez pas encore d’adresse enregistrée : notre équipe vous demandera l’adresse de livraison.",
 "You have sent many requests today. Please contact us directly.": "Vous avez envoyé beaucoup de demandes aujourd’hui. Merci de nous contacter directement.",
 "You will receive it by e-mail and in your « My quotes » space.": "Vous le recevrez par e-mail et dans votre espace « Mes devis ».",
 "Your request %ref% has been sent": "Votre demande %ref% a bien été envoyée",
 "Your request could not be sent. Please try again or contact us.": "Votre demande n’a pas pu être envoyée. Réessayez ou contactez-nous.",
 "e.g. Renovation of the Lyon site": "ex. : Rénovation du site de Lyon",
 "optional": "facultatif",
}
FR_SHOP_J31.update(FR_SHOP_EXCEPTIONS_J31)
