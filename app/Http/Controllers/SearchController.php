<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public function testApi()
    {
        return response()->json([
            'message' => 'API cuaca aktif dan siap dipakai 🚀',
            'status' => 'success'
        ]);
    }

    /**
     * Endpoint: GET /api/search
     * Fungsi: Ambil cuaca 5 hari berdasarkan query atau lat/lon
     */
   public function searchByCityName(Request $request)
{
    header('Access-Control-Allow-Origin: *');

    $lat = $request->query('lat');
    $lon = $request->query('lon');
    $query = $request->query('query');

    if (!$lat || !$lon) {
        if (!$query) {
            return response()->json(['error' => 'Parameter query atau lat/lon dibutuhkan'], 400);
        }

        $baseUrl = config('services.tomorrow.base_url');
        $apiKey = config('services.tomorrow.key');
        $location = urlencode($query);

        $url = "{$baseUrl}/realtime?location={$location}&apikey={$apiKey}";
        $response = Http::withOptions(['http_errors' => false])->get($url);

        if ($response->status() === 429) {
            return response()->json(['error' => 'API limit tercapai.'], 429);
        }

        $data = $response->json();

        if (!isset($data['location']['lat']) || !isset($data['location']['lon'])) {
            return response()->json(['error' => 'Lokasi tidak ditemukan'], 404);
        }

        $lat = $data['location']['lat'];
        $lon = $data['location']['lon'];
    }

    $weatherController = new WeatherController();
    $result = $weatherController->getWeatherByGPSManual($lat, $lon, true);

    $weather = $result['weather'];
    $locationName = $result['location']['name'] ?? 'Lokasi';

    $forecasts = [];

    // Kemarin
    $yesterday = $weather['kemarin'][0] ?? null;
    if ($yesterday) {
        $forecasts[] = [
            'label' => 'Kemarin',
            'tanggal' => Carbon::parse($yesterday['waktu'])->format('d/m'),
            'ikon' => $this->mapWeatherIcon($yesterday),
            'suhu' => round($yesterday['suhu'] ?? 0),
        ];
    }

    // Hari ini hingga 4 hari ke depan
    $labelMap = ['Hari Ini', 'Besok'];
    $keys = ['hari_ini', 'besok','lusa' ,'hari_ke_3'];

    foreach ($keys as $i => $key) {
        $cuaca = $weather[$key][0] ?? null;
        if (!$cuaca) continue;

        $tanggal = Carbon::parse($cuaca['waktu']);
        $label = $labelMap[$i] ?? Str::ucfirst($tanggal->translatedFormat('l'));

   $forecasts[] = [
    'label' => $label,
    'tanggal' => $tanggal->format('d/m'),
    'ikon' => $this->mapWeatherIcon($cuaca),
    'suhu' => round($cuaca['suhu'] ?? $cuaca['max'] ?? $cuaca['min'] ?? 0),
];

    }

    return response()->json([
        'city' => $locationName,
        'forecast' => $forecasts
    ]);
}


    /**
     * Endpoint: GET /api/suggest
     * Fungsi: Memberikan saran lokasi dari geocoding
     */
    public function suggestLocations(Request $request)
    {
        $query = $request->query('query');
        if (!$query) {
            return response()->json(['error' => 'Parameter query tidak ditemukan'], 400);
        }

        $apiKey = env('GEOCODING_API_KEY');
        $url = "https://api.opencagedata.com/geocode/v1/json?q=" . urlencode($query) . "&key={$apiKey}&limit=5&countrycode=id";

        $response = Http::get($url);
        $data = $response->json();

        if (!isset($data['results']) || empty($data['results'])) {
            return response()->json(['error' => 'Tidak ditemukan hasil'], 404);
        }

        $results = [];
        foreach ($data['results'] as $result) {
            $components = $result['components'];
            $city = $components['city']
                ?? $components['town']
                ?? $components['village']
                ?? $components['county']
                ?? null;

            $region = $components['state'] ?? '';
            $country = $components['country'] ?? '';

            if (!$city) continue;

            $results[] = [
                'name' => $city,
                'full' => "{$region}, {$country}",
                'lat' => $result['geometry']['lat'],
                'lon' => $result['geometry']['lng'],
            ];
        }

        return response()->json($results);
    }

    /**
     * Fungsi bantu: Ubah cuaca ke nama ikon
     */
    private function mapWeatherIcon($cuaca)
    {
        $v = strtolower($cuaca['cuaca'] ?? '');

        if (str_contains($v, 'rain')) return 'rain';
        if (str_contains($v, 'cloud')) return 'cloudy';
        if (str_contains($v, 'sun') || str_contains($v, 'clear')) return 'sunny';

        return 'cloudy'; // default
    }
}
