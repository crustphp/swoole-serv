<?php

class LongTask
{
    protected $server;
    protected $task_id;
    protected $reactorId;
    protected $data;

    public function __constructor($server, $task_id, $reactorId, $data){
        $this->server = $server;
        $this->task_id = $task_id;
        $this->reactorId = $reactorId;
        $this->data = $data;
    }

    public function handle(){
        echo "Task Worker Process received data".PHP_EOL;
        echo "#{$this->server->worker_id}\tonTask: [PID={$this->server->worker_pid}]: 
        task_id=$this->task_id, data_len=" . strlen($this->data) . "." . PHP_EOL;
        return $this->data;
    }
}
