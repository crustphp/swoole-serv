<?php
use Swoole\Coroutine;
use Al\Swow\Context;
use DB\DbFacade;
use Swoole\Runtime;
use Swoole\Http\Request;

class WebSocketController
{
    protected $webSocketServer;
    protected $frame;
    protected $dbConnectionPools;
    protected $postgresDbKey;
    protected $mySqlDbKey;

  public function __construct($webSocketServer, $frame, $dbConnectionPools = null, $postgresDbKey = null, $mySqlDbKey = null) {
      global $swoole_pg_db_key;
      global $swoole_mysql_db_key;
      $this->webSocketServer = $webSocketServer;
      $this->frame = $frame;
      $this->dbConnectionPools = $dbConnectionPools;
      $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
      $this->mySqlDbKey = $mySqlDbKey ?? $swoole_mysql_db_key;
  }

  public function handle() {
      if ($this->frame->data == 'reload-code') {
          echo "Reloading Code Changes (by Reloading All Workers)".PHP_EOL;
          $this->webSocketServer->reload();
      } else {
          if (!is_null($this->dbConnectionPools)) {
              $objDbPool = $this->dbConnectionPools[$this->postgresDbKey];
              $record_set = new Swoole\Coroutine\Channel(1);

              go(function() use ($record_set, $objDbPool) {
                  $db_query = 'SELECT * FROM users;';
                  $dbFacade = new DbFacade();
                  $db_result = $dbFacade->query($db_query, $objDbPool);
                  $record_set->push($db_result);
              });

              print_r($record_set->pop());
          }

          echo "Received from frame->fd: {$this->frame->fd}, frame->data: {$this->frame->data}, 
          frame->opcode: {$this->frame->opcode}, frame->fin:{$this->frame->finish}, frame->flags:{$this->frame->flags}\n";

          if ($this->frame->data == 'test1') {
              $task_id = $this->webSocketServer->task([$this->frame->fd, $this->frame->data], -1);

              $task_id = $this->webSocketServer->task([$this->frame->fd, $this->frame->data.' own-task-call-back'], 0, function (Swoole\Server $server, $task_id, $data) {
                    echo "Task's own Result-Processing Callback: \n";
                   var_dump($task_id, $data);
              });
          }

          return array('data'=>"You sent {$this->frame->data} to the server");
      }
  }
}
