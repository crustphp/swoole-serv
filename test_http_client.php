<?php

include_once __DIR__.'/helper.php';

ini_set('memory_limit', -1);

use Swoole\Coroutine as SwooleCo;
use Swoole\Coroutine\Http\Client as SwooleClient;

use OpenSwoole\Coroutine as OpenSwooleCo;
use OpenSwoole\Coroutine\Http\Client as OpenSwooleClient;


list($isSwoole, $swoole_ext) = extension_loaded('swoole') ? [true, 'sw'] : (extension_loaded('openswoole') ? [true, 'osw'] : [false, 'none']);
$coroutineHttpClient = function() use ($isSwoole, $swoole_ext) {
    global $argv;
    if ($isSwoole) {
        if ($swoole_ext == 'sw') {
            $client = new SwooleClient("localhost", 9501, false);
        } else if (extension_loaded('openswoole')) {
            $client = new OpenSwooleClient("localhost", 9501, false);
        }
    } else {
        echo "Swoole / OpenSwoole Extension not available".PHP_EOL;
        exit;
    }

//    $client->setHeaders([
//        'Host' => 'localhost',
//        'User-Agent' => 'Chrome/49.0.2587.3',
//        'Accept' => 'text/html,application/xhtml+xml,application/xml',
//        'Accept-Encoding' => 'gzip',
//    ]);

    //$http_method = 'GET';
    $http_method = 'POST';

    $cookie = "8MLP_5753_saltkey=RSU8HYED; 8MLP_5753_lastvisit=1426120671; pgv_pvi=1454765056; CNZZDATA1000008050=684878078-1426123263-http%253A%252F%252Fcznews-team.chinaz.com%252F%7C1426485386; attentiondomain=2z.cn%2cchinaz.com%2ckuaishang.cn%2ccxpcms.com; CNZZDATA33217=cnzz_eid%3D1036784254-1426122273-http%253A%252F%252Fcznews-team.chinaz.com%252F%26ntime%3D1427414208; CNZZDATA433095=cnzz_eid%3D1613871160-1426123273-http%253A%252F%252Fcznews-team.chinaz.com%252F%26ntime%3D1427848205; CNZZDATA1254679775=309722566-1427851758-http%253A%252F%252Fcznews-team.chinaz.com%252F%7C1427851758; 8MLP_5753_security_cookiereport=c014Hgufskpv55xgM9UaB%2FZZdMrcN0QqBYdcGomTu8OlTDWzTA0z; 8MLP_5753_ulastactivity=e4a1aRIbgdzoRDd8NlT5CMIwLnWjyjr2hWyfn6T5g82RitUOdf3o; 8MLP_5753_auth=9351LJpv7Xa%2FPUylJDQgRiAONZ5HysOaj%2BqRGb6jYmpqZpRkVc2ibPXm7LAfArC%2FpIpY2Fx%2B59AHqzr843qozZWxWNZi; mytool_user=uSHVgCUFWf5Sv2Y8tKytQRUJW3wMVT3rw5xQLNGQFIsod4C6vYWeGA==; 8MLP_5753_lip=220.160.111.22%2C1428036585; pgv_si=s4245709824; PHPSESSID=t3hp9h4o8rb3956t5pajnsfab1; 8MLP_5753_st_p=1024432%7C1428040399%7Cf7599ba9053aa27e12e9e597a4c372ce; 8MLP_5753_viewid=tid_7701248; 8MLP_5753_smile=5D1; 8MLP_5753_st_t=1024432%7C1428040402%7C46d40e02d899b10b431822eb1d39f6a1; 8MLP_5753_forum_lastvisit=D_140_1427103032D_165_1427427405D_168_1427870172D_167_1427870173D_166_1428021390D_163_1428040402; 8MLP_5753_sid=k25gxK; 8MLP_5753_lastact=1428040403%09misc.php%09patch; cmstop_page-view-mode=view; cmstop_rememberusername=error; cmstop_auth=Jcn2qzVn9nsjqtodER9OphcW3PURDWNx6mO7j0Zbb9k%3D; cmstop_userid=6; cmstop_username=error; Hm_lvt_aecc9715b0f5d5f7f34fba48a3c511d6=1427967317,1428021376,1428036617,1428040224; Hm_lpvt_aecc9715b0f5d5f7f34fba48a3c511d6=1428050417; YjVmNm_timeout=0";
    $accessToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI5YzA2M2NkMi0xZGZkLTRmNmItYmNhOS0xODQ3YjhiODcwZjgiLCJqdGkiOiIzNGM3YzA1ZDQ4NTQ3ZDI1ODIyMmZlOGQ4N2ViOTJiYzRhYmU3Yzk5Y2IwMDZjNGE3N2U0NGViNTkzNmQwMGIwNTlhZGUyNzQ5YzY0NjJkYiIsImlhdCI6MTcxNzc4MDQ1My42MDExNzYsIm5iZiI6MTcxNzc4MDQ1My42MDExNzgsImV4cCI6MTc0OTMxNjQ1My41OTc2MTQsInN1YiI6IjE2Iiwic2NvcGVzIjpbXX0.q5r1DQ8rnSFc1-IdID6uAOvceA25L_XLNm3j3YZK0ffSrmFSPCd9G52s6vLIoeKM-TNJjrCItXh19pyJew-1YeQj_eyHl4-xZhUVbfYudO7pRe-vrX20zr8072VA2LdDr6GNN9odQaPfAhKrj9tjKFVHEVlfsdg_8Mbxoj4t-kidN2FxSlLTQ9gOsOfn3tN4MSHHUDwQaWCXPN_Uau0kD2plGWxVNOuKrc0hYj-GzfF7MXFaRos_oNT-nuvNMx0jbby56tXPHTQhOphBNuMB_3SWxc_-TuwYOVCaQRSwhNAhxPMCRUDcUDt9iaSqADo6rPY7feYU9nfbEADOFnmYoaFQXfaFIJoL6skf9gC6bq9QSlgu_ZLWdfoe0P_DRb0J7hdtpmxGxyxIKm6S71wj-TBmZM5uKq5zmg6dr4T7lnCzD3zVwm_Uwskznv2uJMC-XB-nHrfOo7wn-6BmYcAbiTnGWt67CsZLGHihZN5w7jFPRPBGWtZnUcht_Pj0Svsu_x4bDjulxpmf-hyjLP-5w_Wn4snktMWsLDrCBvFZNsIuu-TwK5RU7b2F5EiE1WlGhxng_IsIaduz6GrDL3tvVH_SOjXmuksz2v49Q3gdi6uqTxkJfdD1TnlfSEqJcsoL30KA9xQQ6f6KATrnMp0DfducMZw9bt8IyCBX1_MRV7c";

    if ($http_method == 'GET')
    {
        $header = "GET / HTTP/1.1\r\n";
        $header .= "Host: 127.0.0.1\r\n";
        $header .= "Connection: keep-alive\r\n";
        $header .= "Cache-Control: max-age=0\r\n";
        $header .= "Cookie: $cookie\r\n";
        $header .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n";
        // User Agent can be 'swoole-http-client'
        $header .= "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.116 Safari/537.36\r\n";
        $header .= "\r\n";
        $_sendStr = $header;
    }
    else
    {
//    $header = "POST /home/explore/?hello=123&world=swoole#hello HTTP/1.1\r\n";
        $header = "POST / HTTP/1.1\r\n";
        $header .= "Host: 127.0.0.1\r\n";
        $header .= "Referer: http://group.swoole.com/\r\n";
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $header .= "Accept-Language: zh-CN,zh;q=0.8,en;q=0.6,zh-TW;q=0.4,ja;q=0.2\r\n";
        $header .= "Cookie: pgv_pvi=9559734272; efr__Session=uddfvbm87dtdtrdsro1ohlt4o6; efr_r_uname=apolov%40vip.qq.com; efr__user_login=3N_b4tHW1uXGztWW2Ojf09vssOjR5abS4abO5uWRopnm0eXb7OfT1NbIoqjWzNCvodihq9qaptqfra6imtLXpNTNpduVoque26mniKej5dvM09WMopmmpM2xxcmhveHi3uTN0aegpaiQj8Snoa2IweHP5fCL77CmxqKqmZKp5ejN1c_Q2cPZ25uro6mWqK6BmMOzy8W8k4zi2d3Nlb_G0-PaoJizz97l3deXqKyPoKacr6ynlZ2nppK71t7C4uGarKunlZ-s; pgv_si=s8426935296; Hm_lvt_4967f2faa888a2e52742bebe7fcb5f7d=1410240641,1410241802,1410243730,1410243743; Hm_lpvt_4967f2faa888a2e52742bebe7fcb5f7d=1410248408\r\n";
        $header .= "RA-Ver: 2.5.3\r\n";
        $header .= "RA-Sid: 2A784AF7-20140212-113827-085a9c-c4de6e\r\n";
        //$header .= "Bearer $accessToken\r\n";

        $_postData = ['body1' => 'swoole_http-server', 'message' => $argv[1]];
        $_postBody = json_encode($_postData);
//    $_postBody = http_build_query($_postData);
        $header .=  "Content-Length: " . strlen($_postBody);
        echo "http header length=".strlen($header)."\n";
        $header .=  "Content-Length: " . (strlen($_postBody) - 2);

//    $cli->send($header);
//    usleep(100000);
        $_sendStr = $header . "\r\n\r\n" . $_postBody;
//    $_sendStr = "\r\n\r\n" . $_postBody;
        echo "postBody length=".strlen($_postBody)."\n";
    }

    // https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
    $client->set(['timeout' => 1]);

    // Better form to set header
    // $client->setHeaders(array('User-Agent' => 'swoole-http-client'));
    $client->setHeaders([
        $header
    ]);

// For Swoole\Http\Client get() can be used as below
//    $client->get('/?dump.php?corpid=ding880f44069a80bca1&corpsecret=YB1cT8FNeN7VCm3eThwDAncsmSl4Ajl_1DmckaOFmOZhTFzexLbIzq5ueH3YcHrx', function ($client) {
//        var_dump($client);
//        var_dump($client->headers);
//        echo $client->body;
//        //$client->close();
//    });
    $client->post('/', $_postData);

    highlight_string($client->getBody()).PHP_EOL;

    // Currently works only with Swoole, not OpenSwoole
//    if (extension_loaded('swoole')) {
//        $client->on('close', function($_cli) {
//            echo "connection is closed\n";
//        });
//    }

    $client->close();
};

if ($isSwoole) {
    if ($swoole_ext == 'sw') {
        SwooleCo\run($coroutineHttpClient);
    } else {
        OpenSwooleCo::run($coroutineHttpClient);
    }
}
else {
    echo "Swoole / OpenSwoole Extension not available".PHP_EOL;
    exit;
}



