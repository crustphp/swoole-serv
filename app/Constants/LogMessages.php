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

    // Websocket Commands Message
    public const MISSING_TASI_OR_NOMUC_INDICATORS_SWOOLE_TABLE = "Could not find Swoole table for Tasi or Nomuc companies indicators data";

    // Refinitiv Historical
    public const REF_HISTORICAL_NO_MODULE_DATA_PROVIDED = "No module data has been provided to fetch data for the %s indicators Historical.";
    public const REF_HISTORICAL_DATA_ON_LAST_WORKING_DAY = "Fetching Refinitiv %s historical data of last working day.";
    public const REF_HISTORICAL_DATA_OF_PREVIOUS_DAY = "Fetching Refinitiv %s historical data of previous day between %s and %s.";
    public const REF_HISTORICAL_DATA_OF_TODAY = "Fetching Refinitiv %s historical data of today.";
    public const REF_HISTORICAL_DATA_NOT_FETCHING = "Not fetching Refinitiv %s historical data between %s and %s, as the table is truncated during this period and an empty response is returned.";
    public const REF_HISTORICAL_TRUNCATE_TABLES = "Truncating historical %s data from both Swoole and database tables.";
    public const REF_HISTORICAL_DATA_NOT_FETCHING_MARKET_CLOSED = "Not fetching Refinitiv %s historical data because the market is closed.";
    public const REF_HISTORICAL_INITIALIZE = "Initialize fresh historical data from Refinitiv for %s indicators.";
    public const REFINITIV_HISTORICAL_MISSING_INDICATORS = "Refinitiv Historical API Issue: 'Indicators' missing for the %s in the Historical API. Response from Refinitiv: %s.";
    public const REFINITIV_HISTORICAL_API_NOT_SENDING_DATA = "Refinitiv Historical API is not currently sending any %s data.";
    public const DATA_NOT_FETCHING_MARKET_CLOSED_DAYS = "Not fetching Refinitiv %s historical data on weekend or holiday.";
    public const REF_HISTORICAL_TRUNCATE_SWOOLE_TABLE_INIT_CASE = "Truncating historical %s data from Swoole table on init case.";
    public const REF_HISTORICAL_TOTAL_RECORDS = "A total of %s historical %s data records were fetched.";
    public const REF_HISTORICAL_PROCESS_INITIATE_FETCH_DATA = "Refinitiv historical process initiate to fetched for %s data.";
    public const REFINITIV_HISTORICAL_NO_DATA = 'Refinitiv Historical API is not returning %s at this time for %s.';

    public const REF_HISTORICAL_UNAUTHORIZED_ACCESS = "Swoole-serv: Unauthorized: Invalid token or session has expired in the Refinitiv Historical Process.";
    public const REF_HISTORICAL_UNAUTHORIZED_RETRY_LIMIT = "Swoole-serv: Unauthorized: Retry limit reached in the Refinitiv Historical process.";
    public const REF_HISTORICAL_TOO_MANY_REQUESTS = "Swoole-serv: Too Many Requests: Request failed in the Refinitiv Historical process.";
    public const REF_HISTORICAL_TOO_MANY_REQUESTS_RETRY_LIMIT = "Swoole-serv: Too Many Requests: Retry limit reached in the Refinitiv Historical process.";
    public const REF_HISTORICAL_INVALID_RESPONSE = "Swoole-Serv: Refinitiv Historical API Error – Received an invalid response in the Historical API. Response from Refinitiv: %s";
    public const REF_HISTORICAL_OVERALL_RETRIES_LIMIT_EXCEEDED = "Swoole-serv: Overall retries limit exceeded in the Refinitiv Historical Process.";
}
