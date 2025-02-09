<?php

namespace App\Core\Traits;

use Swoole\Process;

trait CustomProcessesTrait
{
    /**
     * This function kills the processes having PID files in process_pids folder
     * 
     * @param array $except Pass the array of process names you don't want to kill
     * @return void
     */
    public function killProcessesByPidFolder(array $except = [], $forceKill = false): void
    {
        $killSignal = $forceKill ? 9 : 15;
        
        $pidFiles = glob(basePath() . '/process_pids/*.pid');
        $mainProcessData = null;

        foreach ($pidFiles as $processPidFile) {
            $processName = $this->getProcessNameFromPidPath($processPidFile);

            // Ignore process in $except array
            if (in_array($processName, $except)) {
                continue;
            }

            $pid = intval(shell_exec('cat ' . $processPidFile));

            // We kill the Main Process manually in the End
            if ($processName == 'MainProcess') {
                $mainProcessData = [
                    'pidFile' => $processPidFile,
                    'pid' => $pid,
                ];

                continue;
            }

            // Processes that do not have a timer or loop will exit automatically after completing their tasks.
            // Therefore, some processes might have already terminated before reaching this point
            // So here we need to check first if the process is running by passing signal_no param as 0, as per documentation
            // Doc: https://wiki.swoole.com/en/#/process/process?id=kill
            if (Process::kill($pid, 0)) {
                Process::kill($pid, $killSignal);
            }
        }

        // Kill the (Custom) MainProcess
        if ($mainProcessData) {
            if (Process::kill($mainProcessData['pid'], 0)) {
                Process::kill($mainProcessData['pid'], $killSignal);
            }
        }
    }

    /**
     * This function returns the process name from its PID file path
     *
     * @param  string $pidFilePath
     * @return string
     */
    public function getProcessNameFromPidPath(string $pidFilePath): string
    {
        return basename($pidFilePath, '.pid');
    }
}
