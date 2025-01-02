<?php

return [
    'url' => $_ENV['REFINITIVE_URL'] ?? 'https://api.refinitiv.com/auth/oauth2/v1/token',
    'username' => $_ENV['REFINITIVE_USERNAME'] ?? 'GE-A-10171559-3-15155',
    'password' => $_ENV['REFINITIVE_PASSWORD'] ?? 'MuaIbrahim@MarketApp1010777789',
    'grant_type' => $_ENV['REFINITIVE_GRANT_TYPE'] ?? 'password',
    'scope' => $_ENV['REFINITIVE_SCOPE'] ?? 'trapi',
    'take_exclusive_sign_on_control' => $_ENV['REFINITIVE_TAKE_EXCLUSIVE_SIGN_ON_CONTROL'] ?? 'true',
    'client_id' => $_ENV['REFINITIVE_CLIENT_ID'] ?? '07f4cbaa345f49149f5afb74f3b497daa53dd4d8',
    'refresh_grant_type' => $_ENV['REFINITIVE_REFRESH_GRANT_TYPE'] ?? 'refresh_token',

    'search_light_url' => $_ENV['SEARCH_LIGHT_URL'] ?? 'https://api.refinitiv.com/discovery/searchlight/v1/',
    'historical_pricing_intraday_url' =>  $_ENV['HISTORICAL_PRICING_INTRADAY_URL'] ?? 'https://api.refinitiv.com/data/historical-pricing/v1/views/intraday-summaries/',
    'historical_pricing_interday_url' =>  $_ENV['HISTORICAL_PRICING_INTERDAY_URL'] ?? 'https://api.refinitiv.com/data/historical-pricing/v1/views/interday-summaries/',
    'pricing_snapshots_url' => $_ENV['PRICING_SNAPSHOTS_URL'] ?? 'https://api.refinitiv.com/data/pricing/snapshots/v1/',

    'time_to_refresh_token' => $_ENV['TIME_TO_REFRESH_TOKEN'] ?? 10,

    'ref_pricing_snapshot_url' => $_ENV['SW_REF_PRICING_SNAPSHOT_URL'] ?? 'https://api.refinitiv.com/data/pricing/snapshots/v1/',
    'ref_production_token_endpoint_key' => $_ENV['SW_REF_PRODUCTION_TOKEN_ENDPOINT_KEY'] ?? 'auqSGJ89Kp3DGJ*!%4',
    'ref_fields' => $_ENV['SW_REF_FIELDS'],
    'ref_chunk_size' => $_ENV['SW_REF_CHUNK_SIZE'],
];
