<?php
return [
    'igdb_client_id' => getenv('IGDB_CLIENT_ID') ?: '',
    'igdb_client_secret' => getenv('IGDB_CLIENT_SECRET') ?: '',
    // Empty = public. Otherwise every endpoint requires ?token=YOUR_TOKEN.
    'access_token' => '',
    // TMDB → Settings → API → API Read Access Token (recommended).
    'tmdb_token' => getenv('TMDB_TOKEN') ?: '',
    // Alternatively use the v3 API Key.
    'tmdb_api_key' => getenv('TMDB_API_KEY') ?: '',
    'language' => 'cs-CZ',
    'region' => 'CZ',
    'cache_seconds' => 1800,
];
