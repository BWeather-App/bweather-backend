<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WeatherController;
use App\Http\Controllers\NotificationController;


Route::get('/search', [SearchController::class, 'searchByCityName']);
Route::get('/suggestions', [SearchController::class, 'suggestLocations']);
Route::get('/weather', [WeatherController::class, 'getWeatherByGPS']);
Route::get('/ping', [SearchController::class, 'testApi']);
Route::post('/send-notification', [NotificationController::class, 'send']);
