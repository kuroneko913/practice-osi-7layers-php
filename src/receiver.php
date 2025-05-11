<?php

namespace App;

class Receiver
{
    /**
     * @var resource|false
     */
    private $fifo;

    public function __construct()
    {
        $this->fifo = fopen('/tmp/bitfifo', 'r');
    }

    public function run()
    {
        while (true) {
            $bit = fread($this->fifo, 1);
            if ($bit === false || $bit === '') {
                usleep(100000);
                continue;
            }
            $this->log($bit);
        }
        fclose($this->fifo);
    }

    private function log($message)
    {
        $now = microtime(true);
        $formatted = date("Y-m-d H:i:s") . sprintf(".%03d", ($now - floor($now)) * 1000);
        echo "[RECV] $message at $formatted\n";
    }
}

echo "start receiver\n";
$receiver = new Receiver();
$receiver->run();
echo "end receiver\n";
