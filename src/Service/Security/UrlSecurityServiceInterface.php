<?php

namespace App\Service\Security;

interface UrlSecurityServiceInterface
{
    public function validateUrl(string $url): UrlValidationResult;
    public function isUrlSafe(string $url): bool;
}