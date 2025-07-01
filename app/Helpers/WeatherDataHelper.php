<?php

namespace App\Helpers;

use App\Helpers\TimeHelper;

class WeatherDataHelper
{
    public static function groupByDate(array $data): array
    {
        $kelompok = [];
        foreach ($data as $item) {
            if (!isset($item['time'])) continue;
            $tanggal = substr($item['time'], 0, 10);
            $kelompok[$tanggal][] = $item;
        }
        return $kelompok;
    }

    public static function mapHourlyData(array $data): array
    {
        $hasil = [];
        foreach ($data as $item) {
            $waktuWIB = TimeHelper::convertToWIB($item['time']);
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

    public static function gabungCuacaAstronomi(array $cuacaPerJam, array $astronomi): array
    {
        $hasil = [];
        foreach ($cuacaPerJam as $item) {
            $waktuWIB = TimeHelper::convertToWIB($item['time']);
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
}
