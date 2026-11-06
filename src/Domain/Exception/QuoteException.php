<?php

declare(strict_types=1);

namespace Snt\Quotes\Domain\Exception;

/**
 * Erreur métier dont le message est destiné à l'utilisateur (déjà traduit ou traduisible).
 */
class QuoteException extends \RuntimeException
{
    /**
     * @param array<string, string|int|float> $parameters paramètres de traduction
     */
    public function __construct(string $message, private readonly array $parameters = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, string|int|float>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
