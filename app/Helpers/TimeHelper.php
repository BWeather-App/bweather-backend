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
        $dt = DateTime::createFromFormat('Y-m-d h:i A', $dateTimeString, new DateTimeZone('UTC'));

        if (!$dt) return null;

        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('Y-m-d H:i:s');
    }
}
