<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class RegisteredProcessesSeeder extends AbstractSeed
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * https://book.cakephp.org/phinx/0/en/seeding.html
     */
    public function run(): void
    {
        // Names (NewService, RefService) will be replaced in future after "make:process" command to NewsBase and RefBase respectively.
        $data = [
            [
                'name' => 'NewsService',
                'redirect_stdin_and_stdout' => false,
                'pipe_type' => SOCK_DGRAM,
                'enable_coroutine' => true,
            ],
            [
                'name' => 'RefService',
                'redirect_stdin_and_stdout' => false,
                'pipe_type' => SOCK_DGRAM,
                'enable_coroutine' => true,
            ],
        ];

        $posts = $this->table('registered_processes');
        $posts->insert($data)
            ->saveData();
    }
}
