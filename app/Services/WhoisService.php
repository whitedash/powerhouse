<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bare-metal WHOIS lookup over the classic port-43 protocol.
 *
 * We deliberately avoid any third-party SDK here — the protocol is
 * trivial (open socket, send "{domain}\r\n", read everything back)
 * and the wrapped libraries pull in unnecessary dependencies. The
 * downside is per-TLD regex hand-rolling; that's contained to the
 * two `extract*` helpers below.
 *
 * Used by DomainController::whoisLookup so the operator can pre-fill
 * the registrar + expiry on a new domain row without typing them.
 * No external network = no problem — every method degrades gracefully
 * to empty/null so the UI just leaves the field blank.
 */
class WhoisService
{
    private const TIMEOUT = 10;

    private const PORT = 43;

    /**
     * Look up a single domain. Returns a 3-tuple — registrar string,
     * ISO expiry date string, and the raw response (handy for
     * debugging the controller's response).
     *
     * @return array{registrar: ?string, expiry_date: ?string, raw: string}
     */
    public function lookup(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $server = $this->serverFor($domain);

        $raw = $this->queryWhois($server, $domain);

        return [
            'registrar' => $this->extractRegistrar($raw),
            'expiry_date' => $this->extractExpiry($raw),
            'raw' => $raw,
        ];
    }

    /**
     * Pick the right WHOIS server for the domain's TLD. The match
     * handles single-label TLDs first; two-label TLDs (.co.uk,
     * .org.uk) get an explicit second check because the first
     * match would treat them as .uk and pass — that still resolves
     * via nic.uk, so the two paths are equivalent.
     */
    private function serverFor(string $domain): string
    {
        if (str_ends_with($domain, '.co.uk') || str_ends_with($domain, '.org.uk')) {
            return 'whois.nic.uk';
        }

        $tld = substr($domain, (int) strrpos($domain, '.') + 1);

        return match ($tld) {
            'com', 'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'uk' => 'whois.nic.uk',
            'io' => 'whois.nic.io',
            'co' => 'whois.nic.co',
            'dev', 'app' => 'whois.nic.google',
            default => 'whois.iana.org',
        };
    }

    /**
     * Speak port-43 WHOIS. fsockopen is suppressed because the only
     * way it surfaces failure on a closed/blocked port is to emit
     * a warning — we still want to log the reason so a real network
     * issue is visible to ops, but we don't want it tripping every
     * default error handler the app loads.
     */
    private function queryWhois(string $server, string $domain): string
    {
        try {
            $socket = @fsockopen($server, self::PORT, $errno, $errstr, self::TIMEOUT);
            if ($socket === false) {
                Log::warning('whois.connect_failed', [
                    'server' => $server,
                    'errno' => $errno,
                    'errstr' => $errstr,
                ]);

                return '';
            }

            stream_set_timeout($socket, self::TIMEOUT);
            fwrite($socket, $domain."\r\n");

            $response = '';
            while (! feof($socket)) {
                $chunk = fread($socket, 4096);
                if ($chunk === false) {
                    break;
                }
                $response .= $chunk;
            }
            fclose($socket);

            return $response;
        } catch (Throwable $e) {
            Log::warning('whois.query_failed', [
                'server' => $server,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Different registries label the registrar differently. We try
     * the common patterns in order; first hit wins. Result is
     * trimmed but otherwise unmodified — the registrar string is
     * just text and we don't want to over-normalise it.
     */
    private function extractRegistrar(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $patterns = [
            '/Registrar:\s*(.+)/i',
            '/Sponsoring Registrar:\s*(.+)/i',
            '/Registered by:\s*(.+)/i',
            '/Registrar Name:\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $m) === 1) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Expiry date — every registry uses a slightly different label
     * and date format. We hand the matched string to Carbon::parse
     * which handles ISO, RFC, and most natural date layouts; if it
     * can't, we return null and the caller leaves the field blank.
     */
    private function extractExpiry(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $patterns = [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Registrar Registration Expiration Date:\s*(.+)/i',
            '/Expiration Date:\s*(.+)/i',
            '/Expiry date:\s*(.+)/i',
            '/Expiry Date:\s*(.+)/i',
            '/Renewal date:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $m) === 1) {
                $candidate = trim($m[1]);

                try {
                    return Carbon::parse($candidate)->toDateString();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
