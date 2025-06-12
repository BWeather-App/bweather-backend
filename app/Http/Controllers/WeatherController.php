<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WeatherController extends Controller
{
    public function getWeather(Request $request)
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');

        $apiKey = config('services.tomorrow.key');

        $response = Http::get("https://api.tomorrow.io/v4/weather/forecast", [
            'location' => "$lat,$lon",
            'apikey' => $apiKey
        ]);

        if ($response->successful()) {
            return response()->json($response->json());
        } else {
            return response()->json([
                'error' => 'Failed to fetch weather data',
                'details' => $response->body(),
            ], $response->status());
        }
    }

    public function getWeatherByGPS($lat, $lon, $silent = false)
    {
        $apiKey = config('services.tomorrow.key');
        $baseUrl = config('services.tomorrow.base_url');

        $response = Http::get("{$baseUrl}/forecast", [
            'location' => "$lat,$lon",
            'apikey' => $apiKey,
        ]);

        if ($response->failed()) {
            if ($silent) {
                return null;
            }

            return response()->json([
                'error' => 'Gagal mengambil data cuaca',
                'details' => $response->body(),
            ], $response->status());
        }

        return $response->json();
    }

}

