<?php

namespace App\Service;

interface UrlValidatorInterface
{
    public function validateFeedUrl(string $url): bool;
}