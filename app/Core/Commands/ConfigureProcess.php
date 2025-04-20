<?php

namespace App\Core\Commands;

use App\Core\Commands\BaseCommand;
use Crust\StubConverter\StubGenerator;

class ConfigureProcess extends BaseCommand
{
    // Signature of the command (We will make use of it later)
    protected $signature = 'configure:process';

    // Description of the Command
    protected $description = 'This command configures a custom user process';

    protected $registryFilePath = "";

    protected $envConfigurationFilePath = "";

    public function __construct()
    {
        // Setting up paths
        $this->registryFilePath = dirname(__DIR__, 1) . '/Processes/ProcessesRegister.json';
        $this->envConfigurationFilePath = dirname(__DIR__, 1) . '/Processes/EnvironmentConfigurations.json';
    }

    /**
     * Executes the code inside it when the command is executed
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            if (!isset($this->arguments[0])) {
                output('Error: Process Name is required');
                exit(1);
            }

            $processBaseName = trim($this->arguments[0]);

            // Check if the Process with given name is registered / exists
            if (!$this->processExists($processBaseName)) {
                output("Error: Process ($processBaseName) is not registered.");
                exit(1);
            }

            $envConfigurationData = readJsonFile($this->envConfigurationFilePath);

            // Enable / Disable Process
            if (isset($this->options['enable'])) {
                $enable = (bool) $this->options['enable'];

                if (!array_key_exists('enabled_processes', $envConfigurationData)) {
                    $envConfigurationData['enabled_processes'] = [];
                }

                if ($enable && !in_array($processBaseName, $envConfigurationData['enabled_processes'])) {
                    array_push($envConfigurationData['enabled_processes'], $processBaseName);
                } else if(!$enable) {
                    $envConfigurationData['enabled_processes'] = array_values(array_diff($envConfigurationData['enabled_processes'], [$processBaseName]));
                }

                // Update the Environment Configuration File
                writeDataToJsonFile($envConfigurationData, $this->envConfigurationFilePath, 'w');
                output("Process ($processBaseName) has been " . ($enable ? 'enabled' : 'disabled'));
            }
        } catch (\Throwable $e) {
            output($e);
        }
    }

    /**
     * This function checks if the process has already been registered / exists
     *
     * @param  string $processBaseName The name of the process base class you want to check
     * 
     * @return bool True if process is already registered, otherwise False
     */
    public function processExists(string $processBaseName): bool
    {
        $registeredProcesses = readJsonFile($this->registryFilePath);

        foreach ($registeredProcesses as $registeredProcess) {
            if ($registeredProcess['name'] == $processBaseName) {
                return true;
            }
        }

        return false;
    }
}
