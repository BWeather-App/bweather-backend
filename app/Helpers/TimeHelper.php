<?php

namespace App\Helpers;

use DateTime;
use DateTimeZone;

class TimeHelper
{
    public static function convertToWIB($utc)
    {
        if (!$utc) return null;
        $dt = new DateTime($utc);
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('Y-m-d H:i:s');
    }

 public static function convertToWIBFromTimeOnly($date, $timeString)
{
    if (!$date || !$timeString) return null;

    $dateTimeString = $date . ' ' . $timeString;

    // ⛳️ PARSING dengan TIMEZONE Asia/Jakarta
    $dt = DateTime::createFromFormat(
        'Y-m-d h:i A',
        $dateTimeString,
        new DateTimeZone('Asia/Jakarta') // <— waktu lokal sesuai data WeatherAPI
    );

    if (!$dt) return null;

    // Tidak perlu ubah timezone lagi, sudah WIB
    return $dt->format('Y-m-d H:i:s');
}


}
