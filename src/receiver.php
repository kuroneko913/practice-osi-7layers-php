<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;
use App\Layers\DataLinkLayer;
use App\Layers\NetworkLayer;
use App\Layers\TransportLayer;
use App\Layers\SessionLayer;
use App\Layers\PresentationLayer;
use App\Layers\ApplicationLayer;

class Receiver
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
        while (true) {
            $data = $this->layer->receive();
            if ($data === false || $data === '') {
                usleep(100000);
                continue;
            }
            $this->log($data);
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

// 各層のインスタンスを作成
$physicalLayer = new PhysicalLayer('/tmp/bitfifo', 'r');
$dataLinkLayer = new DataLinkLayer($physicalLayer);
$networkLayer = new NetworkLayer($dataLinkLayer);
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 受信側のインスタンスを作成
$receiver = new Receiver($applicationLayer);
$receiver->run();
echo "end receiver\n";
