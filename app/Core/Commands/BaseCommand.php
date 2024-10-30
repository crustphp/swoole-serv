<?php

namespace App\Core\Commands;

/**
 * This class is the Base class of all the commands. It will have common functions and properties
 */
abstract class BaseCommand
{
    // This property stores the arguments of the command
    protected $arguments = [];

    /**
     * This function is used to set the command arguments
     *
     * @param  array $arguments
     * @return void
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    // Abstract method to force implementing the handle method in subclasses
    abstract public function handle(): void;
}
