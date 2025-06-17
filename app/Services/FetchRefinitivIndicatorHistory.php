<?php

namespace App\Services;

use App\Services\RefHistoricalAPIConsumer;
use App\Core\Enum\ResponseStatusCode;
use Carbon\Carbon;

class FetchRefinitivIndicatorHistory
{
    protected $webSocketServer;
    protected $objDbPool;
    protected $dbFacade;


    public function __construct($webSocketServer, $objDbPool, $dbFacade)
    {
        $this->webSocketServer = $webSocketServer;
        $this->objDbPool = $objDbPool;
        $this->dbFacade = $dbFacade;
    }

    /**
     * Processes the "get-indicators-history" WebSocket command.
     *
     * Validates input, fetches historical indicator data from the Refinitiv API,
     * and sends the response or error message back to the client.
     *
     * @param object $frame      WebSocket frame containing client metadata.
     * @param array  $frameData  Payload data from the client.
     * @return void
     */
    public function handle(object $frame, array $frameData): void
    {
        $fd = $frame->fd;

        $indicators = $frameData['indicators'] ?? null;
        $ric = $frameData['ric'] ?? null;
        $interval = $frameData['interval'] ?? null;
        $count = $frameData['count'] ?? null;
        $queryParams = [];

        if (!is_array($indicators) || empty($indicators)) {
            $this->respond($fd, 'Please provide indicators for which you want to get data', ResponseStatusCode::UNPROCESSABLE_CONTENT);
            return;
        }

        if (!$ric) {
            $this->respond($fd, 'Please provide ric for which you want to get data', ResponseStatusCode::UNPROCESSABLE_CONTENT);
            return;
        }

        if (!$interval) {
            $this->respond($fd, 'Please provide interval', ResponseStatusCode::UNPROCESSABLE_CONTENT);
            return;
        }

        if (!$count) {
            $this->respond($fd, 'Please provide count', ResponseStatusCode::UNPROCESSABLE_CONTENT);
            return;
        }

        $queryParams = [
            "fields" => $indicators,
            "count" => $count,
            "interval" => $interval,
            "sessions" => 'normal',
            "adjustments" => ['exchangeCorrection', 'manualCorrection', 'CCH', 'CRE', 'RTS', 'RPO']
        ];

        if (!empty($frameData['start'])) {
            $queryParams['start'] = $frameData['start'];
        }

        if (!empty($frameData['end'])) {
            $queryParams['end'] = $frameData['end'];
        }

        // Holidays and weekends are applied only for 1D and 5D interval
        if ($interval === 'PT1M') {
            if (isset($queryParams['start'])) {
                $milliseconds =  extractMilliseconds($queryParams['start']);
                $queryParams['start'] = getLastWorkingMarketDateTime($queryParams['start'], $this->objDbPool, $this->dbFacade);
                $queryParams['start'] = Carbon::parse($queryParams['start'])->setTimezone('UTC');
                $queryParams['start'] = $queryParams['start']->format("Y-m-d\TH:i:s") . '.' . $milliseconds . 'Z';
            }

            if (isset($queryParams['end'])) {
                $milliseconds =  extractMilliseconds($queryParams['end']);
                $queryParams['end'] = getLastWorkingMarketDateTime($queryParams['end'], $this->objDbPool, $this->dbFacade);
                $queryParams['end'] = Carbon::parse($queryParams['end'])->setTimezone('UTC');
                $queryParams['end'] = $queryParams['end']->format("Y-m-d\TH:i:s") . '.' . $milliseconds . 'Z';
            }
        } else if ($interval === 'PT5M') {

            if (isset($queryParams['start'])) {
                $queryParams['end'] = $queryParams['end'] ?? Carbon::now()->endOfDay()
                ->setTimezone('UTC')
                ->format("Y-m-d\TH:i:s.v\Z");

                $queryParams['start'] = $this->adjustStartDateForNonWorkingDays($queryParams['start'], $queryParams['end'], $this->objDbPool, $this->dbFacade);
            }
        }

        $url = ($interval === 'PT1M' || $interval === 'PT5M')
            ? config('ref_config.historical_pricing_intraday_url')
            : config('ref_config.historical_pricing_interday_url');

        $service = new RefHistoricalAPIConsumer($this->webSocketServer, $url, null);
        $responses = $service->handle([['ric' => $ric]], $queryParams, 'ric', $frameData['module'] ?? 'companies');

        $dataStructure = json_encode([
            'command' => $frameData['command'],
            'data' => $responses,
            'status_code' => ResponseStatusCode::OK->value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($dataStructure === false) {
            output("JSON encoding error for Command ({$frameData['command']}) : " . json_last_error_msg());
            $this->respond($fd, 'Something went wrong while encoding data', ResponseStatusCode::INTERNAL_SERVER_ERROR);
            return;
        }

        if ($this->webSocketServer->isEstablished($fd)) {
            $this->webSocketServer->push($fd, $dataStructure);
        }
    }

    private function respond($fd, string $message, ResponseStatusCode $statusCode): void
    {
        if ($this->webSocketServer->isEstablished($fd)) {
            $this->webSocketServer->push($fd, json_encode([
                'message' => $message,
                'status_code' => $statusCode->value,
            ]));
        }
    }

    /**
     * Adjusts the start date backward by non-working days (weekends and holidays) between start and end.
     *
     * @param string $start Start date in ISO 8601 format.
     * @param string $end End date in ISO 8601 format.
     * @param object $dbPool Database connection pool.
     * @param object $dbFacade Database query executor.
     * @return string Adjusted start date in ISO 8601 format.
     */
    private function adjustStartDateForNonWorkingDays(string $start, string $end, object $dbPool, object $dbFacade): string
    {
        $originalStart = Carbon::parse($start);
        $milliseconds = extractMilliseconds($start);
        $originalTime = $originalStart->format('H:i:s');

        // Calculate the total number of non-working days between the given range
        $nonWorkingDays = $this->countNonWorkingDaysBetween($start, $end, $dbPool, $dbFacade);

        // Adjust start date backward by the number of non-working days
        $adjustedStart = $originalStart->copy();
        while ($nonWorkingDays > 0) {
            $adjustedStart->subDay();
            if ($this->isWorkingDay($adjustedStart, $dbPool, $dbFacade)) {
                $nonWorkingDays--;
            }
        }

        // Restore the original time
        $adjustedStart = Carbon::parse($adjustedStart->toDateString() . " $originalTime");

        // Return formatted adjusted start date
        return $adjustedStart->format('Y-m-d\TH:i:s') . '.' . $milliseconds . 'Z';
    }

    /**
     * Counts non-working days (weekends and holidays) between the start and end dates.
     *
     * @param string $start Start date in ISO 8601 format.
     * @param string $end End date in ISO 8601 format.
     * @param object $dbPool Database connection pool.
     * @param object $dbFacade Database query executor.
     * @return int Total number of non-working days.
     */
    private function countNonWorkingDaysBetween(string $start, string $end, object $dbPool, object $dbFacade): int
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();
        $nonWorkingCount = 0;

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if (!$this->isWorkingDay($date, $dbPool, $dbFacade)) {
                $nonWorkingCount++;
            }
        }

        return $nonWorkingCount;
    }

    /**
     * Checks if a date is a working day (not a weekend or holiday).
     *
     * @param Carbon $date The date to check.
     * @param object $dbPool Database connection pool.
     * @param object $dbFacade Database query executor.
     * @return bool True if the date is a working day, false otherwise.
     */
    private function isWorkingDay(Carbon $date, object $dbPool, object $dbFacade): bool
    {
        $currentDate = $date->toDateString();

        // Check if the day is a weekend (Friday or Saturday)
        $isWeekend = in_array($date->dayOfWeekIso, [5, 6]); // Friday, Saturday

        // Check if the day falls within a holiday period
        $dbQuery = "SELECT from_date, to_date FROM holidays WHERE DATE '$currentDate' BETWEEN from_date AND to_date;";
        $holidayResult = executeDbFacadeQueryWithChannel($dbQuery, $dbPool, $dbFacade);

        // Return true if it's a working day
        return !$isWeekend && count($holidayResult) === 0;
    }

    public function __destruct()
    {
        unset($this->webSocketServer);
    }
}
