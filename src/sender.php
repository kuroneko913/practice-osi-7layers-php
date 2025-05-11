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

// 各層のインスタンスを作成
$physicalLayer = new PhysicalLayer('/tmp/bitfifo', 'w');
$dataLinkLayer = new DataLinkLayer($physicalLayer);
$networkLayer = new NetworkLayer($dataLinkLayer);
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 送信側のインスタンスを作成
$sender = new Sender($applicationLayer);
$sender->run();
echo "end sender\n";
