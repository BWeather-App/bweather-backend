<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Helpers\WeatherDataHelper;
use App\Helpers\TimeHelper; 

class WeatherController extends Controller
{
    public function getWeatherByGPS(Request $request)
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');

        if (!$lat || !$lon) {
            return response()->json(['error' => 'Latitude dan longitude wajib diisi'], 400);
        }

        $result = $this->getWeatherByGPSManual($lat, $lon, true);

        return response()->json($result);
    }

    public function getWeatherByGPSManual($latitude, $longitude, $returnData = false)
    {
        $lokasi = $latitude . "," . $longitude;

        $perJam = $this->getHourlyForecast($lokasi);
        $astronomi = $this->getDailyAstronomy($lokasi);
        $kemarin = $this->getYesterdayWeather($lokasi);
        $realtime = $this->getRealtimeWeather($lokasi, $perJam);
        
        $hariIni = date('Y-m-d');
        $besok = date('Y-m-d', strtotime('+1 day'));
        $lusa = date('Y-m-d', strtotime('+2 day'));
        $hariKe3 = date('Y-m-d', strtotime('+3 day'));

        $dataCuaca = [
            'cuaca_saat_ini' => $realtime,
            'kemarin' => WeatherDataHelper::mapHourlyData($kemarin),
            'hari_ini' => WeatherDataHelper::gabungCuacaAstronomi($perJam[$hariIni] ?? [], $astronomi[$hariIni] ?? []),
            'besok' => WeatherDataHelper::gabungCuacaAstronomi ($perJam[$besok] ?? [], $astronomi[$besok] ?? []),
            'lusa' => WeatherDataHelper::gabungCuacaAstronomi ($perJam[$lusa] ?? [], $astronomi[$lusa] ?? []),
            'hari_ke_3' => WeatherDataHelper::gabungCuacaAstronomi ($perJam[$hariKe3] ?? [], $astronomi[$hariKe3] ?? []),
        ];

        $lokasi = $this->getPlaceNameFromLatLon($latitude, $longitude);
        $locationInfo = array_merge([
            'lat' => floatval($latitude),
            'lon' => floatval($longitude),
        ], $lokasi);


        $result = [
            'location' => $locationInfo,
            'weather' => $dataCuaca,
        ];

        if ($returnData) return $result;

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    private function getRealtimeWeather($lokasi,$hourlyData = [])
    {
        $apiKey = config('services.weatherapi.key');
        $baseUrl = config('services.weatherapi.base_url');
        $url = "{$baseUrl}/current.json?key={$apiKey}&q=" . urlencode($lokasi);

        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['current'])) return ['kesalahan' => 'Data realtime tidak ditemukan'];

        $v = $data['current'];
        $wib = $v['last_updated'] ?? null; // sudah WIB

         // Estimasi peluang hujan dari forecast
        $peluangHujan = null;
        $flatten = [];
            foreach ($hourlyData as $perTanggal) {
                foreach ($perTanggal as $jam) {
                    $flatten[] = $jam;
                }
            }
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:00');

        foreach ($flatten as $jam) {
            $jamWaktu = \DateTime::createFromFormat('Y-m-d H:i', $jam['time'], new \DateTimeZone('UTC'));
            if ($jamWaktu) {
                $jamWaktu->setTimezone(new \DateTimeZone('Asia/Jakarta'));
                $jamFormatted = $jamWaktu->format('Y-m-d H:00');

                if ($jamFormatted === $now) {
                    $peluangHujan = $jam['values']['precipitationProbability'] ?? null;
                    break;
                }
            }
        }

        
        return [
            'waktu' => $wib,
            'suhu' => $v['temp_c'] ?? null,
            'kelembapan' => $v['humidity'] ?? null,
            'kecepatan_angin' => $v['wind_kph'] ?? null,
            'arah_angin' => $v['wind_degree'] ?? null,
            'tekanan_udara' => $v['pressure_mb'] ?? null,
            'indeks_uv' => $v['uv'] ?? null,
            'terasa_seperti' => $v['feelslike_c'] ?? null,
            'peluang_hujan' => $peluangHujan, // tidak tersedia di current.json
        ];
    }

    private function getHourlyForecast($lokasi)
    {
        $apiKey = config('services.weatherapi.key');
        $baseUrl = config('services.weatherapi.base_url');
        $url = "{$baseUrl}/forecast.json?key={$apiKey}&q=" . urlencode($lokasi) . "&days=5&aqi=no&alerts=no";


        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['forecast']['forecastday'])) return [];

        $hasil = [];

        foreach ($data['forecast']['forecastday'] as $hari) {
            foreach ($hari['hour'] as $jam) {
                $hasil[] = [
                    'time' => $jam['time'],
                    'values' => [
                        'temperature' => $jam['temp_c'] ?? null,
                        'humidity' => $jam['humidity'] ?? null,
                        'windSpeed' => $jam['wind_kph'] ?? null,
                        'windDirection' => $jam['wind_degree'] ?? null,
                        'pressureSurfaceLevel' => $jam['pressure_mb'] ?? null,
                        'uvIndex' => $jam['uv'] ?? null,
                        'temperatureApparent' => $jam['feelslike_c'] ?? null,
                        'precipitationProbability' => $jam['chance_of_rain'] ?? null,
                    ]
                ];
            }
        }

        return WeatherDataHelper::groupByDate($hasil);
    }

    private function getYesterdayWeather($lokasi)
    {
        $apiKey = config('services.weatherapi.key');
        $baseUrl = config('services.weatherapi.base_url');
        $tanggalKemarin = date('Y-m-d', strtotime('-1 day'));
        $url = "{$baseUrl}/history.json?key={$apiKey}&q=" . urlencode($lokasi) . "&dt={$tanggalKemarin}";

        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['forecast']['forecastday'][0]['hour'])) return [];

        $hasil = [];
        foreach ($data['forecast']['forecastday'][0]['hour'] as $jam) {
            $hasil[] = [
                'time' => $jam['time'],
                'values' => [
                    'temperature' => $jam['temp_c'] ?? null,
                    'humidity' => $jam['humidity'] ?? null,
                    'windSpeed' => $jam['wind_kph'] ?? null,
                    'windDirection' => $jam['wind_degree'] ?? null,
                    'pressureSurfaceLevel' => $jam['pressure_mb'] ?? null,
                    'uvIndex' => $jam['uv'] ?? null,
                    'temperatureApparent' => $jam['feelslike_c'] ?? null,
                    'precipitationProbability' => $jam['chance_of_rain'] ?? null,
                ]
            ];
        }

        return $hasil;
    }

    private function getDailyAstronomy($lokasi)
    {
            $apiKey = config('services.weatherapi.key');
            $baseUrl = config('services.weatherapi.base_url');
            $url = "{$baseUrl}/forecast.json?key={$apiKey}&q=" . urlencode($lokasi) . "&days=5&aqi=no&alerts=no";

            $response = $this->fetchData($url);
            $data = json_decode($response, true);

            $hasil = [];

            if (isset($data['forecast']['forecastday'])) {
                foreach ($data['forecast']['forecastday'] as $day) {
                    $tanggal = $day['date'] ?? null;
                    $astro = $day['astro'] ?? [];

                    $hasil[$tanggal] = [
                        'matahari_terbit' => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['sunrise'] ?? null),
                        'matahari_terbenam' => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['sunset'] ?? null),
                        'bulan_terbit' => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['moonrise'] ?? null),
                        'bulan_terbenam' => TimeHelper::convertToWIBFromTimeOnly($tanggal, $astro['moonset'] ?? null),
                    ];
                }
            }

            return $hasil;
    }

    private function fetchData($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $header_size);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 429) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'API kamu limit brok, bayar dulu sana 😤']);
            exit;
        }

        return $body;
    }

    private function getPlaceNameFromLatLon($lat, $lon)
    {
        $apiKey = env('GEOCODING_API_KEY');
        $url = "https://api.opencagedata.com/geocode/v1/json?q=$lat+$lon&key=$apiKey";

        $res = Http::get($url)->body();
        $data = json_decode($res, true);

        if (isset($data['results'][0]['components'])) {
            $components = $data['results'][0]['components'];

            $city = $components['city']
                ?? $components['municipality']
                ?? $components['town']
                ?? $components['county']
                ?? $components['region']
                ?? null;

            $region = $components['state'] ?? null;
            $country = $components['country'] ?? null;

            return [
                'city' => $this->translateCity($city ?? 'Kota Tidak Diketahui'),
                'region' => $this->translateRegion($region ?? 'Wilayah Tidak Diketahui'),
                'country' => $this->translateCountry($country ?? 'Negara Tidak Diketahui'),
            ];
        }

        return [
            'city' => 'Tidak Diketahui',
            'region' => '',
            'country' => ''
        ];
    }

    private function translateCountry($country)
    {
        $map = [
            'Indonesia' => 'Indonesia',
            'United States' => 'Amerika Serikat',
            'Japan' => 'Jepang',
            'Malaysia' => 'Malaysia',
            'Singapore' => 'Singapura',
            'Thailand' => 'Thailand',
            'Philippines' => 'Filipina',
            // Tambahkan sesuai kebutuhan
        ];

        return $map[$country] ?? $country;
    }

    private function translateRegion($region)
    {
        // Contoh konversi jika ingin nama provinsi disesuaikan
        $map = [
            'East Java' => 'Jawa Timur',
            'West Java' => 'Jawa Barat',
            'Central Java' => 'Jawa Tengah',
            'Jakarta' => 'DKI Jakarta',
            // Tambah jika perlu
        ];

        return $map[$region] ?? $region;
    }

    private function translateCity($city)
    {
        $map = [
            'Kediri City' => 'Kota Kediri',
            'Surabaya' => 'Surabaya',
            'Jakarta' => 'Jakarta',
            'Bandung' => 'Bandung',
            // Tambah jika perlu
        ];

        return $map[$city] ?? $city;
    }

}
