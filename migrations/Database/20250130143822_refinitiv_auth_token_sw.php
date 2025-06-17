<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RefinitivAuthTokenSw extends AbstractMigration
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
        $table = $this->table('refinitiv_auth_token_sw');
        $table->addColumn('access_token', 'string', ['limit' => 3000])
            ->addColumn('refresh_token', 'string', ['limit' => 1000])
            ->addColumn('expires_in', 'integer')
            ->addTimestamps()
            ->create();
    }
}
