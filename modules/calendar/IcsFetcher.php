<?php

namespace Modules\Calendar;

use Closure;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads a subscribed calendar. The URL is typed by a user and fetched by our server, so this is the SSRF
 * boundary: only http(s) (webcal:// is https), every address the host resolves to must be public (no loopback,
 * private, link-local, cloud metadata at 169.254.169.254, reserved), the connection is pinned to the address we
 * checked (CURLOPT_RESOLVE, so a second DNS answer can't swap in an internal one), redirects are followed by hand
 * and each hop is checked again, and size and time are capped.
 *
 * Failures throw RuntimeException whose message is a reason code the UI translates: blocked | unreachable.
 */
class IcsFetcher
{
    public const MAX_BYTES = 5_000_000;

    private const MAX_REDIRECTS = 3;

    /** Test seam: fn (string $host): list<string> of IPv4 addresses. Null = real DNS. */
    public static ?Closure $resolveUsing = null;

    public static function get(string $url): string
    {
        $url = self::normalize($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = self::target($url);
            try {
                $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()
                    ->withHeaders(['Accept' => 'text/calendar, */*;q=0.5', 'User-Agent' => 'FamilyWell-Calendar/1.0'])
                    ->withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                    ->get($url);
            } catch (\Throwable) {
                throw new RuntimeException('unreachable');
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('unreachable');
                }
                $url = self::normalize(self::absolute($url, $location));

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException('unreachable');
            }

            // Read at most MAX_BYTES (+1 to tell "too big" from "exactly the limit").
            $body = $response->toPsrResponse()->getBody();
            $data = '';
            while (! $body->eof() && strlen($data) <= self::MAX_BYTES) {
                $data .= $body->read(65536);
            }
            if (strlen($data) > self::MAX_BYTES) {
                throw new RuntimeException('unreachable');
            }

            return $data;
        }

        throw new RuntimeException('unreachable');
    }

    /** webcal:// → https://, and only http(s) with a host. */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^webcals?://#i', 'https://', $url);
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('blocked');
        }

        return $url;
    }

    /** [host, port, pinned public IPv4]. */
    private static function target(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        // A literal IP is checked like a resolved one. IPv6 literals are refused outright.
        // ponytail: IPv4 only (gethostbynamel); calendar hosts all serve IPv4. Add AAAA + v6 range checks if one doesn't.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : [];
        } else {
            $ips = self::$resolveUsing ? (self::$resolveUsing)($host) : (gethostbynamel($host) ?: []);
        }
        if ($ips === []) {
            throw new RuntimeException('unreachable');
        }
        // Every answer must be public: one internal address in the set is enough to refuse. GLOBAL_RANGE (PHP 8.2+)
        // is private + reserved + the rest of RFC 6890 (CGNAT 100.64/10, benchmarking, ...), which a cloud host may route.
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('blocked');
            }
        }

        return [$host, $port, $ips[0]];
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin.$dir.$location;
    }
}
