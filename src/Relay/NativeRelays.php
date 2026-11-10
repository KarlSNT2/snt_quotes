<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

/**
 * Modules transporteurs officiels dont le devis sait gérer les points relais.
 * Pour en ajouter un (GLS…) : une classe avec isRelayCarrier(), search() et applyToOrder(), déclarée ici.
 */
final class NativeRelays
{
    /**
     * @return array<string, ChronopostRelay|ColissimoRelay> fournisseur => gestionnaire
     */
    public static function all(): array
    {
        static $providers = null;

        return $providers ??= [
            ChronopostRelay::PROVIDER => new ChronopostRelay(),
            ColissimoRelay::PROVIDER => new ColissimoRelay(),
        ];
    }

    /**
     * Fournisseur du transporteur s'il s'agit d'un transporteur relais d'un module officiel, sinon null.
     */
    public static function providerForCarrier(int $idCarrier): ?string
    {
        foreach (self::all() as $provider => $handler) {
            if ($handler->isRelayCarrier($idCarrier)) {
                return $provider;
            }
        }

        return null;
    }

    public static function get(string $provider): ChronopostRelay|ColissimoRelay|null
    {
        return self::all()[$provider] ?? null;
    }
}
