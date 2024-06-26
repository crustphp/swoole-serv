<?php

// Ref:= https://gist.github.com/NHZEX/946275e9b32a832d579a36c5d3d0b7fc
// https://github.com/nonunicorn/php-swoole-websocket-client/blob/main/client.php

//use Exception;
use Swoole\Client;
use Swoole\WebSocket\Server;

// https://github.com/swoole/swoole-src/blob/master/examples/websocket/WebSocketClient.php
class WebSocketClient
{
    const VERSION = '0.1.4';

    const TOKEN_LENGHT = 16;
    const TYPE_ID_WELCOME = 0;
    const TYPE_ID_PREFIX = 1;
    const TYPE_ID_CALL = 2;
    const TYPE_ID_CALLRESULT = 3;
    const TYPE_ID_ERROR = 4;
    const TYPE_ID_SUBSCRIBE = 5;
    const TYPE_ID_UNSUBSCRIBE = 6;
    const TYPE_ID_PUBLISH = 7;
    const TYPE_ID_EVENT = 8;

    const OPCODE_CONTINUATION_FRAME = 0x0;
    const OPCODE_TEXT_FRAME = 0x1;
    const OPCODE_BINARY_FRAME = 0x2;
    const OPCODE_CONNECTION_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xa;

    const CLOSE_NORMAL = 1000;
    const CLOSE_GOING_AWAY = 1001;
    const CLOSE_PROTOCOL_ERROR = 1002;
    const CLOSE_DATA_ERROR = 1003;
    const CLOSE_STATUS_ERROR = 1005;
    const CLOSE_ABNORMAL = 1006;
    const CLOSE_MESSAGE_ERROR = 1007;
    const CLOSE_POLICY_ERROR = 1008;
    const CLOSE_MESSAGE_TOO_BIG = 1009;
    const CLOSE_EXTENSION_MISSING = 1010;
    const CLOSE_SERVER_ERROR = 1011;
    const CLOSE_TLS = 1015;

    /** @var string */
    private $key;
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $path;

    /** @var Client */
    private $socket;
    /** @var string */
    private $buffer = '';
    /** @var string|null */
    private $origin = null;

    /** @var bool */
    private $connected = false;

    /** @var bool */
    public $returnData = false;

    /**
     * @param string $host
     * @param int $port
     * @param string $path
     * @param string $origin
     */
    public function __construct($host = '127.0.0.1', $port = 9501, $path = '/', string $origin = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->origin = $origin;
        $this->key = $this->generateToken(self::TOKEN_LENGHT);
    }

    /**
     * Disconnect on destruct
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect client to server
     *
     * @return bool
     * @throws Exception
     */
    public function connect()
    {
        $this->socket = new Client(SWOOLE_SOCK_TCP);
        if (!$this->socket->connect($this->host, $this->port)) {
            return false;
        }
        $this->socket->send($this->createHeader());
        return $this->recv();
    }

    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Disconnect from server
     */
    public function disconnect()
    {
        if ($this->socket instanceof Client) {
            $this->connected = false;
            $this->socket->close();
        }
    }

    public function close($code = self::CLOSE_NORMAL, $reason = '')
    {
        $data = pack('n', $code) . $reason;
        return $this->socket->send(Server::pack($data, self::OPCODE_CONNECTION_CLOSE, true));
    }

    /**
     * @return false|string
     * @throws Exception
     */
    public function recv()
    {
        $data = $this->socket->recv();
        if ($data === false) {
            /** @noinspection PhpUndefinedFieldInspection */
            echo "Error: {$this->socket->errMsg}";
            return false;
        }
        $this->buffer .= $data;
        $recv_data = $this->parseData($this->buffer);
        if ($recv_data) {
            $this->buffer = '';
            return $recv_data;
        } else {
            return false;
        }
    }

    /**
     * @param string $data
     * @param string $type
     * @param bool $masked
     * @return bool
     */
    public function send($data, $type = 'text', $masked = false) {
        switch ($type) {
            case 'text':
                $_type = WEBSOCKET_OPCODE_TEXT;
                break;
            case 'binary':
            case 'bin':
                $_type = WEBSOCKET_OPCODE_BINARY;
                break;
            case 'ping':
                $_type = WEBSOCKET_OPCODE_PING;
                break;
            default:
                return false;
        }
        return $this->socket->send(Server::pack($data, $_type, $masked));
    }

    /**
     * Parse received data
     *
     * @param $response
     * @return bool
     * @throws Exception
     */
    private function parseData($response)
    {
        if (!$this->connected) {
            $response = $this->parseIncomingRaw($response);
            if (isset($response['Sec-Websocket-Accept'])
                && base64_encode(
                    pack('H*', sha1($this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
                ) === $response['Sec-Websocket-Accept']
            ) {
                $this->connected = true;
                return true;
            } else {
                throw new Exception("error response key.");
            }
        }

//        echo "In parse Data".$this->returnData.PHP_EOL;
//        var_dump($response);

        $frame = Server::unpack($response);
        if ($frame) {
            return $this->returnData ? $frame->data : $frame;
        } else {
            throw new Exception("swoole_websocket_server::unpack failed.");
        }
    }

    /**
     * Create header for websocket client
     *
     * @return string
     */
    private function createHeader()
    {
        $host = $this->host;
        if ($host === '127.0.0.1' || $host === '0.0.0.0') {
            $host = 'localhost';
        }
        return "GET {$this->path} HTTP/1.1" . "\r\n" .
            "Origin: {$this->origin}" . "\r\n" .
            "Host: {$host}:{$this->port}" . "\r\n" .
            "Sec-WebSocket-Key: {$this->key}" . "\r\n" .
            "User-Agent: PHPWebSocketClient/" . self::VERSION . "\r\n" .
            "Upgrade: websocket" . "\r\n" .
            "Connection: Upgrade" . "\r\n" .
            "Sec-WebSocket-Protocol: wamp" . "\r\n" .
            "Sec-WebSocket-Version: 13" . "\r\n" . "\r\n";
    }

    /**
     * Parse raw incoming data
     *
     * @param $header
     *
     * @return array
     */
    private function parseIncomingRaw($header)
    {
        $retval = [];
        $content = "";
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach ($fields as $field) {
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace_callback(
                    '/(?<=^|[\x09\x20\x2D])./',
                    function ($matches) {
                        return strtoupper($matches[0]);
                    },
                    strtolower(trim($match[1]))
                );
                if (isset($retval[$match[1]])) {
                    $retval[$match[1]] = [$retval[$match[1]], $match[2]];
                } else {
                    $retval[$match[1]] = trim($match[2]);
                }
            } else {
                if (preg_match('!HTTP/1\.\d (\d)* .!', $field)) {
                    $retval["status"] = $field;
                } else {
                    $content .= $field . "\r\n";
                }
            }
        }
        $retval['content'] = $content;
        return $retval;
    }

    /**
     * Generate token
     *
     * @param int $length
     *
     * @return string
     */
    private function generateToken($length)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
        $useChars = [];
        // select some random chars:
        for ($i = 0; $i < $length; $i++) {
            $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
        }
        // Add numbers
        array_push($useChars, mt_rand(0, 9), mt_rand(0, 9), mt_rand(0, 9));
        shuffle($useChars);
        $randomString = trim(implode('', $useChars));
        $randomString = substr($randomString, 0, self::TOKEN_LENGHT);
        return base64_encode($randomString);
    }

    /**
     * Generate token
     *
     * @param int $length
     *
     * @return string
     */
    public function generateAlphaNumToken($length)
    {
        $characters = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        srand((float)microtime() * 1000000);
        $token = '';
        do {
            shuffle($characters);
            $token .= $characters[mt_rand(0, (count($characters) - 1))];
        } while (strlen($token) < $length);
        return $token;
    }
}
