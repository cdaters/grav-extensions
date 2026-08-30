<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

final class SecretRedactor
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private array $secrets;

    /** @param iterable<string> $secrets */
    public function __construct(iterable $secrets = [])
    {
        $normalized = [];
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $normalized[$secret] = true;
            }
        }
        $this->secrets = array_keys($normalized);
        usort($this->secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /** @param array<string, mixed>|null $environment */
    public static function fromEnvironment(?array $environment = null): self
    {
        if ($environment === null) {
            $values = getenv();
            $environment = is_array($values) ? $values : [];
        }
        $secrets = [];
        foreach ($environment as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'GRAV_JARVIS_') || !is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value !== '') {
                $secrets[] = $value;
            }
        }
        return new self($secrets);
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        $patterns = [
            '/(Bearer\s+)[A-Za-z0-9._~+\/=:-]+/i',
            '/((?:authorization|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|bearer[_ -]?token|auth[_ -]?token|token|secret|client[_ -]?secret|password|credentials?)\s*[:=]\s*)([^\s,;]+)/i',
        ];
        foreach ($patterns as $pattern) {
            $redacted = preg_replace($pattern, '$1' . self::REDACTED, $text);
            if ($redacted !== null) {
                $text = $redacted;
            }
        }
        return $text;
    }

    public function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(
            '/^(?:authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|bearer[_-]?token|auth[_-]?token|token|secret|client[_-]?secret|password|credentials?)$/i',
            $key
        ) === 1) {
            return self::REDACTED;
        }
        if (is_string($value)) {
            return $this->redact($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $redacted = [];
        foreach ($value as $itemKey => $item) {
            $redacted[$itemKey] = $this->redactValue($item, is_string($itemKey) ? $itemKey : null);
        }
        return $redacted;
    }
}
