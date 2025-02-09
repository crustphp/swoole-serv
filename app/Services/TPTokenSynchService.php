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

    public function __construct($server, $process)
    {
        $this->privilegedFDKeyForRefToken = config('ref_config.privileged_fd_key_for_ref_token');
        $this->server = $server;
        $this->process = $process;
        $this->refTokenTable = SwooleTableFactory::getTable('ref_token_sw', true);
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        // In case of stage or local make websocket connection with prod
        if (config('app_config.env') == 'local' || config('app_config.env') == 'staging') {
            $this->getTokenFrmProductionSever(config('app_config.production_ip'));
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
                $recievedata = $objTokenRetrieval->recv();
                if ($recievedata) {
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
                    }
                }
                Co::sleep(1);
            }
        } else {
            output("Could not connect to server, production server not running");
        }
    }
}
