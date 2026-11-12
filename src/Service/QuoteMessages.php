<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Fil d'échanges client ↔ équipe d'un devis (espace « Mes devis » et fiche BO).
 */
class QuoteMessages
{
    public const MAX_LENGTH = 3000;

    /** Taille maximale d'une pièce jointe (octets). */
    public const MAX_ATTACHMENT_SIZE = 10485760;

    /** Extensions acceptées pour les pièces jointes. */
    public const ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'csv', 'txt', 'dwg', 'dxf', 'zip'];

    public function __construct(private readonly HistoryLogger $history)
    {
    }

    /**
     * @param array{0: string, 1: string}|null $attachment [fichier stocké, nom d'origine] (voir storeUpload)
     */
    public function add(\SntQuote $quote, string $authorType, int $authorId, string $text, ?array $attachment = null): \SntQuoteMessage
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            throw new QuoteException('Write your message before sending it.');
        }
        $message = new \SntQuoteMessage();
        $message->id_snt_quote = (int) $quote->id;
        $message->author_type = $authorType;
        $message->author_id = $authorId;
        $message->message = mb_substr($text, 0, self::MAX_LENGTH);
        if ($attachment) {
            $message->attachment = $attachment[0];
            $message->attachment_name = $attachment[1];
        }
        if (!$message->add()) {
            throw new \RuntimeException('Unable to save the message.');
        }
        $this->history->log($quote, \SntQuoteHistory::EVENT_NOTE, null, [
            'message' => (int) $message->id,
            'from' => $authorType,
        ], null, null, $authorType === 'customer' ? \SntQuoteHistory::ACTOR_CUSTOMER : null, $authorType === 'customer' ? $authorId : null);

        return $message;
    }

    /**
     * Messages du devis et de ses versions précédentes, du plus ancien au plus récent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thread(\SntQuote $quote): array
    {
        $root = (int) ($quote->id_root_quote ?: $quote->id);
        $rows = \Db::getInstance()->executeS(
            'SELECT m.*, e.`firstname` AS employee_firstname, e.`lastname` AS employee_lastname,
                    c.`firstname` AS customer_firstname, c.`lastname` AS customer_lastname
             FROM `' . _DB_PREFIX_ . 'snt_quote_message` m
             INNER JOIN `' . _DB_PREFIX_ . 'snt_quote` q ON q.`id_snt_quote` = m.`id_snt_quote`
             LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (m.`author_type` = \'employee\' AND e.`id_employee` = m.`author_id`)
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (m.`author_type` = \'customer\' AND c.`id_customer` = m.`author_id`)
             WHERE q.`id_snt_quote` = ' . $root . ' OR q.`id_root_quote` = ' . $root . '
             ORDER BY m.`date_add` ASC, m.`id_snt_quote_message` ASC'
        ) ?: [];
        foreach ($rows as &$row) {
            $row['author_name'] = $row['author_type'] === 'employee'
                ? trim($row['employee_firstname'] . ' ' . $row['employee_lastname'])
                : trim($row['customer_firstname'] . ' ' . $row['customer_lastname']);
            $row['date_display'] = \Tools::displayDate((string) $row['date_add'], true);
        }

        return $rows;
    }

    /**
     * Marque comme lus les messages écrits par l'autre partie.
     */
    public function markRead(\SntQuote $quote, string $readerType): void
    {
        $author = $readerType === 'employee' ? 'customer' : 'employee';
        \Db::getInstance()->update(
            'snt_quote_message',
            ['read_at' => date('Y-m-d H:i:s')],
            '`id_snt_quote` = ' . (int) $quote->id . ' AND `author_type` = \'' . $author . '\' AND `read_at` IS NULL'
        );
    }

    public function unreadForEmployee(\SntQuote $quote): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'snt_quote_message`
             WHERE `id_snt_quote` = ' . (int) $quote->id . ' AND `author_type` = \'customer\' AND `read_at` IS NULL'
        );
    }

    /**
     * Enregistre un fichier envoyé (entrée de $_FILES) hors du dossier du module, sous un nom aléatoire.
     * Retourne null si aucun fichier n'a été choisi.
     *
     * @param array<string, mixed>|null $file
     *
     * @return array{0: string, 1: string}|null [fichier stocké, nom d'origine]
     */
    public function storeUpload(?array $file): ?array
    {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string) ($file['name'] ?? '') === '') {
            return null;
        }
        $error = (int) ($file['error'] ?? 0);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_ATTACHMENT_SIZE) {
            throw new QuoteException('The file is too large (10 MB maximum).');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new QuoteException('The file could not be uploaded. Please try again.');
        }
        $name = $this->cleanFileName((string) $file['name']);
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ATTACHMENT_EXTENSIONS, true)) {
            throw new QuoteException('This file type is not accepted. Accepted: %types%.', ['%types%' => implode(', ', self::ATTACHMENT_EXTENSIONS)]);
        }
        $dir = self::attachmentDir();
        $stored = bin2hex(random_bytes(20));
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . $stored)) {
            throw new QuoteException('The file could not be uploaded. Please try again.');
        }

        return [$stored, $name];
    }

    /**
     * Message du fil de ce devis (toutes versions) portant une pièce jointe.
     */
    public function findAttachment(\SntQuote $quote, int $idMessage): ?\SntQuoteMessage
    {
        $message = new \SntQuoteMessage($idMessage);
        if (!\Validate::isLoadedObject($message) || !$message->attachment) {
            return null;
        }
        $root = (int) ($quote->id_root_quote ?: $quote->id);
        $owner = new \SntQuote((int) $message->id_snt_quote);
        if (!\Validate::isLoadedObject($owner) || (int) ($owner->id_root_quote ?: $owner->id) !== $root) {
            return null;
        }
        $path = self::attachmentDir() . basename((string) $message->attachment);

        return is_file($path) ? $message : null;
    }

    public static function attachmentPath(\SntQuoteMessage $message): string
    {
        return self::attachmentDir() . basename((string) $message->attachment);
    }

    /**
     * Envoie le fichier au navigateur (téléchargement) puis termine la requête.
     */
    public static function streamAttachment(\SntQuoteMessage $message): never
    {
        $path = self::attachmentPath($message);
        $name = str_replace(['"', '\\', "\r", "\n"], '', (string) $message->attachment_name) ?: 'attachment';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Dossier des pièces jointes : upload/snt_quotes/ de PrestaShop, protégé contre l'accès direct
     * (noms aléatoires sans extension, .htaccess et index.php).
     */
    public static function attachmentDir(): string
    {
        $dir = rtrim(_PS_UPLOAD_DIR_, '/\\') . '/snt_quotes/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . 'index.php')) {
            @file_put_contents($dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        return $dir;
    }

    private function cleanFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'file';
        $name = trim($name, ' .');

        return mb_substr($name !== '' ? $name : 'file', -120);
    }
}
