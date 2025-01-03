<?php

namespace App\Core\Processes;

use Swoole\Timer;
use Swoole\Atomic;
use Swoole\Process;
use Bootstrap\ServiceContainer;
use App\Core\Traits\CustomProcessesTrait;

class MainProcess
{
    use CustomProcessesTrait;

    protected $server;
    protected $process;

    protected $customProcessesMetaData;

    protected $baseDir;

    // Atomic counter for process IDs
    protected $processIdCounter = null;

    public function __construct($server, $process)
    {
        $this->server = $server;
        $this->process = $process;
        $this->baseDir = dirname(__DIR__, 3);

        // Since we are using $process->start so we will not have $process->id ...
        // So we use Swoole Atomic to get incremented value to be used as process ID
        // Docs: https://wiki.swoole.com/en/#/memory/atomic
        if ($this->processIdCounter === null) {
            $startFrom = config('swoole_config.server_settings.worker_num') + config('swoole_config.server_settings.task_worker_num') - 1;
            $this->processIdCounter = new Atomic($startFrom);
        }
    }

    /**
     * This function we start the other processes
     *
     * @return void
     */
    public function handle()
    {
        // Following Timer is important to prevent the custom MainProcess from continuously exiting and restarting
        Timer::tick(10000, function () {});

        // Following SIGCHLD is catched when child processes of this Main Process are killed
        Process::signal(SIGCHLD, static function ($sig) {
            while ($ret = Process::wait(true)) {
                /* clean up then event loop will exit */
                Timer::clearAll();
            }
        });

        // Get the service container instance
        $serviceContainer = ServiceContainer::get_instance();

        // Get the registered processes from the Service Container
        $this->customProcessesMetaData = $serviceContainer->get_registered_processes();

        // Following code is to create process Resident Process based on customProcessesMetaData[] and enable reload on process code.
        if (!isset($this->server->customProcesses)) {
            $this->server->customProcesses = [];
        }

        // Close the custom user Processes if they are already running as they are not terminated when
        // the main process exits abnormally as per docs: https://wiki.swoole.com/en/#/process/process?id=usage-example
        $this->killProcessesByPidFolder(['MainProcess'], true);

        // Setup and Start registered custom user processes
        foreach ($this->customProcessesMetaData as $processKey => $processInfo) {
            // Process Options
            $processOptions = $processInfo['process_options'] ?? [];

            // Process Creation Callback
            $processCallback = function (Process $process) use ($processKey, $processInfo) {
                try {
                    // Set the name of the process
                    $process->name($processKey);

                    // Since we are usign $process->start so we will not have $process->id ...
                    // So we use Swoole Atomic to get incremented value to be used as process ID
                    // Docs: https://wiki.swoole.com/en/#/memory/atomic?id=add
                    $process->id = $this->processIdCounter->add();

                    // Create the PID file of process - Used to kill the process in Before Reload
                    $pidFile = $this->baseDir . '/process_pids/' . $processKey . '.pid';
                    file_put_contents($pidFile, $process->pid);

                    // Get the ServiceContainerInstance with global process parameters
                    $serviceContainer = ServiceContainer::get_instance($this->server, $process);
                    // Here we pass the server and process as constructor params to avoid error of these values replaced by latest process
                    $processBaseClass = $serviceContainer($processKey, null, $this->server, $process);

                    // Catch the Signal to revoke resources before terminating the processes
                    Process::signal(SIGTERM, function ($signo) use ($processBaseClass) {
                        // Clear Timers
                        Timer::clearAll();

                        // Unset Process Base Class
                        unset($processBaseClass);
                    });

                    // Following Code is not needed/commented when we use $process->start();
                    // Throw the exception if no Swoole\Timer is used in.
                    // There should be a Timer is to prevent the user process from continuously exiting and restarting as per documentation
                    // Reference: https://wiki.swoole.com/en/#/server/methods?id=addprocess
                    // if (count(Timer::list()) == 0) {
                    //     $qualifiedClassName = $processInfo['callback'][0] ?? "";
                    //     throw new ExitException('The resident process ([' . $processKey . '] -> ' . $qualifiedClassName . ') must have a Swoole\Timer::tick()');
                    // }
                } catch (\Throwable $e) {
                    // On Local Environment, shutdown the server on Exception for debugging
                    // Else just log the exception without exiting/shutting down the server
                    if (config('app_config.env') == 'local') {
                        output(data: $e, server: $this->server, shouldExit: true);
                    } else {
                        output($e);
                    }
                }
            };

            // Create the Process
            $this->server->customProcesses[$processKey] = new Process($processCallback, ...$processOptions);

            // Start the process
            $this->server->customProcesses[$processKey]->start();
        }
    }
}
