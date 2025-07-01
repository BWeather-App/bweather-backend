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

        $apiKey = env('GEOCODING_API_KEY');
        $url = "https://api.opencagedata.com/geocode/v1/json?q=" . urlencode($query) . "&key={$apiKey}&limit=1";

        $res = Http::get($url);
        $data = $res->json();

        if (!isset($data['results'][0]['geometry'])) {
            return response()->json(['error' => 'Lokasi tidak ditemukan'], 404);
        }

        $lat = $data['results'][0]['geometry']['lat'];
        $lon = $data['results'][0]['geometry']['lng'];
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
        $url = "https://api.opencagedata.com/geocode/v1/json?q=" . urlencode($query) . "&key={$apiKey}&limit=10&countrycode=id&language=id";


        $response = Http::get($url);
        $data = $response->json();

        if (!isset($data['results']) || empty($data['results'])) {
            return response()->json(['error' => 'Tidak ditemukan hasil'], 404);
        }

        $results = [];
        $seen = []; // untuk menghindari duplikat

        foreach ($data['results'] as $result) {
            $components = $result['components'];
            $city = $components['city'] 
                ?? $components['town'] 
                ?? $components['village'] 
                ?? $components['county'] 
                ?? null;

            $subregion = $components['county'] ?? ''; // misalnya Kabupaten
            $region = $components['state'] ?? ''; // Jawa Timur
            $country = $components['country'] ?? '';

            if (!$city) continue;

            // Buat nama lengkap lokasi
            $fullName = trim("{$city}, {$subregion}, {$region}");
            $uniqueKey = strtolower($fullName);

            // Hindari duplikat berdasarkan full name
            if (in_array($uniqueKey, $seen)) continue;
            $seen[] = $uniqueKey;

            $results[] = [
                'name' => $city,
                'full' => $fullName,
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
