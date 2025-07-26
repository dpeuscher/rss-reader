<?php

namespace App\Service\Security;

class UrlValidationResult
{
    private bool $isValid;
    private string $message;
    private array $violations;

    public function __construct(bool $isValid, string $message = '', array $violations = [])
    {
        $this->isValid = $isValid;
        $this->message = $message;
        $this->violations = $violations;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public static function valid(): self
    {
        return new self(true, 'URL is valid');
    }

    public static function invalid(string $message, array $violations = []): self
    {
        return new self(false, $message, $violations);
    }
}