<?php

class LongTasks
{
    protected $server;
    protected $task;

    public function __construct($server, $task){
        $this->server = $server;
        $this->task = $task;
    }

    public function handle($task=null, $data=null){
//        $this->task->data;
//        $this->task->dispatch_time;
//        $this->task->id;
//        $this->task->worker_id;
//        $this->task->flags;

        echo PHP_EOL."\nTaskWorker-ID {$this->server->worker_id} received data...
        from EventWorker-ID {$this->task->worker_id} ...
        to process Task# {$this->task->id}\n".PHP_EOL;
//        echo "#{$this->server->worker_id}\tonTask: [PID={$this->server->worker_pid}]:
//        task_id=$this->task_id, data_len=" . strlen($this->data) . "." . PHP_EOL;
        return $this->task->data;
    }
}
