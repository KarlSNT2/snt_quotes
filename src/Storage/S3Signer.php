<?php

declare(strict_types=1);

namespace Snt\Quotes\Storage;

/**
 * Signature AWS Signature V4 (compatible Scaleway Object Storage), sans SDK. Pur et testable.
 */
final class S3Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * En-têtes signés pour une requête (PUT/GET/DELETE).
     *
     * @param array<string, string> $headers en-têtes supplémentaires à signer (content-type…)
     *
     * @return array<string, string>
     */
    public function signHeaders(string $method, string $host, string $path, string $payloadHash, array $headers = [], ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $shortDate = $now->format('Ymd');

        $headers = array_change_key_case($headers + [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ]);
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::encodePath($path),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $signature = $this->signature($shortDate, $amzDate, $scope, $canonicalRequest);

        $headers['authorization'] = self::ALGORITHM . ' Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * URL présignée (GET) valable $expires secondes.
     *
     * @param array<string, string> $extraQuery paramètres de réponse (response-content-disposition…)
     */
    public function presignedUrl(string $scheme, string $host, string $path, int $expires, array $extraQuery = [], ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $shortDate = $now->format('Ymd');
        $scope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $query = $extraQuery + [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min(604800, $expires)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = self::canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            'GET',
            self::encodePath($path),
            $canonicalQuery,
            'host:' . $host . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        $signature = $this->signature($shortDate, $amzDate, $scope, $canonicalRequest);

        return $scheme . '://' . $host . self::encodePath($path) . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    private function signature(string $shortDate, string $amzDate, string $scope, string $canonicalRequest): string
    {
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $key = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $stringToSign, $key);
    }

    /**
     * @param array<string, string> $query trié par clé
     */
    private static function canonicalQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    private static function encodePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
