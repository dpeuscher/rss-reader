<?php

namespace App\Service;

class FeedFormatDetector
{
    public const FORMAT_RSS_2_0 = 'RSS_2_0';
    public const FORMAT_RSS_1_0 = 'RSS_1_0';
    public const FORMAT_ATOM_1_0 = 'ATOM_1_0';
    public const FORMAT_JSON_FEED = 'JSON_FEED';
    public const FORMAT_UNKNOWN = 'UNKNOWN';

    public function detectFormat(string $content): string
    {
        // Trim whitespace
        $content = trim($content);
        
        if (empty($content)) {
            return self::FORMAT_UNKNOWN;
        }

        // JSON Feed detection - check if content starts with { and contains JSON Feed signature
        if ($content[0] === '{') {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($data['version']) && 
                    is_string($data['version']) && 
                    strpos($data['version'], 'https://jsonfeed.org/version/') === 0) {
                    return self::FORMAT_JSON_FEED;
                }
            }
            return self::FORMAT_UNKNOWN;
        }

        // XML-based format detection
        try {
            $doc = new \DOMDocument();
            $originalErrors = libxml_use_internal_errors(true);
            
            $loaded = $doc->loadXML($content);
            
            if (!$loaded) {
                libxml_use_internal_errors($originalErrors);
                return self::FORMAT_UNKNOWN;
            }

            $root = $doc->documentElement;
            if (!$root) {
                libxml_use_internal_errors($originalErrors);
                return self::FORMAT_UNKNOWN;
            }

            // Atom detection - check namespace
            if ($root->namespaceURI === 'http://www.w3.org/2005/Atom') {
                libxml_use_internal_errors($originalErrors);
                return self::FORMAT_ATOM_1_0;
            }

            // RSS 1.0 (RDF) detection - check namespace and root element
            if ($root->namespaceURI === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' ||
                $root->nodeName === 'rdf:RDF') {
                libxml_use_internal_errors($originalErrors);
                return self::FORMAT_RSS_1_0;
            }

            // RSS 2.0 detection - check root element and version
            if ($root->nodeName === 'rss') {
                $version = $root->getAttribute('version');
                if ($version === '2.0') {
                    libxml_use_internal_errors($originalErrors);
                    return self::FORMAT_RSS_2_0;
                }
                // If no version or different version, assume RSS 2.0 as fallback
                libxml_use_internal_errors($originalErrors);
                return self::FORMAT_RSS_2_0;
            }

            libxml_use_internal_errors($originalErrors);
            return self::FORMAT_UNKNOWN;

        } catch (\Exception $e) {
            return self::FORMAT_UNKNOWN;
        }
    }

    public function getFormatDisplayName(string $format): string
    {
        return match ($format) {
            self::FORMAT_RSS_2_0 => 'RSS 2.0',
            self::FORMAT_RSS_1_0 => 'RSS 1.0 (RDF)',
            self::FORMAT_ATOM_1_0 => 'Atom 1.0',
            self::FORMAT_JSON_FEED => 'JSON Feed',
            default => 'Unknown'
        };
    }

    public function isFormatSupported(string $format): bool
    {
        return in_array($format, [
            self::FORMAT_RSS_2_0,
            self::FORMAT_RSS_1_0,
            self::FORMAT_ATOM_1_0,
            self::FORMAT_JSON_FEED
        ]);
    }

    public function getAllSupportedFormats(): array
    {
        return [
            self::FORMAT_RSS_2_0,
            self::FORMAT_RSS_1_0,
            self::FORMAT_ATOM_1_0,
            self::FORMAT_JSON_FEED
        ];
    }
}