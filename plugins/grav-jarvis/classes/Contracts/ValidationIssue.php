<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ValidationIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public string $code;
    public string $message;
    public string $severity;
    public bool $retryable;

    public function __construct(
        string $code,
        string $message,
        string $severity = self::ERROR,
        bool $retryable = false
    ) {
        $code = trim($code);
        $message = trim($message);
        if (!CompletionRequest::validIdentifier($code)) {
            throw new InvalidArgumentException('Validation issue codes must be lowercase stable slugs.');
        }
        if ($message === '' || self::containsUnsafeControlCharacter($message)) {
            throw new InvalidArgumentException('Validation issue messages must be non-empty plain text.');
        }
        if (!in_array($severity, [self::ERROR, self::WARNING, self::INFO], true)) {
            throw new InvalidArgumentException('Validation issue severity is invalid.');
        }

        $this->code = $code;
        $this->message = $message;
        $this->severity = $severity;
        $this->retryable = $retryable;
    }

    /** @return array{code: string, message: string, severity: string, retryable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'retryable' => $this->retryable,
        ];
    }

    private static function containsUnsafeControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
