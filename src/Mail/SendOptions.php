<?php

declare(strict_types=1);

namespace Snt\Quotes\Mail;

/**
 * Options de la modale « Envoyer au client ».
 */
final class SendOptions
{
    /**
     * @param string[]                                                 $to
     * @param string[]                                                 $cc
     * @param array<int, array{content: string, name: string, mime: string}> $attachments pièces jointes supplémentaires
     */
    public function __construct(
        public readonly array $to,
        public readonly array $cc = [],
        public readonly ?string $subject = null,
        public readonly ?string $message = null,
        public readonly array $attachments = [],
        public readonly bool $copyToEmployee = true,
    ) {
    }

    /**
     * Adresses valides et dédoublonnées.
     *
     * @param mixed $raw liste ou chaîne séparée par virgules / points-virgules
     *
     * @return string[]
     */
    public static function parseEmails(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[,;\s]+/', (string) $raw);
        $out = [];
        foreach ($list ?: [] as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = $email;
            }
        }

        return array_values($out);
    }
}
