<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\Cities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VerifyLegacyUrlsCommand extends Command
{
    protected $signature = 'azp:verify-urls
                            {--live=https://www.azilapranz.ro : Base URL of the legacy site}
                            {--min-urls=300 : Fail if fewer than this many venue URLs are scraped}';

    protected $description = 'Check that every venue URL the live legacy site links to can be generated locally';

    public function handle(): int
    {
        $live = rtrim((string) $this->option('live'), '/');

        $locations = Location::all();

        if ($locations->isEmpty()) {
            $this->error('No locations imported — run azp:import-legacy first.');
            $this->line('Comparing an empty database against the live site would pass vacuously.');

            return self::FAILURE;
        }

        $canonical = $locations
            ->mapWithKeys(fn ($l) => [ltrim($l->path, '/') => true])
            ->all();

        $scraped = [];
        $failures = [];

        foreach (Cities::slugs() as $city) {
            $url = "{$live}/{$city}/restaurante.html";

            try {
                $response = Http::timeout(30)->get($url);
            } catch (\Throwable $e) {
                $this->error("  fetch failed: {$url} ({$e->getMessage()})");
                $failures[] = $url;

                continue;
            }

            // Laravel's HTTP client does not throw on 4xx/5xx. Without this an
            // error page scrapes to zero links and the whole gate passes green.
            if (! $response->successful()) {
                $this->error(sprintf('  fetch failed: %s (HTTP %d)', $url, $response->status()));
                $failures[] = $url;

                continue;
            }

            preg_match_all("#href='([a-z0-9\-]+/[^']*?-(\d+)\.html)'#", $response->body(), $matches, PREG_SET_ORDER);

            $found = 0;
            foreach ($matches as $match) {
                if (str_contains($match[1], 'meniul-zilei') || str_contains($match[1], 'oferte-speciale')) {
                    continue;
                }

                $scraped[$match[1]] = true;
                $found++;
            }

            $this->line(sprintf('  %-14s %4d venue links', $city, $found));
        }

        $missing = array_diff(array_keys($scraped), array_keys($canonical));
        $min = (int) $this->option('min-urls');

        $this->newLine();
        $this->line(sprintf('live venue URLs scraped : %d', count($scraped)));
        $this->line(sprintf('canonical URLs locally  : %d', count($canonical)));
        $this->line(sprintf('locations imported      : %d', $locations->count()));

        if ($failures) {
            $this->newLine();
            $this->error(sprintf('%d city page(s) could not be fetched; coverage is incomplete.', count($failures)));

            return self::FAILURE;
        }

        if ($missing) {
            $this->newLine();
            $this->error(sprintf('%d live URL(s) cannot be generated locally:', count($missing)));
            foreach (array_slice($missing, 0, 40) as $m) {
                $this->error('  /'.$m);
            }

            return self::FAILURE;
        }

        // Without a floor, anything that quietly stops the scrape from matching
        // — a redesigned template, double-quoted attributes, a consent wall —
        // yields zero URLs, and "zero missing" would read as a pass.
        if (count($scraped) < $min) {
            $this->newLine();
            $this->error(sprintf(
                'Only %d venue URLs were scraped, below the expected floor of %d.',
                count($scraped),
                $min
            ));
            $this->line('The scrape is broken (template change or blocked request), not the data.');
            $this->line('Confirm against the live site before trusting this result; --min-urls overrides.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('All %d live venue URLs resolve to a canonical local URL.', count($scraped)));

        return self::SUCCESS;
    }
}
