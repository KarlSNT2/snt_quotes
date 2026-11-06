<?php

declare(strict_types=1);

namespace Snt\Quotes\Domain;

enum LineType: string
{
    /** Produit du catalogue (simple, déclinaison, pack, virtuel). */
    case Product = 'product';
    /** Ligne libre : prestation, frais… (produit technique à la conversion). */
    case Free = 'free';
    /** Titre de section, sans montant. */
    case Section = 'section';
    /** Commentaire, sans montant. */
    case Comment = 'comment';

    public function hasAmount(): bool
    {
        return $this === self::Product || $this === self::Free;
    }
}
