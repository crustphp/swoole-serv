<?php
include 'wbsocketclient.php';
//$w = new WebSocketClient('45.76.35.99', 9501);
$w = new WebSocketClient('127.0.0.1', 9501);
if ($x = $w->connect()) {
//    var_dump($x);
    for ($i=1;$i<4;$i++)
        $w->send('test'.$i, 'text', 0);
    $w->send('end', 'text', 1);
    while(true) {
        $data = $w->recv();
        if ($data) {
            var_export($data);
            sleep(1);
        } else {
            break;
        }
    }
} else {
    echo "Could not connect to server".PHP_EOL;
}



/*
 *
 * use Swoole\Coroutine\HTTP\Client;

Co\run(function()
{
    $client = new Co\http\Client("127.0.0.1", 9501);

    $ret = $client->upgrade("/");

    if($ret)
    {
        while(true)
        {
            $client->push("Hello World!");
            var_dump($client->recv());
            Co\System::sleep(5);
        }
    }
});

 */
