<?php

namespace App\Core\Commands;

use App\Core\Commands\BaseCommand;
use App\Core\Services\PdoService;
use Crust\StubConverter\StubGenerator;

class MakeProcess extends BaseCommand
{
    // Signature of the command (We will make use of it later)
    protected $signature = 'make:process';

    // Description of the Command
    protected $description = 'This command creates a custom user process and registers it';

    protected $stubPath = "";
    protected $toPath = "";

    protected $registryFilePath = "";

    public function __construct()
    {
        // Setting up paths
        $this->stubPath = dirname(__DIR__, 3) . '/stubs/ProcessStub.stub';
        $this->toPath = dirname(__DIR__, 2) . '/Processes/';
        $this->registryFilePath = dirname(__DIR__, 1) . '/Processes/ProcessesRegister.json';
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
                output('Error: Process base class name is required');
                exit(1);
            }

            $processBaseName = trim($this->arguments[0]);

            // Check if the Process with given name is already registered/exists
            if ($this->isProcessAlreadyRegistered($processBaseName)) {
                output("Error: Process ($processBaseName) is already registered.");
                exit(1);
            }

            // File Path
            $filePath = $this->toPath . $processBaseName . '.php';

            // Create the ProcessFile using StubGenerator
            $stubGen = new StubGenerator();
            $fileSaved = $stubGen->from($this->stubPath)
                ->to($this->toPath)
                ->as($processBaseName)
                ->ext('php')
                ->withReplacers([
                    'class' => $processBaseName,
                ])
                ->save();

            if (!$fileSaved) {
                throw new \RuntimeException('Failed converting ProcessStub to Process file');
            }

            // Register the process in ProcessesRegister.
            // Here if process options provided in command than use those otherwise store default values
            $data = [
                'name' => $processBaseName,
                'redirect_stdin_and_stdout' => isset($this->options['redirect_stdin_and_stdout']) ? (bool) $this->options['redirect_stdin_and_stdout'] : false,
                'pipe_type' => isset($this->options['pipe_type']) ? (int) $this->options['pipe_type'] : SOCK_DGRAM,
                'enable_coroutine' => isset($this->options['enable_coroutine']) ? (bool) $this->options['enable_coroutine'] : true,
            ];

            $processRegistered = writeDataToJsonFile($data, $this->registryFilePath);

            // If the process is not registered successfully then remove the process file and show error message
            if (!$processRegistered) {
                unlink($filePath);

                output("Error: Failed to register the ($processBaseName) Process");
                exit(1);
            }

            output("Process ($processBaseName) has been created successfully. Path: $filePath");
        } catch (\Throwable $e) {
            // Remove the process file if its created
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Remove the Process from process Register if its registered
            if (!empty($processBaseName) && $this->isProcessAlreadyRegistered($processBaseName)) {
                $processRemovedFromRegister = removeItemFromJsonFile('name', $processBaseName, $this->registryFilePath);

                if (!$processRemovedFromRegister) {
                    output("Error: Failed to remove the ($processBaseName) Process from ProcessRegister. Please remove it manually from " . $this->registryFilePath);
                }
            }

            output($e);
        }
    }

    /**
     * This function checks if the process has already been registered
     *
     * @param  string $processBaseName The name of the process base class you want to check
     * @return bool True if process is already registered, otherwise False
     */
    public function isProcessAlreadyRegistered(string $processBaseName): bool
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
