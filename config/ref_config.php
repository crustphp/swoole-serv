<?php

return [
    'username' => $_ENV['SW_REF_USERNAME'],
    'password' => $_ENV['SW_REF_PASSWORD'],
    'client_id' => $_ENV['SW_REF_CLIENT_ID'],
    'url' => $_ENV['SW_REF_TOKEN_URL'],
    'scope' => $_ENV['SW_REF_SCOPE'],
    'take_exclusive_sign_on_control' => $_ENV['SW_REF_TAKE_EXCLUSIVE_SIGN_ON_CONTROL'],
    'grant_type' => $_ENV['SW_REF_GRANT_TYPE'],
    'refresh_grant_type' => $_ENV['SW_REF_REFRESH_GRANT_TYPE'],
    'search_light_url' => $_ENV['SW_REF_SEARCH_LIGHT_URL'],
    'historical_pricing_intraday_url' =>  $_ENV['SW_REF_HISTORICAL_PRICING_INTRADAY_URL'],
    'historical_pricing_interday_url' =>  $_ENV['SW_REF_HISTORICAL_PRICING_INTERDAY_URL'],
    'pricing_snapshots_url' => $_ENV['SW_REF_PRICING_SNAPSHOT_URL'],

    'time_to_refresh_token' => $_ENV['SW_REF_TIME_TO_REFRESH_TOKEN'],

    'ref_pricing_snapshot_url' => $_ENV['SW_REF_PRICING_SNAPSHOT_URL'],
    'ref_production_token_endpoint_key' => $_ENV['SW_REF_PRODUCTION_TOKEN_ENDPOINT_KEY'],
    'ref_fields' => $_ENV['SW_REF_FIELDS'],
    'ref_chunk_size' => $_ENV['SW_REF_CHUNK_SIZE'],
];
