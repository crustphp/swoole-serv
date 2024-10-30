<?php

namespace App\Core\Commands;

use App\Core\Commands\BaseCommand;

class MakeProcess extends BaseCommand
{
    // Signature of the command (We will make use of it later)
    protected $signature = 'make:process';

    // Description of the Command
    protected $description = 'This command creates a custom user process and registers it';


    /**
     * Executes the code inside it when the command is executed
     *
     * @return void
     */
    public function handle(): void
    {
        // Add the logic here
        echo PHP_EOL . 'Process (' . $this->arguments[0] . ') has been created' . PHP_EOL;
    }
}
