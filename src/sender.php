<?php

namespace App;

class Sender    
{
    /**
     * @var resource|false
     */
    private $fifo;

    public function __construct()
    {
        $this->fifo = fopen('/tmp/bitfifo', 'w');
    }

    public function run()
    {
        // 標準入力からデータを受け取り、FIFOに1bitずつ書き込む
        $input = trim(fgets(STDIN));
        $fifo = fopen('/tmp/bitfifo', 'w');

        for ($i = 0; $i < strlen($input); $i++) {
            $this->log($input[$i]);
            fwrite($fifo, $input[$i]);
        }
        fclose($fifo);
    }

    private function log($message)
    {
        $now = microtime(true);
        $formatted = date("Y-m-d H:i:s") . sprintf(".%03d", ($now - floor($now)) * 1000);
        echo "[SEND] $message at $formatted\n";
    }
}

echo "start sender\n";
$sender = new Sender();
$sender->run();
echo "end sender\n";
