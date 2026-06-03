<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WeatherController;


Route::get('/ping', [SearchController::class, 'testApi']);

Route::middleware('api.key')->group(function () {
    Route::get('/search', [SearchController::class, 'searchByCityName']);
    Route::get('/suggestions', [SearchController::class, 'suggestLocations']);
    Route::get('/weather', [WeatherController::class, 'getWeatherByGPS']);
});
