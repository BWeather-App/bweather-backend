# BWeather API

<p align="center">
  <b>Laravel 12 · WeatherAPI · OpenCage</b><br>
  Backend proxy for the BWeather Android app
</p>

Lightweight Laravel API that proxies requests to WeatherAPI and OpenCage Geocoding, with built-in caching and rate limiting.

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/weather?lat=&lon=` | 5-day weather data by GPS coordinates |
| `GET` | `/api/search?query=` | Search weather by city name |
| `GET` | `/api/suggestions?query=` | Autocomplete city suggestions (Indonesia) |
| `GET` | `/api/ping` | Health check |

### Response Format

```json
{
  "location": { "city": "Surabaya", "region": "Jawa Timur", "country": "Indonesia", "lat": -7.25, "lon": 112.75 },
  "weather": {
    "cuaca_saat_ini": { "suhu": 32, "kelembapan": 68, ... },
    "kemarin": [ ... ],
    "hari_ini": [ ... ],
    "besok": [ ... ],
    "lusa": [ ... ],
    "hari_ke_3": [ ... ]
  }
}
```

## Tech Stack

| Technology | Purpose |
|------------|---------|
| Laravel 12 | API framework |
| WeatherAPI | Weather data (current, forecast, history) |
| OpenCage | Reverse geocoding & city search |
| File Cache | 30-min coordinate-based caching |
| Rate Limiter | 60 req/min per IP |

## Getting Started

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Fill in: WEATHERAPI_API_KEY, GEOCODING_API_KEY

# 3. Generate app key
php artisan key:generate

# 4. Run
php artisan serve --host=0.0.0.0 --port=8000
```

## Environment Variables

```
WEATHERAPI_API_KEY=      # https://www.weatherapi.com/
WEATHERAPI_BASE_URL=     # https://api.weatherapi.com/v1
GEOCODING_API_KEY=       # https://opencagedata.com/
WEATHER_CACHE_TTL=30     # Cache duration in minutes
```

## Deploy (Render)

```bash
# 1. Push to GitHub
# 2. Create Web Service on render.com → Docker
# 3. Set environment variables in Render dashboard
# 4. Deploy
```

## License

MIT
