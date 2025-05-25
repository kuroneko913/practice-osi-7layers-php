<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;
use App\Layers\TransportLayer;
use App\Layers\SessionLayer;
use App\Layers\PresentationLayer;
use App\Layers\ApplicationLayer;
use App\Layers\DataLinkLayer\Factory as DataLinkLayerFactory;
use App\Layers\NetworkLayer\Factory as NetworkLayerFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

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

// ログディレクトリの作成
$logDir = dirname(__DIR__, 1) . '/logs';
if (!is_dir($logDir)) {
    echo "ログディレクトリが存在しません。作成します。\n";
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/receiver.log';
$logger = new Logger('receiver');
$logger->pushHandler(new StreamHandler($logFile, Logger::INFO));

// FIFOファイルの初期化(物理ケーブルの抜き差し相当)
$cablePath = getenv('FIFO_PATH') ?? null;
if (file_exists($cablePath)) {
    unlink($cablePath);
}
posix_mkfifo($cablePath, 0666);

// 別なレイヤーで追加されるはずだが今はここで設定
// 自分のMACアドレス
$receiverMac = getenv('MACADDRESS') ?? null;
$receiverIp = getenv('IPADDRESS') ?? null;
$type = '0x0800';

echo "start receiver ($receiverMac)\n\n";

// 各層のインスタンスを作成
$physicalLayer = new PhysicalLayer($cablePath, 'r');
// どこから来たのかはframeのsrcで判断する
$dataLinkLayer = DataLinkLayerFactory::createEthernet($physicalLayer, type:$type, to:$receiverMac, logger:$logger);
$networkLayer = NetworkLayerFactory::createStringIPPacket(lowerLayer:$dataLinkLayer, receiverIp:$receiverIp, logger:$logger);
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 受信側のインスタンスを作成
$receiver = new Receiver($applicationLayer);
$receiver->run();
echo "end receiver\n";
