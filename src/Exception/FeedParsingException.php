<?php

namespace App\Exception;

class FeedParsingException extends \Exception
{
    private string $feedFormat;
    private ?string $suggestion;

    public function __construct(
        string $message, 
        string $feedFormat = 'UNKNOWN', 
        ?string $suggestion = null, 
        int $code = 0, 
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->feedFormat = $feedFormat;
        $this->suggestion = $suggestion;
    }

    public function getFeedFormat(): string
    {
        return $this->feedFormat;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function getDetailedMessage(): string
    {
        $message = $this->getMessage();
        
        if ($this->feedFormat !== 'UNKNOWN') {
            $message .= " (Format: {$this->feedFormat})";
        }
        
        if ($this->suggestion) {
            $message .= " Suggestion: {$this->suggestion}";
        }
        
        return $message;
    }

    public static function invalidJsonFeed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Invalid JSON Feed: {$reason}",
            'JSON_FEED',
            'Ensure the feed follows JSON Feed 1.1 specification (https://jsonfeed.org/version/1.1)',
            0,
            $previous
        );
    }

    public static function invalidRssFeed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Invalid RSS Feed: {$reason}",
            'RSS_2_0',
            'Check that the XML structure follows RSS 2.0 specification',
            0,
            $previous
        );
    }

    public static function invalidAtomFeed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Invalid Atom Feed: {$reason}",
            'ATOM_1_0',
            'Verify the feed follows Atom 1.0 specification',
            0,
            $previous
        );
    }

    public static function unsupportedFormat(string $detectedFormat): self
    {
        $suggestion = match ($detectedFormat) {
            'UNKNOWN' => 'Verify the URL points to a valid feed. Supported formats: RSS 2.0, RSS 1.0, Atom 1.0, JSON Feed',
            default => "The detected format '{$detectedFormat}' is not supported. Supported formats: RSS 2.0, RSS 1.0, Atom 1.0, JSON Feed"
        };

        return new self(
            "Unsupported feed format: {$detectedFormat}",
            $detectedFormat,
            $suggestion
        );
    }

    public static function networkError(string $url, int $statusCode): self
    {
        $suggestion = match (true) {
            $statusCode === 404 => 'The feed URL was not found. Check if the URL is correct.',
            $statusCode === 403 => 'Access to the feed is forbidden. The feed may require authentication.',
            $statusCode >= 500 => 'The server is experiencing issues. Try again later.',
            default => 'Check the feed URL and try again.'
        };

        return new self(
            "Network error accessing feed '{$url}': HTTP {$statusCode}",
            'UNKNOWN',
            $suggestion
        );
    }

    public static function timeoutError(string $url): self
    {
        return new self(
            "Timeout accessing feed '{$url}'",
            'UNKNOWN',
            'The feed server is responding slowly. Try again later or check if the URL is correct.'
        );
    }
}