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

        echo "Task Worker Process received data".PHP_EOL;
//        echo "#{$this->server->worker_id}\tonTask: [PID={$this->server->worker_pid}]:
//        task_id=$this->task_id, data_len=" . strlen($this->data) . "." . PHP_EOL;
        return $this->task->data;
    }
}
