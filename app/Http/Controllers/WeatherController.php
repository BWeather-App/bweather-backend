<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Helpers\WeatherDataHelper;
use App\Helpers\TimeHelper;

class WeatherController extends Controller
{
    public function getWeatherByGPS(Request $request)
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');

        if (!$lat || !$lon) {
            return response()->json(
                ['error' => 'Latitude dan longitude wajib diisi'],
                400
            );
        }

        $result = $this->buildWeatherData($lat, $lon);

        return response()->json($result);
    }

    /**
     * Manual entry point — dipanggil dari SearchController & controller lain.
     * Tidak menerima Request object, langsung menerima lat/lon.
     * Return format sama dengan getWeatherByGPS: {location, weather}.
     */
    public function getWeatherByGPSManual($lat, $lon)
    {
        return $this->buildWeatherData($lat, $lon);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Build Weather Data
    //
    // CACHING: Hasil di-cache per koordinat selama 30 menit (configurable
    // via WEATHER_CACHE_TTL di .env). Menghemat 4 call eksternal per
    // request yang identik → krusial untuk WeatherAPI Free Tier.
    //
    // SEBELUM: 3 request ke WeatherAPI
    //   1. getHourlyForecast()  → forecast.json?days=5
    //   2. getDailyAstronomy()  → forecast.json?days=5  ← DUPLIKAT!
    //   3. getYesterdayWeather() → history.json
    //
    // SESUDAH: 2 request ke WeatherAPI
    //   1. getForecastAndAstronomy() → forecast.json?days=5 (1x saja)
    //   2. getYesterdayWeather()     → history.json
    // ─────────────────────────────────────────────────────────────────────

    private function buildWeatherData($latitude, $longitude)
    {
        $cacheKey = 'weather_' . round($latitude, 3) . '_' . round($longitude, 3);
        $ttl = (int) env('WEATHER_CACHE_TTL', 30);

        return Cache::remember($cacheKey, now()->addMinutes($ttl), function () use ($latitude, $longitude) {
        $lokasi = "$latitude,$longitude";

        // ✅ 1 request untuk hourly + astronomy (sebelumnya 2 request)
        ['hourly' => $perJam, 'astronomy' => $astronomi] =
            $this->getForecastAndAstronomy($lokasi);

        $kemarin  = $this->getYesterdayWeather($lokasi);
        $realtime = $this->getRealtimeWeather($lokasi, $perJam);

        $hariIni = date('Y-m-d');
        $besok   = date('Y-m-d', strtotime('+1 day'));
        $lusa    = date('Y-m-d', strtotime('+2 day'));
        $hariKe3 = date('Y-m-d', strtotime('+3 day'));

        $dataCuaca = [
            'cuaca_saat_ini' => $realtime,
            'kemarin'  => WeatherDataHelper::mapHourlyData($kemarin),
            'hari_ini' => WeatherDataHelper::gabungCuacaAstronomi(
                $perJam[$hariIni] ?? [], $astronomi[$hariIni] ?? []
            ),
            'besok' => WeatherDataHelper::gabungCuacaAstronomi(
                $perJam[$besok] ?? [], $astronomi[$besok] ?? []
            ),
            'lusa' => WeatherDataHelper::gabungCuacaAstronomi(
                $perJam[$lusa] ?? [], $astronomi[$lusa] ?? []
            ),
            'hari_ke_3' => WeatherDataHelper::gabungCuacaAstronomi(
                $perJam[$hariKe3] ?? [], $astronomi[$hariKe3] ?? []
            ),
        ];

        $lokasiInfo = array_merge(
            ['lat' => floatval($latitude), 'lon' => floatval($longitude)],
            $this->getPlaceNameFromLatLon($latitude, $longitude)
        );

        return [
            'location' => $lokasiInfo,
            'weather'  => $dataCuaca,
        ];
    });
}

// ─────────────────────────────────────────────────────────────────────
// Realtime Weather
    // ─────────────────────────────────────────────────────────────────────

    private function getRealtimeWeather(string $lokasi, array $hourlyData = []): array
    {
        $url = $this->buildUrl('current.json', $lokasi);

        // ✅ Http facade — bukan curl manual
        $response = Http::timeout(10)->get($url);

        if ($response->failed()) {
            return ['kesalahan' => 'Data realtime tidak ditemukan'];
        }

        $data = $response->json();
        if (!isset($data['current'])) {
            return ['kesalahan' => 'Data realtime tidak ditemukan'];
        }

        $v = $data['current'];

        // Estimasi peluang hujan dari data forecast per jam
        $peluangHujan = $this->estimateRainChance($hourlyData);

        return [
            'waktu'          => $v['last_updated'] ?? null,
            'suhu'           => $v['temp_c'] ?? null,
            'kelembapan'     => $v['humidity'] ?? null,
            'kecepatan_angin'=> $v['wind_kph'] ?? null,
            'arah_angin'     => $v['wind_degree'] ?? null,
            'tekanan_udara'  => $v['pressure_mb'] ?? null,
            'indeks_uv'      => $v['uv'] ?? null,
            'terasa_seperti' => $v['feelslike_c'] ?? null,
            'peluang_hujan'  => $peluangHujan,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Forecast + Astronomy — 1 request, 2 hasil
    //
    // Menggantikan getHourlyForecast() + getDailyAstronomy()
    // yang sebelumnya hit endpoint yang sama dua kali.
    // ─────────────────────────────────────────────────────────────────────

    private function getForecastAndAstronomy(string $lokasi): array
    {
        $url = $this->buildUrl('forecast.json', $lokasi, ['days' => 5]);

        // ✅ Http facade — bukan curl manual
        $response = Http::timeout(10)->get($url);

        if ($response->failed()) {
            return ['hourly' => [], 'astronomy' => []];
        }

        $data = $response->json();

        if (!isset($data['forecast']['forecastday'])) {
            return ['hourly' => [], 'astronomy' => []];
        }

        $hourlyRaw  = [];
        $astronomyResult = [];

        foreach ($data['forecast']['forecastday'] as $day) {
            $tanggal = $day['date'] ?? null;

            // Hourly data
            foreach ($day['hour'] as $jam) {
                $hourlyRaw[] = [
                    'time'   => $jam['time'],
                    'values' => [
                        'temperature'             => $jam['temp_c'] ?? null,
                        'humidity'                => $jam['humidity'] ?? null,
                        'windSpeed'               => $jam['wind_kph'] ?? null,
                        'windDirection'           => $jam['wind_degree'] ?? null,
                        'pressureSurfaceLevel'    => $jam['pressure_mb'] ?? null,
                        'uvIndex'                 => $jam['uv'] ?? null,
                        'temperatureApparent'     => $jam['feelslike_c'] ?? null,
                        'precipitationProbability'=> $jam['chance_of_rain'] ?? null,
                    ],
                ];
            }

            // Astronomy data — diambil dari request yang sama
            if ($tanggal && isset($day['astro'])) {
                $astro = $day['astro'];
                $astronomyResult[$tanggal] = [
                    'matahari_terbit'   => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['sunrise'] ?? null),
                    'matahari_terbenam' => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['sunset'] ?? null),
                    'bulan_terbit'      => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['moonrise'] ?? null),
                    'bulan_terbenam'    => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['moonset'] ?? null),
                ];
            }
        }

        return [
            'hourly'    => WeatherDataHelper::groupByDate($hourlyRaw),
            'astronomy' => $astronomyResult,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Yesterday Weather
    // ─────────────────────────────────────────────────────────────────────

    private function getYesterdayWeather(string $lokasi): array
    {
        $tanggalKemarin = date('Y-m-d', strtotime('-1 day'));
        $url = $this->buildUrl('history.json', $lokasi, ['dt' => $tanggalKemarin]);

        // ✅ Http facade — bukan curl manual
        $response = Http::timeout(10)->get($url);

        if ($response->failed()) return [];

        $data = $response->json();
        if (!isset($data['forecast']['forecastday'][0]['hour'])) return [];

        $hasil = [];
        foreach ($data['forecast']['forecastday'][0]['hour'] as $jam) {
            $hasil[] = [
                'time'   => $jam['time'],
                'values' => [
                    'temperature'             => $jam['temp_c'] ?? null,
                    'humidity'                => $jam['humidity'] ?? null,
                    'windSpeed'               => $jam['wind_kph'] ?? null,
                    'windDirection'           => $jam['wind_degree'] ?? null,
                    'pressureSurfaceLevel'    => $jam['pressure_mb'] ?? null,
                    'uvIndex'                 => $jam['uv'] ?? null,
                    'temperatureApparent'     => $jam['feelslike_c'] ?? null,
                    'precipitationProbability'=> $jam['chance_of_rain'] ?? null,
                ],
            ];
        }

        return $hasil;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Get Place Name from Lat/Lon
    // ─────────────────────────────────────────────────────────────────────

    private function getPlaceNameFromLatLon($lat, $lon): array
    {
        $apiKey = env('GEOCODING_API_KEY');
        $response = Http::timeout(10)->get(
            "https://api.opencagedata.com/geocode/v1/json",
            ['q' => "$lat+$lon", 'key' => $apiKey]
        );

        if ($response->failed()) {
            return ['city' => 'Tidak Diketahui', 'region' => '', 'country' => ''];
        }

        $data = $response->json();

        if (!isset($data['results'][0]['components'])) {
            return ['city' => 'Tidak Diketahui', 'region' => '', 'country' => ''];
        }

        $c = $data['results'][0]['components'];

        $city = $c['city']
            ?? $c['municipality']
            ?? $c['town']
            ?? $c['county']
            ?? $c['region']
            ?? null;

        return [
            'city'    => $this->translateCity($city ?? 'Kota Tidak Diketahui'),
            'region'  => $this->translateRegion($c['state'] ?? 'Wilayah Tidak Diketahui'),
            'country' => $this->translateCountry($c['country'] ?? 'Negara Tidak Diketahui'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build URL WeatherAPI dengan key & parameter tambahan.
     */
    private function buildUrl(string $endpoint, string $lokasi, array $extra = []): string
    {
        $apiKey  = config('services.weatherapi.key');
        $baseUrl = config('services.weatherapi.base_url');

        $params = array_merge(
            ['key' => $apiKey, 'q' => $lokasi, 'aqi' => 'no', 'alerts' => 'no'],
            $extra
        );

        return "{$baseUrl}/{$endpoint}?" . http_build_query($params);
    }

    /**
     * Estimasi peluang hujan dari data per jam berdasarkan waktu sekarang.
     */
    private function estimateRainChance(array $hourlyData): ?int
    {
        $flatten = [];
        foreach ($hourlyData as $perTanggal) {
            foreach ($perTanggal as $jam) {
                $flatten[] = $jam;
            }
        }

        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:00');

        foreach ($flatten as $jam) {
            $jamWaktu = \DateTime::createFromFormat(
                'Y-m-d H:i', $jam['time'],
                new \DateTimeZone('UTC')
            );
            if (!$jamWaktu) continue;

            $jamWaktu->setTimezone(new \DateTimeZone('Asia/Jakarta'));
            if ($jamWaktu->format('Y-m-d H:00') === $now) {
                return $jam['values']['precipitationProbability'] ?? null;
            }
        }

        return null;
    }

    private function translateCountry(string $country): string
    {
        $map = [
            'Indonesia'     => 'Indonesia',
            'United States' => 'Amerika Serikat',
            'Japan'         => 'Jepang',
            'Malaysia'      => 'Malaysia',
            'Singapore'     => 'Singapura',
            'Thailand'      => 'Thailand',
            'Philippines'   => 'Filipina',
        ];
        return $map[$country] ?? $country;
    }

    private function translateRegion(string $region): string
    {
        $map = [
            'East Java'    => 'Jawa Timur',
            'West Java'    => 'Jawa Barat',
            'Central Java' => 'Jawa Tengah',
            'Jakarta'      => 'DKI Jakarta',
        ];
        return $map[$region] ?? $region;
    }

    private function translateCity(string $city): string
    {
        $map = [
            'Kediri City' => 'Kota Kediri',
            'Surabaya'    => 'Surabaya',
            'Jakarta'     => 'Jakarta',
            'Bandung'     => 'Bandung',
        ];
        return $map[$city] ?? $city;
    }
}