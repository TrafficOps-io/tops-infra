<?php

namespace TrafficOps\Cloudflare\DTO;

use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Support\Hostname;

final readonly class DnsRecordExpectation
{
    private const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'TXT'];

    public string $type;

    public string $name;

    public string $content;

    public function __construct(
        string $type,
        string $name,
        string $content,
        public int $ttl = 1,
        public ?bool $proxied = null,
    ) {
        $type = strtoupper(trim($type));

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new CloudflareValidationException("Unsupported DNS record type [$type].");
        }

        if ($ttl < 1) {
            throw new CloudflareValidationException('DNS record TTL must be a positive integer.');
        }

        $this->type = $type;
        $this->name = Hostname::normalize($name, allowWildcard: true, allowUnderscore: true);
        $this->content = self::normalizeContent($type, $content);

        if ($proxied !== null && ! in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            throw new CloudflareValidationException("DNS record type [$type] cannot be proxied.");
        }
    }

    /** @return array{type: string, name: string, content: string, ttl: int, proxied?: bool} */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'proxied' => $this->proxied,
        ], fn (mixed $value) => $value !== null);
    }

    /** @param array<string, mixed> $record */
    public function matches(array $record): bool
    {
        $remoteType = strtoupper((string) ($record['type'] ?? ''));
        $remoteName = Hostname::normalize((string) ($record['name'] ?? ''), allowWildcard: true, allowUnderscore: true);
        $remoteContent = self::normalizeContent($remoteType, (string) ($record['content'] ?? ''));

        if ($remoteType !== $this->type || $remoteName !== $this->name || $remoteContent !== $this->content) {
            return false;
        }

        if ($this->proxied !== null && (bool) ($record['proxied'] ?? false) !== $this->proxied) {
            return false;
        }

        return $this->ttl === 1 || (int) ($record['ttl'] ?? 0) === $this->ttl;
    }

    public function signature(): string
    {
        return hash('sha256', implode("\0", [$this->type, $this->name, $this->content, (string) $this->ttl, var_export($this->proxied, true)]));
    }

    private static function normalizeContent(string $type, string $content): string
    {
        $content = trim($content);

        if (in_array($type, ['A', 'AAAA'], true)) {
            $binary = @inet_pton($content);

            return $binary === false ? $content : inet_ntop($binary);
        }

        return $type === 'CNAME' ? Hostname::normalize($content) : $content;
    }
}
