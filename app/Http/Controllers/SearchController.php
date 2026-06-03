<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
     * Fungsi: Ambil data cuaca lengkap 5 hari berdasarkan nama kota atau lat/lon.
     * Response format: {location, weather} — sama dengan /api/weather.
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
        $result = $weatherController->getWeatherByGPSManual($lat, $lon);

        return response()->json($result);
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
}
