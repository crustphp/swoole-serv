<?php

namespace App\Services;

use Bootstrap\SwooleTableFactory;
use Carbon\Carbon;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine as Co;

class TPTokenSynchService
{
    protected $server;
    protected $process;
    protected $privilegedFDKeyForRefToken;
    protected $refTokenTable;

    protected $refTokenSyncRetryInterval;

    public function __construct($server, $process)
    {
        $this->privilegedFDKeyForRefToken = config('ref_config.privileged_fd_key_for_ref_token');
        $this->server = $server;
        $this->process = $process;
        $this->refTokenTable = SwooleTableFactory::getTable('ref_token_sw', true);
        $this->refTokenSyncRetryInterval = config('ref_config.ref_token_sync_retry_interval');
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        // In case of stage or local make websocket connection with prod
        if (config('app_config.env') == 'local' || config('app_config.env') == 'staging' || config('app_config.env') == 'pre-production') {
            do {
                // Log the Coroutine Stats on Each Iteration
                if (config('app_config.env') == 'local' || config('app_config.env') == 'staging' || config('app_config.env') == 'pre-production') {
                    output(data: Co::stats(), processName: $this->process->title);
                }
                
                $this->getTokenFrmProductionSever(config('app_config.production_ip'));
                output('Refinitiv Token Sync Function ended and will retry after ' . $this->refTokenSyncRetryInterval . ' seconds');
                Co::sleep($this->refTokenSyncRetryInterval);
            } while (true);
        }
    }

    function getTokenFrmProductionSever($ip)
    {
        $objTokenRetrieval = new Client($ip, 9501);
        $objTokenRetrieval->set(['timeout' => -1]);
        $objTokenRetrieval->setHeaders(['privileged-fd-key-for-ref-token' => $this->privilegedFDKeyForRefToken]);
        $connTokenRetrieval = $objTokenRetrieval->upgrade('/');

        if ($connTokenRetrieval) {
            while (true) {
                // Ping the Production Server for connection confirmation
                if (!$objTokenRetrieval->push('', WEBSOCKET_OPCODE_PING)) {
                    output("Token Sync Websocket Connection might be lost!");
                    break;
                }

                $recievedata = $objTokenRetrieval->recv();
                if ($recievedata) {
                    // opcode 10 is for Pong Frame
                    if ($recievedata->opcode != 10) {
                        $recievedata = json_decode($recievedata->data ?? null);

                        if (
                            isset($recievedata->access_token)
                            && isset($recievedata->refresh_token)
                            && isset($recievedata->expires_in)
                            && isset($recievedata->updated_at)

                        ) {
                            $serverUpdatedAt = Carbon::parse($recievedata->updated_at)->timezone('UTC');
                            $localUpdatedAt = $serverUpdatedAt->timezone(config('app_config.time_zone'));
                            $createdAt = Carbon::now()->format('Y-m-d H:i:s');

                            $token = [
                                'id' => 1,
                                'access_token' => $recievedata->access_token,
                                'refresh_token' => $recievedata->refresh_token,
                                'expires_in' => $recievedata->expires_in,
                                'created_at' => $createdAt,
                                'updated_at' => $localUpdatedAt,
                                'updated_by_process' => cli_get_process_title() ?? "1",
                            ];

                            // Save into the swoole table
                            $this->refTokenTable->set('1', $token);

                            output('Refinitiv token saved in swoole table');
                        }
                    }
                }
                Co::sleep(1);
            }
        } else {
            output("Could not connect to server, production server not running");
        }
    }
}
