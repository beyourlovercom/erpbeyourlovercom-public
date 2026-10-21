<?php

namespace App\Modules\Kol\Application\AffiliateWp;

final readonly class ConnectorResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $contractVersion,
        public readonly string $requestId,
        public readonly string $connectionKey,
        public readonly mixed $data,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            ok: (bool) ($raw['ok'] ?? false),
            contractVersion: (string) ($raw['contract_version'] ?? ''),
            requestId: (string) ($raw['request_id'] ?? ''),
            connectionKey: (string) ($raw['connection_key'] ?? ''),
            data: $raw['data'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'contract_version' => $this->contractVersion,
            'request_id' => $this->requestId,
            'connection_key' => $this->connectionKey,
            'data' => $this->data,
        ];
    }
}
