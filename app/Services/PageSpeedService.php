<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google PageSpeed Insights (Lighthouse) v5 client. Free, key-only (no
 * OAuth), 25k req/day. Runs mobile + desktop in parallel and returns a
 * shape ready for a direct Website::update() — scores, Core Web Vitals,
 * and a trimmed opportunities report stored in pagespeed_data.
 */
class PageSpeedService
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * @return array<string, mixed>
     */
    public function check(Website $website): array
    {
        $url = $website->url;
        $key = config('services.cpanel.pagespeed_key');

        $responses = Http::pool(fn ($pool) => [
            $pool->as('mobile')->timeout(30)->get(self::API_URL, [
                'url' => $url,
                'strategy' => 'mobile',
                'key' => $key,
                'category' => ['performance', 'accessibility', 'seo', 'best-practices'],
            ]),
            $pool->as('desktop')->timeout(30)->get(self::API_URL, [
                'url' => $url,
                'strategy' => 'desktop',
                'key' => $key,
                'category' => ['performance'],
            ]),
        ]);

        $mobile = $responses['mobile'];
        $desktop = $responses['desktop'];

        if ($mobile instanceof \Throwable) {
            throw new \RuntimeException('PageSpeed API error: '.$mobile->getMessage());
        }
        if ($mobile->failed()) {
            throw new \RuntimeException('PageSpeed API error: '.$mobile->status());
        }

        $mobileScore = (int) round(((float) ($mobile->json('lighthouseResult.categories.performance.score') ?? 0)) * 100);
        $desktopScore = ($desktop instanceof \Throwable || $desktop->failed())
            ? 0
            : (int) round(((float) ($desktop->json('lighthouseResult.categories.performance.score') ?? 0)) * 100);

        // The other three Lighthouse categories are only requested for
        // mobile. Null when the run didn't return them so the report
        // modal can hide what's missing rather than show a false 0.
        $categoryScore = function (string $category) use ($mobile): ?int {
            $raw = $mobile->json("lighthouseResult.categories.{$category}.score");

            return $raw === null ? null : (int) round(((float) $raw) * 100);
        };

        $audits = $mobile->json('lighthouseResult.audits') ?? [];

        $lcp = $this->extractSeconds($audits['largest-contentful-paint']['displayValue'] ?? null);
        $cls = (float) ($audits['cumulative-layout-shift']['displayValue'] ?? 0);
        $fcp = $this->extractSeconds($audits['first-contentful-paint']['displayValue'] ?? null);
        $tbt = (int) ($audits['total-blocking-time']['numericValue'] ?? 0);

        $reportData = [
            'mobile' => [
                'score' => $mobileScore,
                'accessibility' => $categoryScore('accessibility'),
                'seo' => $categoryScore('seo'),
                'best_practices' => $categoryScore('best-practices'),
                'lcp' => $lcp,
                'cls' => $cls,
                'fcp' => $fcp,
                'tbt' => $tbt,
            ],
            'desktop' => [
                'score' => $desktopScore,
            ],
            'opportunities' => collect($audits)
                ->filter(fn ($a): bool => ($a['score'] ?? 1) < 0.9 && isset($a['details']))
                ->map(fn ($a): array => [
                    'title' => $a['title'] ?? '',
                    'description' => Str::limit($a['description'] ?? '', 150),
                    'savings' => $a['displayValue'] ?? '',
                ])
                ->values()
                ->take(20)
                ->all(),
        ];

        return [
            'pagespeed_mobile' => $mobileScore,
            'pagespeed_desktop' => $desktopScore,
            'pagespeed_lcp' => $lcp,
            'pagespeed_cls' => $cls,
            'pagespeed_fcp' => $fcp,
            'pagespeed_tbt' => $tbt,
            'pagespeed_data' => $reportData,
            'pagespeed_checked_at' => now(),
        ];
    }

    /**
     * Lightweight key check — a single request against a known-good URL.
     * Returns true when the API accepts the key and responds. Avoids the
     * full multi-category run check() performs.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::timeout(20)->get(self::API_URL, [
                'url' => 'https://www.google.com',
                'strategy' => 'mobile',
                'key' => config('services.cpanel.pagespeed_key'),
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse Lighthouse display values like "2.4 s" or "450 ms" into float
     * seconds.
     */
    private function extractSeconds(?string $display): float
    {
        if (! $display) {
            return 0;
        }
        if (str_contains($display, ' ms')) {
            return round(((float) trim(str_replace([' ms', ','], ['', ''], $display))) / 1000, 2);
        }
        if (str_contains($display, ' s')) {
            return (float) trim(str_replace([' s', ','], ['', ''], $display));
        }

        return 0;
    }
}
