<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\PhysicalLayer;

class Receiver
{
    /**
     * @var PhysicalLayer
     */
    private $physicalLayer;

    public function __construct(PhysicalLayer $physicalLayer)
    {
        $this->physicalLayer = $physicalLayer;
    }

    public function run()
    {
        while (true) {
            $bit = $this->physicalLayer->receiveBit();
            if ($bit === false || $bit === '') {
                usleep(100000);
                continue;
            }
            $this->log($bit);
        }
    }

    private function log($message)
    {
        $now = microtime(true);
        $formatted = date("Y-m-d H:i:s") . sprintf(".%03d", ($now - floor($now)) * 1000);
        echo "[RECV] $message at $formatted\n";
    }
}

echo "start receiver\n";
$fifo = '/tmp/bitfifo';
$readMode = 'r';
$physicalLayer = new PhysicalLayer($fifo, $readMode);
$receiver = new Receiver($physicalLayer);
$receiver->run();
echo "end receiver\n";
