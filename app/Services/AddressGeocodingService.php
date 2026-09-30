<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AddressGeocodingService
{
    public function resolve(string $address, string $city = 'Mar del Plata', string $state = 'Buenos Aires'): array
    {
        $address = $this->clean($address);
        $city = $this->clean($city);
        $state = $this->clean($state);

        if ($address === '' || $city === '' || $state === '') {
            throw new RuntimeException('Completá la dirección o esquina y la ciudad.');
        }

        $queries = [$this->fullQuery($address, $city, $state)];
        $intersection = $this->normalizeIntersection($address);
        if ($intersection !== $address) {
            $queries[] = $this->fullQuery($intersection, $city, $state);
        }

        foreach (array_unique($queries) as $query) {
            $result = $this->search($query);
            if (!$result) continue;

            $latitude = $result['lat'] ?? null;
            $longitude = $result['lon'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) continue;

            $details = is_array($result['address'] ?? null) ? $result['address'] : [];
            $streetName = $details['road']
                ?? $details['pedestrian']
                ?? $details['residential']
                ?? $details['street']
                ?? $this->firstStreet($address);

            $streetNumber = $details['house_number']
                ?? $this->houseNumber($address)
                ?? 'S/N';

            $resolvedCity = $details['city']
                ?? $details['town']
                ?? $details['municipality']
                ?? $details['village']
                ?? $city;

            $resolvedState = $details['state'] ?? $state;

            return [
                'street_name' => $this->clean((string) $streetName),
                'street_number' => $this->clean((string) $streetNumber),
                'city_name' => $this->clean((string) $resolvedCity),
                'state_name' => $this->clean((string) $resolvedState),
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'location_reference' => $streetNumber === 'S/N' ? $address : null,
                'display_name' => (string) ($result['display_name'] ?? $query),
            ];
        }

        throw new RuntimeException('No pude ubicar esa dirección o esquina. Probá, por ejemplo, “Belgrano 2100” o “Belgrano e Independencia”.');
    }

    private function search(string $query): ?array
    {
        $cacheKey = 'geocoding:nominatim:' . sha1(mb_strtolower($query));

        return Cache::remember($cacheKey, now()->addYear(), function () use ($query) {
            return Cache::lock('geocoding:nominatim:request', 10)->block(10, function () use ($query) {
                $lastRequestAt = (float) Cache::get('geocoding:nominatim:last_request_at', 0);
                $wait = 1.05 - (microtime(true) - $lastRequestAt);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }

                $response = Http::withHeaders([
                    'User-Agent' => (string) config('services.geocoding.user_agent'),
                    'Referer' => (string) config('app.url'),
                    'Accept-Language' => 'es-AR,es;q=0.9',
                ])->acceptJson()->timeout(12)->get((string) config('services.geocoding.endpoint'), [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 1,
                    'countrycodes' => 'ar',
                ]);

                Cache::put('geocoding:nominatim:last_request_at', microtime(true), now()->addMinute());

                if (!$response->successful()) {
                    throw new RuntimeException('No pude consultar el servicio de ubicación. Probá nuevamente en unos segundos.');
                }

                $results = $response->json();
                return is_array($results) && isset($results[0]) && is_array($results[0])
                    ? $results[0]
                    : null;
            });
        });
    }

    private function fullQuery(string $address, string $city, string $state): string
    {
        return "{$address}, {$city}, {$state}, Argentina";
    }

    private function normalizeIntersection(string $address): string
    {
        return preg_replace('/\s+(?:y|e)\s+/iu', ' & ', $address, 1) ?: $address;
    }

    private function firstStreet(string $address): string
    {
        $parts = preg_split('/\s+(?:y|e|&)\s+/iu', $address, 2);
        $first = trim((string) ($parts[0] ?? $address));
        $first = preg_replace('/\s+\d+[A-Za-z-]*\s*$/u', '', $first) ?: $first;
        return $this->clean($first);
    }

    private function houseNumber(string $address): ?string
    {
        if (preg_match('/(?:^|\s)(\d+[A-Za-z-]*)\s*$/u', $address, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
