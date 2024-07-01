<?php
use Swoole\Coroutine;
use Al\Swow\Context;
use DB\DBFacade;
use Swoole\Runtime;
use Swoole\Http\Request;

class WebSocketController
{
    protected $webSocketServer;
    protected $frame;
    protected $dbConnectionPools;
    protected $postgresDbKey = 'pg';
    protected $mySqlDbKey = 'mysql';

  public function __construct($webSocketServer, $frame, $dbConnectionPools, $postgresDbKey = 'pg', $mySqlDbKey = 'mysql'){
      $this->webSocketServer = $webSocketServer;
      $this->frame = $frame;
      $this->dbConnectionPools = $dbConnectionPools;
      $this->postgresDbKey = $postgresDbKey;
      $this->mySqlDbKey = $mySqlDbKey;

  }

  public function handle() {
      if ($this->frame->data == 'reload-code') {
          echo "Reloading Code Changes (by Reloading All Workers)".PHP_EOL;
          $this->webSocketServer->reload();
      } else {
//                $postgresClient = $pool->get();
//                $sql            = 'SELECT version()';
//                $result         = $postgresClient->query($sql);
//                var_dump($postgresClient->fetchAll($result));
//                $pool->put($postgresClient);
          echo "Received from frame->fd: {$this->frame->fd}, frame->data: {$this->frame->data}, 
          frame->opcode: {$this->frame->opcode}, frame->fin:{$this->frame->finish}, frame->flags:{$this->frame->flags}\n";

          return array('data'=>"You sent {$this->frame->data} to the server");
      }
  }
}
