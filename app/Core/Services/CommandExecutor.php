<?php

namespace App\Core\Services;

class CommandExecutor
{
    // This property will contain the registered commands, We can seperate it in the future
    protected $commands = [];

    public function __construct()
    {
        $this->registerCommands();
    }

    /**
     * Register the commands in this function
     *
     * @return void
     */
    public function registerCommands(): void
    {
        $this->commands = [
            'make:process' => 'App\Core\Commands\MakeProcess',
        ];
    }

    /**
     * This function returns an array of registered commands
     *
     * @return array
     */
    public function getCommandsList(): array
    {
        return $this->commands;
    }

    /**
     * It is the initial function that is called when prompt file is executed.
     * Reponsible for parsing the $argv and executing the command
     *
     * @return void
     */
    public function process(): void
    {
        // The first argument $argv[0] is always the name that was used to run the script (In our case "prompt")
        // Docs: https://www.php.net/manual/en/reserved.variables.argv.php
        $args = $_SERVER['argv'] ?? [];

        // Parse command and arguments from $args
        // So $args[1] will be the command and rest of the array will be arguments (Docs: https://www.w3schools.com/php/func_array_slice.asp)
        $command = isset($args[1]) ? $args[1] : null;
        $arguments = array_slice($args, 2); // Start the slice from the 2nd array element, and return the rest of the elements in the array

        // Pass parsed command and arguments to the execute method
        $this->execute($command, $arguments);
    }

    /**
     * Execute the registered command
     *
     * @param  mixed $command
     * @param  mixed $arguments
     * @return void
     */
    public function execute(string $command, array $arguments = []): void
    {
        // Check if command is registered
        if (!isset($this->commands[$command])) {
            echo PHP_EOL . "Command not found." . PHP_EOL;
            exit(1);
        }

        // Create the instance of the registered command
        $commandClass = $this->commands[$command];
        $instance = new $commandClass();

        // Pass the arguments to command class
        $instance->setArguments($arguments);

        // Execute the command
        $instance->handle();
    }
}
