<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RegisteredProcesses extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // create the table
        $table = $this->table('registered_processes');
        $table->addColumn('name', 'string', ['limit' => 70])
            ->addColumn('redirect_stdin_and_stdout', 'boolean')
            ->addColumn('pipe_type', 'integer')
            ->addColumn('enable_coroutine', 'boolean')
            ->addTimestampsWithTimezone()
            ->create();
    }
}
