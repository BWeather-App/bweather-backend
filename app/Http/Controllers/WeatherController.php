<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DateTime;
use DateTimeZone;

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
        $realtime = $this->getRealtimeWeather($lokasi);

        $hariIni = date('Y-m-d');
        $besok = date('Y-m-d', strtotime('+1 day'));
        $lusa = date('Y-m-d', strtotime('+2 day'));
        $hariKe3 = date('Y-m-d', strtotime('+3 day'));

        $dataCuaca = [
            'cuaca_saat_ini' => $realtime,
            'kemarin' => $this->mapHourlyData($kemarin),
            'hari_ini' => $this->gabungCuacaAstronomi($perJam[$hariIni] ?? [], $astronomi[$hariIni] ?? []),
            'besok' => $this->gabungCuacaAstronomi($perJam[$besok] ?? [], $astronomi[$besok] ?? []),
            'lusa' => $this->gabungCuacaAstronomi($perJam[$lusa] ?? [], $astronomi[$lusa] ?? []),
            'hari_ke_3' => $this->gabungCuacaAstronomi($perJam[$hariKe3] ?? [], $astronomi[$hariKe3] ?? []),
        ];

        $name = $this->getPlaceNameFromLatLon($latitude, $longitude);
        $locationInfo = [
            'lat' => floatval($latitude),
            'lon' => floatval($longitude),
            'name' => $name,
        ];

        $result = [
            'location' => $locationInfo,
            'weather' => $dataCuaca,
        ];

        if ($returnData) return $result;

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    private function getRealtimeWeather($lokasi)
    {
        $url = config('services.tomorrow.base_url') . "/realtime?location=" . urlencode($lokasi) . "&apikey=" . config('services.tomorrow.key');
        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['data']['values'])) return ['kesalahan' => 'Data realtime tidak ditemukan'];

        $v = $data['data']['values'];
        $utc = $data['data']['time'];
        $wib = $this->convertToWIB($utc);

        return [
            'waktu' => $wib,
            'suhu' => $v['temperature'] ?? null,
            'kelembapan' => $v['humidity'] ?? null,
            'kecepatan_angin' => $v['windSpeed'] ?? null,
            'arah_angin' => $v['windDirection'] ?? null,
            'tekanan_udara' => $v['pressureSurfaceLevel'] ?? null,
            'indeks_uv' => $v['uvIndex'] ?? null,
            'terasa_seperti' => $v['temperatureApparent'] ?? null,
            'peluang_hujan' => $v['precipitationProbability'] ?? null,
        ];
    }

    private function getHourlyForecast($lokasi)
    {
        $url = config('services.tomorrow.base_url') . "/forecast?location=" . urlencode($lokasi) . "&apikey=" . config('services.tomorrow.key');
        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['timelines']['hourly'])) return [];
        return $this->groupByDate($data['timelines']['hourly']);
    }

    private function getYesterdayWeather($lokasi)
    {
        $url = config('services.tomorrow.base_url') . "/history/recent?location=" . urlencode($lokasi) . "&apikey=" . config('services.tomorrow.key');
        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        if (!isset($data['timelines']['hourly'])) return [];
        $tanggal = date('Y-m-d', strtotime('-1 day'));
        $kelompok = $this->groupByDate($data['timelines']['hourly']);
        return $kelompok[$tanggal] ?? [];
    }

    private function getDailyAstronomy($lokasi)
    {
        $url = config('services.tomorrow.base_url') . "/forecast?location=" . urlencode($lokasi) . "&apikey=" . config('services.tomorrow.key');
        $response = $this->fetchData($url);
        $data = json_decode($response, true);

        $hasil = [];
        if (isset($data['timelines']['daily'])) {
            foreach ($data['timelines']['daily'] as $item) {
                $tanggal = substr($item['time'], 0, 10);
                $v = $item['values'];
                $hasil[$tanggal] = [
                    'matahari_terbit' => $this->convertToWIB($v['sunriseTime'] ?? null),
                    'matahari_terbenam' => $this->convertToWIB($v['sunsetTime'] ?? null),
                    'bulan_terbit' => $this->convertToWIB($v['moonriseTime'] ?? null),
                    'bulan_terbenam' => $this->convertToWIB($v['moonsetTime'] ?? null),
                ];
            }
        }

        return $hasil;
    }

    private function gabungCuacaAstronomi($cuacaPerJam, $astronomi)
    {
        $hasil = [];
        foreach ($cuacaPerJam as $item) {
            $waktuWIB = $this->convertToWIB($item['time']);
            $v = $item['values'];

            $hasil[] = [
                'waktu' => $waktuWIB,
                'suhu' => $v['temperature'] ?? null,
                'kelembapan' => $v['humidity'] ?? null,
                'kecepatan_angin' => $v['windSpeed'] ?? null,
                'arah_angin' => $v['windDirection'] ?? null,
                'tekanan_udara' => $v['pressureSurfaceLevel'] ?? null,
                'indeks_uv' => $v['uvIndex'] ?? null,
                'terasa_seperti' => $v['temperatureApparent'] ?? null,
                'peluang_hujan' => $v['precipitationProbability'] ?? null,
                'matahari_terbit' => $astronomi['matahari_terbit'] ?? null,
                'matahari_terbenam' => $astronomi['matahari_terbenam'] ?? null,
                'bulan_terbit' => $astronomi['bulan_terbit'] ?? null,
                'bulan_terbenam' => $astronomi['bulan_terbenam'] ?? null,
            ];
        }
        return $hasil;
    }

    private function mapHourlyData($data)
    {
        $hasil = [];
        foreach ($data as $item) {
            $waktuWIB = $this->convertToWIB($item['time']);
            $v = $item['values'];
            $hasil[] = [
                'waktu' => $waktuWIB,
                'suhu' => $v['temperature'] ?? null,
                'kelembapan' => $v['humidity'] ?? null,
                'kecepatan_angin' => $v['windSpeed'] ?? null,
                'arah_angin' => $v['windDirection'] ?? null,
                'tekanan_udara' => $v['pressureSurfaceLevel'] ?? null,
                'indeks_uv' => $v['uvIndex'] ?? null,
                'terasa_seperti' => $v['temperatureApparent'] ?? null,
                'peluang_hujan' => $v['precipitationProbability'] ?? null,
            ];
        }
        return $hasil;
    }

    private function convertToWIB($utc)
    {
        if (!$utc) return null;
        $dt = new DateTime($utc);
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('Y-m-d H:i:s');
    }

    private function groupByDate($data)
    {
        $kelompok = [];
        foreach ($data as $item) {
            if (!isset($item['time'])) continue;
            $tanggal = substr($item['time'], 0, 10);
            $kelompok[$tanggal][] = $item;
        }
        return $kelompok;
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

            $cityOrRegency = $components['city']
                ?? $components['municipality']
                ?? $components['town']
                ?? $components['county']
                ?? $components['region']
                ?? null;

            $province = $components['state'] ?? null;

            if ($cityOrRegency && $province) {
                return "$cityOrRegency, $province";
            }
        }

        return 'Lokasi Tidak Dikenal';
    }
}
