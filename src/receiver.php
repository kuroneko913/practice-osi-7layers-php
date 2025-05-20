<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;
use App\Layers\NetworkLayer;
use App\Layers\TransportLayer;
use App\Layers\SessionLayer;
use App\Layers\PresentationLayer;
use App\Layers\ApplicationLayer;
use App\Layers\DataLinkLayer\Factory;

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

// FIFOファイルの初期化(物理ケーブルの抜き差し相当)
$cablePath = '/fifo/bitfifo';
if (file_exists($cablePath)) {
    unlink($cablePath);
}
posix_mkfifo($cablePath, 0666);

// 別なレイヤーで追加されるはずだが今はここで設定
$receiverMac = 'aabbccddee02';
$type = '0x0800';

echo "start receiver ($receiverMac)\n\n";

// 各層のインスタンスを作成
$physicalLayer = new PhysicalLayer($cablePath, 'r');
// どこから来たのかはframeのsrcで判断する
$dataLinkLayer = Factory::createBit($physicalLayer, type:$type, to:$receiverMac);
$networkLayer = new NetworkLayer($dataLinkLayer);
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 受信側のインスタンスを作成
$receiver = new Receiver($applicationLayer);
$receiver->run();
echo "end receiver\n";
