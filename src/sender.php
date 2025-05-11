<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;

class Sender
{
    /**
     * @var LayerInterface
     */
    private $layer;

    public function __construct(LayerInterface $layer)
    {
        $this->layer = $layer;
    }

    public function run()
    {
        // 標準入力からデータを受け取り、FIFOに1bitずつ書き込む
        $input = trim(fgets(STDIN));
        for ($i = 0; $i < strlen($input); $i++) {
            $this->log($input[$i]);
            $this->layer->send($input[$i]);
        }
    }

    private function log($message)
    {
        $now = microtime(true);
        $formatted = date("Y-m-d H:i:s") . sprintf(".%03d", ($now - floor($now)) * 1000);
        echo "[SEND] $message at $formatted\n";
    }
}

echo "start sender\n";
$fifo = '/tmp/bitfifo';
$writeMode = 'w';
$physicalLayer = new PhysicalLayer($fifo, $writeMode);
$sender = new Sender($physicalLayer);
$sender->run();
echo "end sender\n";
