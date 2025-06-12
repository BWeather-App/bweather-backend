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

    public function searchByCityName(Request $request)
    {
        $query = $request->query('query');
        $location = urlencode($query);

        $baseUrl = config('services.tomorrow.base_url');
        $apiKey = config('services.tomorrow.key');

        $url = "{$baseUrl}/forecast?location={$location}&apikey={$apiKey}";

        $response = Http::withOptions(['http_errors' => false])->get($url);

        if ($response->status() === 429) {
            return response()->json(['error' => 'API kamu limit brok, bayar dulu sana 😤'], 429);
        }

        $data = $response->json();

        if (!isset($data['location']['lat']) || !isset($data['location']['lon'])) {
            return response()->json(['error' => 'Kota tidak ditemukan'], 404);
        }

        $latitude = $data['location']['lat'];
        $longitude = $data['location']['lon'];
        $name = $data['location']['name'];

        // Panggil WeatherController
        $weatherController = new WeatherController();
        $weatherData = $weatherController->getWeatherByGPS($latitude, $longitude, true); // mode silent

        $result = [
            'location' => [
                'lat' => $latitude,
                'lon' => $longitude,
                'name' => $name
            ],
            'weather' => $weatherData
        ];

        return response()->json($result);
    }
}
