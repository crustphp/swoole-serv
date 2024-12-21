<?php

return [
    'url' => $_ENV['REFINITIVE_URL'] ?? '',
    'username' => $_ENV['REFINITIVE_USERNAME'] ?? '',
    'password' => $_ENV['REFINITIVE_PASSWORD'] ?? '',
    'grant_type' => $_ENV['REFINITIVE_GRANT_TYPE'] ?? '',
    'scope' => $_ENV['REFINITIVE_SCOPE'] ?? '',
    'take_exclusive_sign_on_control' => $_ENV['REFINITIVE_TAKE_EXCLUSIVE_SIGN_ON_CONTROL'] ?? '',
    'client_id' => $_ENV['REFINITIVE_CLIENT_ID'] ?? '',
    'refresh_grant_type' => $_ENV['REFINITIVE_REFRESH_GRANT_TYPE'] ?? '',

    'search_light_url' => $_ENV['SEARCH_LIGHT_URL'] ?? '',
    'historical_pricing_intraday_url' =>  $_ENV['HISTORICAL_PRICING_INTRADAY_URL'] ?? '',
    'historical_pricing_interday_url' =>  $_ENV['HISTORICAL_PRICING_INTERDAY_URL'] ?? '',
    'pricing_snapshots_url' => $_ENV['PRICING_SNAPSHOTS_URL'] ?? '',

    'time_to_refresh_token' => $_ENV['TIME_TO_REFRESH_TOKEN'] ?? 10,

];
