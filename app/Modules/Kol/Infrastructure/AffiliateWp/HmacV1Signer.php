<?php

namespace App\Modules\Kol\Infrastructure\AffiliateWp;

final class HmacV1Signer
{
    /**
     * Build required request headers including HMAC-SHA256 V1 signature.
     *
     * Canonical payload (Contract 25 §4):
     *   HTTP_METHOD\n REQUEST_PATH_WITH_QUERY\n TIMESTAMP\n NONCE\n CONNECTION_KEY\n IDEMPOTENCY_KEY\n SHA256(RAW_BODY)
     *
     * @param  non-empty-string  $sharedSecret
     * @return array<string,string>
     */
    public static function headers(
        string $method,
        string $pathWithQuery,
        string $connectionKey,
        string $keyId,
        string $sharedSecret,
        string $body,
        ?string $idempotencyKey,
        string $timestamp,
        string $nonce,
    ): array {
        $bodyHash = hash('sha256', $body);
        $canonical = implode("\n", [
            strtoupper($method),
            $pathWithQuery,
            $timestamp,
            $nonce,
            $connectionKey,
            $idempotencyKey ?? '',
            $bodyHash,
        ]);

        $signature = hash_hmac('sha256', $canonical, $sharedSecret);

        $headers = [
            'X-YBY-Key-Id' => $keyId,
            'X-YBY-Connection-Key' => $connectionKey,
            'X-YBY-Timestamp' => $timestamp,
            'X-YBY-Nonce' => $nonce,
            'X-YBY-Signature-Version' => 'v1',
            'X-YBY-Signature' => $signature,
        ];

        if ($idempotencyKey !== null) {
            $headers['X-YBY-Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }
}
