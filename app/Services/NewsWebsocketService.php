<?php

namespace App\Services;
use DB\DbFacade;
use Throwable;
use Swoole\Coroutine\Channel;
class NewsWebsocketService
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

        // Base query with necessary joins
        $baseQuery = "SELECT
            kd.\"keyDevId\",
            kd.\"headline\",
            kd.\"situation\",
            kd.\"announcedDate\",
            kdote.\"keyDevToObjectToEventTypeID\",
            kdote.\"objectID\",
            kdote.\"keyDevEventTypeID\",
            kdote.\"keyDevToObjectRoleTypeID\",
            c.\"sp_comp_id\",
            c.\"name\",
            c.\"short_name\",
            kte.\"keyDevCategoryName\",
            st.\"sourceTypeId\",
            st.\"sourceTypeName\",
            COUNT(*) OVER () AS total
        FROM key_dev kd
        LEFT JOIN key_dev_to_object_to_event_type kdote
            ON kd.\"keyDevId\" = kdote.\"keyDevID\"
            AND kd.\"spEffectiveDate\" = kdote.\"spEffectiveDate\"
        LEFT JOIN companies c
            ON kdote.\"objectID\" = c.\"sp_comp_id\"
        LEFT JOIN key_dev_category_type kte
            ON kdote.\"keyDevEventTypeID\" = kte.\"keyDevEventTypeId\"
        LEFT JOIN key_dev_to_source_type dts
            ON kd.\"keyDevId\" = dts.\"keyDevId\"
        LEFT JOIN source_type st
            ON dts.\"sourceTypeId\" = st.\"sourceTypeId\"";

        $whereClauses = [];

        // Country
        if (isset($this->request['country'])) {
            $country = str_replace("'", "''", $this->request['country']); // escape single quotes
            $whereClauses[] = "c.country = '$country'";
        }

        if (isset($this->request['keyword'])) {
            $keyword = str_replace("'", "''", $this->request['keyword']); // Escape single quotes
            $keyword = '%' . $keyword . '%';
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
            $category = str_replace("'", "''", $this->request['category']); // Escape single quotes
            $whereClauses[] = "kte.\"keyDevCategoryName\" ILIKE '$category'";
        }

        if (isset($this->request['source'])) {
            $source = str_replace("'", "''", $this->request['source']); // Escape single quotes
            $whereClauses[] = "st.\"sourceTypeName\" ILIKE '$source'";
        }

        // Combine query
        $finalQuery = $baseQuery;
        if (!empty($whereClauses)) {
            $finalQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $finalQuery .= " ORDER BY kd.\"announcedDate\" DESC, kd.\"keyDevId\" ASC";

        // Pagination logic
        $limit = 20;
        $page = isset($this->request['page']) ? (int)$this->request['page'] : 1;
        $offset = ($page - 1) * $limit;
        $finalQuery .= " LIMIT $limit OFFSET $offset";

        // Execute query
        $channel = new Channel(1);
            go(function () use ( $dbFacade, $finalQuery, $objDbPool, $channel) {
                try {
                    $result = $dbFacade->query($finalQuery, $objDbPool);
                    $channel->push($result);
                } catch (Throwable $e) {
                    output($e);
                }
            });

        $newsData = $channel->pop();

        // Process and structure the data
        $news = [];
        foreach ($newsData as $row) {
            $keyDevId = $row['keyDevId'];
            if (!isset($news[$keyDevId])) {
                $news[$keyDevId] = [
                    'keyDevId' => $row['keyDevId'],
                    'headline' => $row['headline'],
                    'announcedDate' => $row['announcedDate'],
                    'object_to_event_type' => [
                        'keyDevToObjectToEventTypeID' => $row['keyDevToObjectToEventTypeID'],
                        'objectID' => $row['objectID'],
                        'keyDevEventTypeID' => $row['keyDevEventTypeID'],
                        'keyDevToObjectRoleTypeID' => $row['keyDevToObjectRoleTypeID'],
                    ],
                    'company' => [
                        'sp_comp_id' => $row['sp_comp_id'],
                        'en_long_name' => $row['name'],
                        'en_short_name' => $row['short_name'],
                        //'ar_long_name' => $row['arabic_name'],
                        //'ar_short_name' => $row['arabic_short_name']
                    ],
                    'dev_to_source_type' => [
                        'sourceTypeId' => $row['sourceTypeId'],
                        'sourceTypeName' => $row['sourceTypeName']
                    ],
                ];
            }

        }

        $totalRecords = $newsData[0]['total'] ?? 0;
        $totalPages = (int) ceil($totalRecords / $limit);

        // Force at least 1 page if no records
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        // Build final result
        $result = [
            'news' => [
                'current_page' => $page,
                'data' => array_values($news),
                'per_page' => $limit,
                'total_pages' => $totalPages,
                'total' => $totalRecords,
            ],
            'status' => 200
        ];

        // Push to WebSocket
        $this->webSocketServer->push($this->frame->fd, json_encode($result));
    }

}
