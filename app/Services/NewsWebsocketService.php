<?php

namespace App\Services;
use DB\DbFacade;
use Throwable;
use Swoole\Coroutine\Channel;
use App\Core\Enum\ResponseStatusCode;

class NewsWebsocketService
{
    protected $dbConnectionPools;
    protected $webSocketServer;
    protected $frame;
    protected $postgresDbKey;
    protected $mySqlDbKey;
    protected $request;
    protected $requestFromCommand;

    public function __construct($webSocketServer, $frame, $dbConnectionPools, $request, $postgresDbKey = null, $requestFromCommand = true)
    {
        $this->request = $request;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
        $this->webSocketServer = $webSocketServer;
        $this->frame = $frame;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->mySqlDbKey = $mySqlDbKey ?? $swoole_mysql_db_key;
        $this->requestFromCommand = $requestFromCommand;
    }

    public function handle()
    {
        $objDbPool = $this->dbConnectionPools[$this->postgresDbKey];
        $dbFacade = new DbFacade();
    
        $whereClauses = [];
    
        // Filters
        if (isset($this->request['country'])) {
            $country = str_replace("'", "''", $this->request['country']);
            $whereClauses[] = "c.country = '$country'";
        }
    
        if (isset($this->request['keyword'])) {
            $keyword = str_replace("'", "''", $this->request['keyword']);
            $keyword = "%$keyword%";
            $whereClauses[] = "(kd.headline ILIKE '$keyword' OR kd.situation ILIKE '$keyword')";
        }
    
        if (isset($this->request['days']) && is_numeric($this->request['days'])) {
            $days = (int) $this->request['days'];
            $whereClauses[] = "kd.\"announcedDate\" >= NOW() - INTERVAL '$days days'";
        }
    
        if (isset($this->request['start_date']) && isset($this->request['end_date'])) {
            $startDate = str_replace("'", "''", $this->request['start_date']) . ' 00:00:00';
            $endDate = str_replace("'", "''", $this->request['end_date']) . ' 23:59:59';
            $whereClauses[] = "kd.\"announcedDate\" BETWEEN '$startDate' AND '$endDate'";
        }
    
        if (isset($this->request['company'])) {
            $company = str_replace("'", "''", $this->request['company']);
            $whereClauses[] = "(CAST(c.id AS TEXT) ILIKE '%$company%' OR c.name ILIKE '%$company%')";
        }
    
        if (isset($this->request['category'])) {
            $category = str_replace("'", "''", $this->request['category']);
            $whereClauses[] = "kte.\"keyDevCategoryName\" ILIKE '$category'";
        }
    
        if (isset($this->request['source'])) {
            $source = str_replace("'", "''", $this->request['source']);
            $whereClauses[] = "st.\"sourceTypeName\" ILIKE '$source'";
        }
    
        $limit = 20;
        $page = isset($this->request['page']) ? (int)$this->request['page'] : 1;
        $offset = ($page - 1) * $limit;
    
        // Core optimized single-query using COUNT(*) OVER () wrapped in subquery
        $query = "
            SELECT *
            FROM (
                SELECT 
                    COUNT(kd.\"keyDevId\") OVER () as total, 
                    kd.\"keyDevId\",
                    kd.\"spEffectiveDate\",
                    kd.\"spToDate\",
                    kd.\"headline\",
                    kd.\"headline_ar\",
                    kd.\"situation\",
                    kd.\"announcedDate\",

                    -- kdote.\"keyDevToObjectToEventTypeID\",
                    -- kdote.\"objectID\",
                    -- kdote.\"keyDevEventTypeID\",
                    -- kdote.\"keyDevToObjectRoleTypeID\",

                    c.\"id\" AS company_id,
                    c.\"name\",
                    c.\"short_name\",
                    c.\"arabic_name\",
                    c.\"arabic_short_name\",
                    c.\"sp_comp_id\",
                    c.\"symbol\",
                    c.\"isin_code\",
                    c.\"ric\",
                    c.\"logo\",
                    c.\"parent_id\" AS market_id,

                    m.\"name\" AS market_name,

                    kte.\"keyDevEventTypeName\",

                    -- st.\"sourceTypeId\",
                    st.\"sourceTypeName\"

                FROM key_dev kd
                LEFT JOIN key_dev_to_object_to_event_type kdote
                    ON kd.\"keyDevId\" = kdote.\"keyDevID\"
                    AND kd.\"spEffectiveDate\" = kdote.\"spEffectiveDate\"
                LEFT JOIN companies c
                    ON kdote.\"objectID\" = c.\"sp_comp_id\"
                LEFT JOIN markets m
                    ON c.\"parent_id\" = m.\"id\"    
                LEFT JOIN key_dev_category_type kte
                    ON kdote.\"keyDevEventTypeID\" = kte.\"keyDevEventTypeId\"
                LEFT JOIN key_dev_to_source_type dts
                    ON kd.\"keyDevId\" = dts.\"keyDevId\"
                LEFT JOIN source_type st
                    ON dts.\"sourceTypeId\" = st.\"sourceTypeId\"
                " . (!empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '') . "
            ) as resultset
            ORDER BY resultset.\"spEffectiveDate\" DESC, resultset.\"keyDevId\" ASC
            LIMIT $limit OFFSET $offset;
        ";
        
        $channel = new Channel(1);
        go(function () use ($dbFacade, $query, $objDbPool, $channel) {
            try {
                $result = $dbFacade->query($query, $objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
                $channel->close();
            }
        });
    
        $rows = $channel->pop();

        if ($channel->errCode != 0) {
            $this->webSocketServer->push($this->frame->fd, json_encode(['message' => 'Something went wrong while fetching News data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
            return;
        }
    
        $news = [];
        foreach ($rows as $row) {
            $keyDevId = $row['keyDevId'];
            if (!isset($news[$keyDevId])) {
                $news[$keyDevId] = [
                    'keyDevId' => $row['keyDevId'],
                    'headline' => $row['headline'],
                    'headline_ar' => $row['headline_ar'],
                    'spEffectiveDate' => $row['spEffectiveDate'],
                    'spToDate' => $row['spToDate'],
                    'announcedDate' => $row['announcedDate'],
                    'object_to_event_type' => [
                        // 'keyDevToObjectToEventTypeID' => $row['keyDevToObjectToEventTypeID'],
                        'keyDevEventTypeName' => $row['keyDevEventTypeName'],
                        // 'objectID' => $row['objectID'],
                        // 'keyDevEventTypeID' => $row['keyDevEventTypeID'],
                        // 'keyDevToObjectRoleTypeID' => $row['keyDevToObjectRoleTypeID'],
                    ],
                    'company' => [
                        'company_id' => $row['company_id'],
                        'en_long_name' => $row['name'],
                        'en_short_name' => $row['short_name'],
                        'ar_long_name' => $row['arabic_name'],
                        'ar_short_name' => $row['arabic_short_name'],
                        'sp_comp_id' => $row['sp_comp_id'],
                        'symbol' => $row['symbol'],
                        'isin_code' => $row['isin_code'],
                        'ric' => $row['ric'],
                        'logo' => config('app_config.laravel_app_url').'storage/'.$row['logo'],
                        'market_id' => $row['market_id'],
                        'market_name' => $row['market_name'],
                    ],
                    'dev_to_source_type' => [
                        // 'sourceTypeId' => $row['sourceTypeId'],
                        'sourceTypeName' => $row['sourceTypeName']
                    ],
                ];
            }
        }
    
        $totalRecords = $rows[0]['total'] ?? 0;
        $totalPages = (int) ceil($totalRecords / $limit);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        
        if ($this->requestFromCommand) {
            $result = [
                "command" => "get-news",
                'news' => [
                    'current_page' => $page,
                    'data' => array_values($news),
                    'per_page' => $limit,
                    'total_pages' => $totalPages,
                    'total' => $totalRecords,
                ],
                'status_code' => ResponseStatusCode::OK->value
            ];
        }
        else {
            $result = [
                'news' => array_values($news),
            ];
        }
        
    
        $this->webSocketServer->push($this->frame->fd, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    

}
