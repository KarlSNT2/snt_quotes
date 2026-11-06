<?php

declare(strict_types=1);

namespace Snt\Quotes\Storage;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;

/**
 * Archive des PDF : Object Storage Scaleway (bucket privé, URL présignées), clé « {site}/{fichier} ».
 * Si le bucket n'est pas configuré (DEV), repli sur var/modules/snt_quotes/ (hors webroot).
 */
class S3Storage
{
    public const STORAGE_S3 = 's3';
    public const STORAGE_LOCAL = 'local';

    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function isConfigured(?int $idShop = null): bool
    {
        return $this->config->get(ConfigKeys::S3_BUCKET, $idShop) !== ''
            && $this->config->get(ConfigKeys::S3_KEY, $idShop) !== ''
            && $this->config->get(ConfigKeys::S3_SECRET, $idShop) !== '';
    }

    /**
     * @return array{storage: string, path: string}
     */
    public function put(string $key, string $content, string $contentType, int $idShop): array
    {
        if (!$this->isConfigured($idShop)) {
            $file = $this->localDir() . str_replace(['..', '\\'], ['', '/'], $key);
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0750, true) && !is_dir(dirname($file))) {
                throw new \RuntimeException('Cannot create the local archive directory.');
            }
            if (file_put_contents($file, $content) === false) {
                throw new \RuntimeException('Cannot write the PDF archive.');
            }

            return ['storage' => self::STORAGE_LOCAL, 'path' => $key];
        }

        [$scheme, $host] = $this->hostFor($idShop);
        $path = '/' . ltrim($key, '/');
        $headers = $this->signer($idShop)->signHeaders('PUT', $host, $path, hash('sha256', $content), [
            'content-type' => $contentType,
            'x-amz-acl' => 'private',
        ]);
        [$code, $body] = $this->request('PUT', $scheme . '://' . $host . $this->encodePath($path), $headers, $content);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf('Object storage refused the upload (HTTP %d): %s', $code, mb_substr($body, 0, 300)));
        }

        return ['storage' => self::STORAGE_S3, 'path' => $key];
    }

    public function get(string $storage, string $key, int $idShop): string
    {
        if ($storage === self::STORAGE_LOCAL) {
            $content = @file_get_contents($this->localDir() . $key);
            if ($content === false) {
                throw new \RuntimeException('Archived PDF not found.');
            }

            return $content;
        }
        [$scheme, $host] = $this->hostFor($idShop);
        $path = '/' . ltrim($key, '/');
        $headers = $this->signer($idShop)->signHeaders('GET', $host, $path, hash('sha256', ''));
        [$code, $body] = $this->request('GET', $scheme . '://' . $host . $this->encodePath($path), $headers);
        if ($code !== 200) {
            throw new \RuntimeException(sprintf('Archived PDF not available (HTTP %d).', $code));
        }

        return $body;
    }

    /**
     * Lien de téléchargement temporaire (S3 uniquement ; null pour le stockage local).
     */
    public function temporaryUrl(string $storage, string $key, int $idShop, string $downloadName, int $ttl = 600): ?string
    {
        if ($storage !== self::STORAGE_S3) {
            return null;
        }
        [$scheme, $host] = $this->hostFor($idShop);

        return $this->signer($idShop)->presignedUrl($scheme, $host, '/' . ltrim($key, '/'), $ttl, [
            'response-content-disposition' => 'attachment; filename="' . str_replace('"', '', $downloadName) . '"',
            'response-content-type' => 'application/pdf',
        ]);
    }

    /**
     * Test de connexion (page de configuration) : écrit puis relit un petit fichier.
     */
    public function selfTest(int $idShop): string
    {
        if (!$this->isConfigured($idShop)) {
            return 'not_configured';
        }
        $key = $this->siteCode($idShop) . '/.snt_quotes_test';
        $this->put($key, 'ok ' . date('c'), 'text/plain', $idShop);

        return str_starts_with($this->get(self::STORAGE_S3, $key, $idShop), 'ok ') ? 'ok' : 'read_failed';
    }

    public function siteCode(int $idShop): string
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', $this->config->get(ConfigKeys::SHOP_CODE, $idShop)) ?? '';

        return $code !== '' ? strtolower($code) : 'shop' . $idShop;
    }

    private function signer(int $idShop): S3Signer
    {
        return new S3Signer(
            $this->config->get(ConfigKeys::S3_KEY, $idShop),
            $this->config->get(ConfigKeys::S3_SECRET, $idShop),
            $this->config->get(ConfigKeys::S3_REGION, $idShop) ?: 'fr-par'
        );
    }

    /**
     * Hôte « virtual-hosted » : https://s3.fr-par.scw.cloud + bucket → bucket.s3.fr-par.scw.cloud.
     *
     * @return array{0: string, 1: string}
     */
    private function hostFor(int $idShop): array
    {
        $endpoint = $this->config->get(ConfigKeys::S3_ENDPOINT, $idShop) ?: 'https://s3.fr-par.scw.cloud';
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 's3.fr-par.scw.cloud';

        return [$scheme, $this->config->get(ConfigKeys::S3_BUCKET, $idShop) . '.' . $host];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $curl = curl_init($url);
        $flat = [];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $flat[] = $name . ': ' . $value;
            }
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new \RuntimeException('Object storage unreachable: ' . $error);
        }

        return [$code, (string) $response];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function localDir(): string
    {
        return rtrim(_PS_ROOT_DIR_, '/\\') . '/var/modules/snt_quotes/';
    }
}
