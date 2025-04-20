<?php

namespace App\Constants;

class LogMessages {
    // -------------------- Refinitiv Messages ------------------------- \\
    // Company
    public const ADD_MARKET_TO_REF_UNIVERSE = 'Please add the market name to the refinitiv_universe column in the markets table of the database.';
    public const REFINITIV_NO_DATA = 'Refinitiv API is not returning %s at this time for %s companies.';
    public const CREATE_SWOOLE_TABLE = "Please create a Swoole table to save data of %s's companies.";
    public const NO_MARKET_IN_DB = "There is no market that exists in the database.";
    public const REFINITIV_MISSING_INDICATORS = 'Refinitiv API Issue: "Indicators" missing for the company in the pricing snapshot API. Response from Refinitiv: %s';
    public const REF_SAVE_SWOOLE_TABLE = 'Save data in the Swoole table: %s';
    public const INITIALIZE_REFINDICATORS = "Initialize fresh data from Refinitiv for indicators.";
    public const UPDATE_DB_TABLE = "Update data in the database table: %s";
    public const SAVE_REF_SNAPSHOT = "Save Refinitiv snapshot data in the database table: %s";
    public const LOADING_SWOOLE_TABLE = "Loading data into the Swoole table: %s.";
    public const NO_REF_COMPANY_DATA = "There is no Refinitiv company data in the database.";
    public const OLDER_DATA_EXISTS = "There is data older than %s seconds.";
    public const RECORD_WITHIN_TIMESPAN = "The record is within the last %s seconds. Data is prepared.";
    public const REF_TOKEN_COMPANY_ISSUE = "There is an issue retrieving the token or the responsible company does not exist in the database.";
    public const REF_UNAUTHORIZED_ACCESS = "Swoole-serv: Unauthorized: Invalid token or session has expired in the Refinitiv Process.";
    public const REF_UNAUTHORIZED_RETRY_LIMIT = "Swoole-serv: Unauthorized: Retry limit reached in the Refinitiv process.";
    public const REF_TOO_MANY_REQUESTS = "Swoole-serv: Too Many Requests: Request failed in the Refinitiv process.";
    public const REF_TOO_MANY_REQUESTS_RETRY_LIMIT = "Swoole-serv: Too Many Requests: Retry limit reached in the Refinitiv process.";
    public const REF_INVALID_RESPONSE = "Swoole-Serv: Refinitiv API Error – Received an invalid response in the pricing snapshot API. Response from Refinitiv: %s";
    public const REF_OVERALL_RETRIES_LIMIT_EXCEEDED = "Swoole-serv: Overall retries limit exceeded in the Refinitiv process.";

    // Market
    public const NO_REF_MARKET_DATA = "There is no Refinitiv market data in the database.";
    public const REFINITIV_NO_MARKET_DATA = 'Refinitiv API is not returning %s at this time for markets.';
    public const REFINITIV_MISSING_MARKET_INDICATORS = 'Refinitiv API Issue: "Indicators" missing for the market in the pricing snapshot API. Response from Refinitiv: %s';
    public const INITIALIZE_MARKET_REFINDICATORS = "Initialize fresh data from Refinitiv for market's indicators.";
    public const SAVE_REF_MARKET_SNAPSHOT = "Save Refinitiv snapshot market data in the database table: %s";
    public const REF_MARKET_RECORD_WITHIN_TIMESPAN = "The market record is within the last %s seconds. Data is prepared.";
    public const REF_MARKET_OLDER_DATA_EXISTS = "There is market's data older than %s seconds.";

    // Sector
    public const NO_REF_SECTOR_DATA = "There is no Refinitiv sector data in the database.";
    public const REFINITIV_NO_SECTOR_DATA = 'Refinitiv API is not returning %s at this time for sectors.';
    public const REFINITIV_MISSING_SECTOR_INDICATORS = 'Refinitiv API Issue: "Indicators" missing for the sector in the pricing snapshot API. Response from Refinitiv: %s';
    public const INITIALIZE_SECTOR_REFINDICATORS = "Initialize fresh data from Refinitiv for sector's indicators.";
    public const SAVE_REF_SECTOR_SNAPSHOT = "Save Refinitiv snapshot sector data in the database table: %s";
    public const REF_SECTOR_RECORD_WITHIN_TIMESPAN = "The sector record is within the last %s seconds. Data is prepared.";
    public const REF_SECTOR_OLDER_DATA_EXISTS = "There is sector's data older than %s seconds.";
}
