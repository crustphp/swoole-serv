<?php

namespace App\Services;
use DB\DbFacade;
use Throwable;
use Swoole\Coroutine\Channel;
use App\Core\Enum\ResponseStatusCode;

class NewsDetailWebsocketService
{
    protected $dbConnectionPools;
    protected $webSocketServer;
    protected $frame;
    protected $postgresDbKey;
    protected $mySqlDbKey;
    protected $request;

    public function __construct($webSocketServer, $frame, $dbConnectionPools, $request, $postgresDbKey = null)
    {
        $this->request = $request;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
        $this->webSocketServer = $webSocketServer;
        $this->frame = $frame;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->mySqlDbKey = $mySqlDbKey ?? $swoole_mysql_db_key;
    }

    public function handle()
    {
        $objDbPool = $this->dbConnectionPools[$this->postgresDbKey];
        $dbFacade = new DbFacade();
    
        $keyDevId = $this->request['keyDevId'] ?? null;

        if (!$keyDevId || !is_numeric($keyDevId)) {
            $this->webSocketServer->push($this->frame->fd, json_encode([
                'status' => 400,
                'message' => 'Invalid keyDevId',
            ]));
            return;
        }

            $query = "
                SELECT 
                    kd.\"keyDevId\",
                    kd.\"headline\",
                    kd.\"headline_ar\",
                    kd.\"situation\",
                    kd.\"situation_ar\",
                    kd.\"spEffectiveDate\",
                    kd.\"announcedDate\",
                    kd.\"enteredDate\",
                    kd.\"lastModifiedDate\",

                    kdote.\"keyDevToObjectToEventTypeID\",
                    kdote.\"objectID\",
                    kdote.\"keyDevEventTypeID\",
                    kdote.\"keyDevToObjectRoleTypeID\",

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

                    st.\"sourceTypeId\",
                    st.\"sourceTypeName\",

                    kte.\"keyDevEventTypeName\"
                    
                FROM key_dev kd
                LEFT JOIN key_dev_to_object_to_event_type kdote
                    ON kd.\"keyDevId\" = kdote.\"keyDevID\"
                    AND kd.\"spEffectiveDate\" = kdote.\"spEffectiveDate\"
                LEFT JOIN companies c
                    ON kdote.\"objectID\" = c.\"sp_comp_id\"
                LEFT JOIN markets m
                    ON c.\"parent_id\" = m.\"id\"
                LEFT JOIN key_dev_to_source_type dts
                    ON kd.\"keyDevId\" = dts.\"keyDevId\"
                LEFT JOIN source_type st
                    ON dts.\"sourceTypeId\" = st.\"sourceTypeId\"
                LEFT JOIN key_dev_category_type kte
                        ON kdote.\"keyDevEventTypeID\" = kte.\"keyDevEventTypeId\"
                WHERE kd.\"keyDevId\" = $keyDevId
                LIMIT 1;
            ";


        $channel = new Channel(1);

        go(function () use ($dbFacade, $query, $objDbPool, $channel) {
            try {
                $result = $dbFacade->query($query, $objDbPool);
                $channel->push($result);
            } catch (\Throwable $e) {
                output($e);
            }
        });

        $rows = $channel->pop();

        if (empty($rows)) {
            $this->webSocketServer->push($this->frame->fd, json_encode([
                'status' => 404,
                'message' => 'News not found',
            ]));
            return;
        }

        $row = $rows[0];

        $response = [
            'status' => 200,
            "command" => "get-news-detail",
            'newsDetail' => [
                'keyDevId' => $row['keyDevId'],
                'headline' => $row['headline'],
                'headline_ar' => $row['headline_ar'],
                'situation' => $row['situation'],
                'situation_ar' => $row['situation_ar'],
                'spEffectiveDate' => $row['spEffectiveDate'],
                'announcedDate' => $row['announcedDate'],
                'enteredDate' => $row['enteredDate'],
                'lastModifiedDate' => $row['lastModifiedDate'],
                'object_to_event_type' => [
                    'keyDevToObjectToEventTypeID' => $row['keyDevToObjectToEventTypeID'],
                    'keyDevEventTypeName' => $row['keyDevEventTypeName'],
                    'objectID' => $row['objectID'],
                    'keyDevEventTypeID' => $row['keyDevEventTypeID'],
                    'keyDevToObjectRoleTypeID' => $row['keyDevToObjectRoleTypeID'],
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
                    'sourceTypeId' => $row['sourceTypeId'],
                    'sourceTypeName' => $row['sourceTypeName'],
                ],
            ]
        ];

        $this->webSocketServer->push($this->frame->fd, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    }

}
