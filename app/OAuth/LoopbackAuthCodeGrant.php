<?php

namespace App\OAuth;

use Psr\Http\Message\ServerRequestInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Entities\ClientEntityInterface;

class LoopbackAuthCodeGrant extends AuthCodeGrant
{
    /**
     * @param  string[]|string  $allowed
     */
    protected function validateRedirectUri(string $redirectUri, ClientEntityInterface $client, ServerRequestInterface $request): void
    {
        if ($this->localhostRedirectMatches($redirectUri, $client->getRedirectUri())) {
            return;
        }

        parent::validateRedirectUri($redirectUri, $client, $request);
    }

    /**
     * Claude Code registers http://localhost/callback and then listens on an ephemeral port.
     *
     * @param  string[]|string  $allowed
     */
    private function localhostRedirectMatches(string $redirectUri, array|string $allowed): bool
    {
        $requested = parse_url($redirectUri);

        if (($requested['scheme'] ?? null) !== 'http' || strcasecmp((string) ($requested['host'] ?? ''), 'localhost') !== 0) {
            return false;
        }

        $needle = $this->withoutPort($redirectUri);

        foreach (is_array($allowed) ? $allowed : [$allowed] as $candidate) {
            $parsed = parse_url($candidate);

            if (($parsed['scheme'] ?? null) !== 'http' || strcasecmp((string) ($parsed['host'] ?? ''), 'localhost') !== 0) {
                continue;
            }

            if ($needle === $this->withoutPort($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function withoutPort(string $url): string
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'http://localhost'.$path.$query;
    }
}
