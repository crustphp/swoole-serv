<?php

return [
    'spglobal_ftp_url' => $_ENV['SPGLOBAL_FTP_URL'] ?? 'sftp2.spglobal.com',
    'spglobal_username' => $_ENV['SPGLOBAL_USERNAME'] ?? 'Muas2321PC',
    'spglobal_password' => $_ENV['SPGLOBAL_PASSWORD'] ?? '1j1c4Z$05',
    'sp_data_fetching_timespan' => intval($_ENV['SW_SP_DATA_FETCHING_TIMESPAN'] ?? 30),
    'sp_chunck_size' => intval($_ENV['SW_SP_CHUNK_SIZE'] ?? 50),
    'sp_fields' => $_ENV['SW_SP_FIELDS'] ?? 'IQ_VOLUME,IQ_FLOAT',
    'sp_global_api_uri' => $_ENV['SW_SP_GLOBAL_API_URI'] ?? 'https://api-ciq.marketintelligence.spglobal.com/gdsapi/rest/v3',
    'sp_global_api_user' => $_ENV['SW_SP_GLOBAL_API_USER'],
    'sp_global_api_secret' => $_ENV['SW_SP_GLOBAL_API_SECRET'],
    'sp_drived_fields' => $_ENV['SW_SP_DRIVED_FIELDS'] ?? 'sp_turnover',
];
