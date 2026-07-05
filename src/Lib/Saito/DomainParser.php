<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito;

use Pdp\Rules;

class DomainParser
{
    private static ?Rules $rules = null;

    /**
     * Returns host name for $uri
     *
     * `http://www.youtube.com/foo` returns `youtube`
     *
     * @param string $uri uri
     * @return string|null domain if detected or null otherwise
     */
    public static function domain(string $uri): ?string
    {
        $resolved = self::resolve($uri);

        return $resolved !== null ? $resolved->secondLevelDomain()->toString() : null;
    }

    /**
     * Returns top level domain
     *
     * `http://www.youtube.com/foo` returns `youtube.com`
     *
     * @param string $uri uri
     * @return string|null requested URI part if detected or null otherwise
     */
    public static function domainAndTld(string $uri): ?string
    {
        $resolved = self::resolve($uri);

        return $resolved !== null ? $resolved->registrableDomain()->toString() : null;
    }

    private static function resolve(string $uri): ?\Pdp\ResolvedDomainName
    {
        $host = parse_url($uri, PHP_URL_HOST) ?? $uri;
        if ($host === '' || $host === false) {
            return null;
        }

        $resolved = self::rules()->resolve($host);

        return $resolved->registrableDomain()->toString() !== '' ? $resolved : null;
    }

    private static function rules(): Rules
    {
        if (self::$rules === null) {
            self::$rules = Rules::fromPath(ROOT . DS . 'data' . DS . 'public_suffix_list.dat'); // ROOT/DS are CakePHP bootstrap constants skipcq: PHP-W1038
        }

        return self::$rules;
    }
}
