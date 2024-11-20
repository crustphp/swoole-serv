<?php

namespace App\Services;
use DB\DbFacade;
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
            kd.*, 
            kds.\"keyDevId\" AS split_keyDevId, 
            kdote.*, 
            c.*, 
            kte.*, 
            st.*, 
            tz.\"announcedDateTimeZoneId\", tz.\"announcedDateTimeZoneName\"
        FROM key_dev kd
        LEFT JOIN key_dev_split_info kds 
            ON kd.\"keyDevId\" = kds.\"keyDevId\"
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
            ON dts.\"sourceTypeId\" = st.\"sourceTypeId\"
        LEFT JOIN key_dev_time_zone tz 
            ON kd.\"announcedDateTimeZoneId\" = tz.\"announcedDateTimeZoneId\"";
    
        $whereClauses = [];

        // Filters
        if (isset($this->request['filter']) && $this->request['filter'] === 'KSA') {
            $whereClauses[] = "c.country = 'KSA'";
        }

        if (isset($this->request['keyword'])) {
            $keyword = '%' . $this->request['keyword'] . '%';
            $keyword = str_replace("'", "''", $keyword); // Escape single quotes
            $whereClauses[] = "(kd.headline LIKE '$keyword' OR kd.situation LIKE '$keyword')";
        }

        if (isset($this->request['start_date']) && isset($this->request['end_date'])) {
            $startDate = $this->request['start_date'] . ' 00:00:00';
            $endDate = $this->request['end_date'] . ' 23:59:59';
            $whereClauses[] = "kd.\"announcedDate\" BETWEEN '$startDate' AND '$endDate'";
        }

        if (isset($this->request['company'])) {
            $companyId = $this->request['company'];
            $whereClauses[] = "c.id = '$companyId'";
        }

        if (isset($this->request['category'])) {
            $category = $this->request['category'];
            $category = str_replace("'", "''", $category); // Escape single quotes
            $whereClauses[] = "kte.\"keyDevCategoryName\" = '$category'";
        }

        if (isset($this->request['source'])) {
            $source = $this->request['source'];
            $source = str_replace("'", "''", $source); // Escape single quotes
            $whereClauses[] = "st.\"sourceTypeName\" = '$source'";
        }

        // Combine query
        $finalQuery = $baseQuery;
        if (!empty($whereClauses)) {
            $finalQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $finalQuery .= " ORDER BY kd.\"announcedDate\" DESC, kd.\"keyDevId\" ASC";

        $countQuery = "SELECT COUNT(*) as total FROM ($baseQuery) as subquery";
        $totalResult = $dbFacade->query($countQuery, $objDbPool);
        $total = $totalResult[0]['total'] ?? 0;
    
        // Pagination logic
        $limit = 20;
        $page = isset($this->request['page']) ? (int)$this->request['page'] : 1;
        $offset = ($page - 1) * $limit;
        $finalQuery .= " LIMIT $limit OFFSET $offset";

        // Execute query
        $newsData = $dbFacade->query($finalQuery, $objDbPool);
    
        // Process and structure the data
        $news = [];
        foreach ($newsData as $row) {
            $keyDevId = $row['keyDevId'];
            if (!isset($news[$keyDevId])) {
                $news[$keyDevId] = [
                    'keyDevId' => $row['keyDevId'],
                    'spEffectiveDate' => $row['spEffectiveDate'],
                    'spToDate' => $row['spToDate'],
                    'headline' => $row['headline'],
                    'situation' => $row['situation'],
                    'announcedDate' => $row['announcedDate'],
                    'announcedDateTimeZoneId' => $row['announcedDateTimeZoneId'],
                    'announceddateUTC' => $row['announceddateUTC'],
                    'enteredDate' => $row['enteredDate'],
                    'enteredDateUTC' => $row['enteredDateUTC'],
                    'lastModifiedDate' => $row['lastModifiedDate'],
                    'lastModifiedDateUTC' => $row['lastModifiedDateUTC'],
                    'mostImportantDateUTC' => $row['mostImportantDateUTC'],
                    'split_info' => [],
                    'object_to_event_type' => [],
                    'dev_to_source_type' => [],
                    'time_zone' => []
                ];
            }
    
            // Add related data
            if (!empty($row['split_keyDevId'])) {
                $news[$keyDevId]['split_info'][] = [
                    'keyDevId' => $row['split_keyDevId']
                ];
            }
    
            if (!empty($row['keyDevToObjectToEventTypeID'])) {
                $news[$keyDevId]['object_to_event_type'][] = [
                    'keyDevToObjectToEventTypeID' => $row['keyDevToObjectToEventTypeID'],
                    'spEffectiveDate' => $row['spEffectiveDate'],
                    'spToDate' => $row['spToDate'],
                    'keyDevID' => $row['keyDevID'],
                    'objectID' => $row['objectID'],
                    'keyDevEventTypeID' => $row['keyDevEventTypeID'],
                    'keyDevToObjectRoleTypeID' => $row['keyDevToObjectRoleTypeID'],
                    'company' => [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'sp_comp_name' => $row['sp_comp_name'],
                        'en_long_name' => $row['en_long_name'],
                        'en_short_name' => $row['en_short_name'],
                        'ar_long_name' => $row['ar_long_name'],
                        'ar_short_name' => $row['ar_short_name']
                    ],
                    'category_type' => [
                        'keyDevEventTypeId' => $row['keyDevEventTypeID'],
                        'keyDevCategoryId' => $row['keyDevCategoryID'],
                        'keyDevCategoryName' => $row['keyDevCategoryName'],
                        'keyDevEventTypeName' => $row['keyDevEventTypeName']
                    ]
                ];
            }
    
            if (!empty($row['sourceTypeId'])) {
                $news[$keyDevId]['dev_to_source_type'][] = [
                    'sourceTypeId' => $row['sourceTypeId'],
                    'sourceTypeName' => $row['sourceTypeName']
                ];
            }
    
            if (!empty($row['announcedDateTimeZoneId'])) {
                $news[$keyDevId]['time_zone'][] = [
                    'announcedDateTimeZoneId' => $row['announcedDateTimeZoneId'],
                    'announcedDateTimeZoneName' => $row['announcedDateTimeZoneName']
                ];
            }
        }
    
        // Build final result
        $result = [
            'news' => [
                'current_page' => $page,
                'data' => array_values($news),
                'per_page' => $limit,
                'total' => $total
            ],
            'status' => 200
        ];
    
        // Push to WebSocket
        $this->webSocketServer->push($this->frame->fd, json_encode($result));
    }
    
}
