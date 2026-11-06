<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Storage\S3Signer;

/**
 * Vecteurs de test officiels AWS (Signature V4, documentation Amazon S3).
 */
final class S3SignerTest extends TestCase
{
    private const KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24 00:00:00', new \DateTimeZone('UTC'));
    }

    public function testPresignedUrlMatchesAwsExample(): void
    {
        $signer = new S3Signer(self::KEY, self::SECRET, 'us-east-1');
        $url = $signer->presignedUrl('https', 'examplebucket.s3.amazonaws.com', '/test.txt', 86400, [], $this->now());

        self::assertStringEndsWith('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
        self::assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
    }

    public function testGetObjectHeadersMatchAwsExample(): void
    {
        $signer = new S3Signer(self::KEY, self::SECRET, 'us-east-1');
        $headers = $signer->signHeaders(
            'GET',
            'examplebucket.s3.amazonaws.com',
            '/test.txt',
            hash('sha256', ''),
            ['range' => 'bytes=0-9'],
            $this->now()
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['authorization']
        );
    }
}
