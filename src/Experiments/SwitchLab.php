<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer;
use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\EthernetFrame;
use App\Layers\DataLinkLayer\Factory as DataLinkLayerFactory;
use App\Layers\DataLinkLayer\FrameInterface;
use App\Layers\DataLinkLayer\MacAddress;
use App\Layers\DuplexPhysicalLayer;
use App\Layers\PhysicalLink;

/**
 * L2スイッチと4台のPC (A, B, C, D) からなる実験環境
 * 実験ごとに生成し、MACアドレステーブルが空の状態から始める
 */
class SwitchLab
{
    private const NODES = [
        'A' => ['port' => 1, 'mac' => 'aabbccddee0a'],
        'B' => ['port' => 2, 'mac' => 'aabbccddee0b'],
        'C' => ['port' => 3, 'mac' => 'aabbccddee0c'],
        'D' => ['port' => 4, 'mac' => 'aabbccddee0d'],
    ];

    /** @var array<string, array{phy: DuplexPhysicalLayer, link: PhysicalLink, dl: DataLinkLayer, mac: MacAddress, port: int}> */
    private array $pcs = [];

    /** @var array<int, array{phy: DuplexPhysicalLayer, link: PhysicalLink}> PCを移動した後のポート */
    private array $vacantPorts = [];

    /** @var resource|null */
    private $switchProc = null;

    private string $switchLogPath;

    private string $fifoDir;

    /**
     * @param int $portCount スイッチのポート数 (PCの移動先を用意する場合は5以上)
     * @param array<string, string> $switchEnv スイッチの設定 (MAC_AGING_SECONDS, MAC_TABLE_SIZE)
     */
    public function __construct(private int $portCount = 4, array $switchEnv = [])
    {
        $root = dirname(__DIR__, 2);
        $this->switchLogPath = "$root/logs/switch.log";
        // 別のスイッチが動いていると伝送線を奪い合うので、FIFO_DIR で分離できるようにする
        $this->fifoDir = getenv('FIFO_DIR') ?: "$root/tmp/switch";
        $this->startSwitch("$root/src/switch.php", $switchEnv);
        $this->connectPcs();
    }

    /**
     * @param array<string, string> $switchEnv
     */
    private function startSwitch(string $switchScript, array $switchEnv): void
    {
        if (!is_dir(dirname($this->switchLogPath))) {
            mkdir(dirname($this->switchLogPath), 0777, true);
        }
        file_put_contents($this->switchLogPath, ''); // ログクリア

        echo "L2スイッチ (4ポート) を起動しています...\n";
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->switchLogPath, 'a'],
            2 => ['file', $this->switchLogPath, 'a'],
        ];
        $env = $switchEnv + ['PORT_COUNT' => (string)$this->portCount] + getenv();
        $this->switchProc = proc_open([PHP_BINARY, $switchScript], $descriptors, $pipes, null, $env);

        // スイッチの初期化（FIFO作成など）待ち
        usleep(300000); // 300ms
    }

    private function connectPcs(): void
    {
        echo "4台のPC (A, B, C, D) のNICと物理層を初期化しています...\n";
        foreach (self::NODES as $name => $node) {
            $this->plugIn($name, $node['port'], new MacAddress($node['mac']));
            echo "  ✓ PC-$name: Port {$node['port']}, MAC: {$node['mac']}\n";
        }
        echo "\n";
    }

    private function openPort(int $port): DuplexPhysicalLayer
    {
        $tx = "{$this->fifoDir}/port_{$port}_to_switch";
        $rx = "{$this->fifoDir}/switch_to_port_{$port}";
        return new DuplexPhysicalLayer($tx, $rx, intervalUs: 0); // 実験のため高速送信
    }

    private function plugIn(string $name, int $port, MacAddress $mac): void
    {
        $link = new PhysicalLink("{$this->fifoDir}/port_{$port}.link");
        if (!$link->plugIn()) {
            throw new \RuntimeException("Port $port にはすでに別のPCが接続しています");
        }
        $phy = $this->openPort($port);
        // 接続前に伝送線に残っていたデータは受け取らない
        $phy->clear();
        // 自分のMACアドレスのみを登録してデータリンク層を初期化
        $dl = DataLinkLayerFactory::createEthernet($phy, from: $mac);
        $this->pcs[$name] = [
            'phy' => $phy,
            'link' => $link,
            'dl' => $dl,
            'mac' => $mac,
            'port' => $port,
        ];
    }

    /**
     * PCを別のポートへ移動する。フレームは送信しないのでスイッチには伝わらない
     * 元のポートの先にはハブがあり、PCが移動してもリンクは切れない構成を想定している
     */
    public function movePc(string $name, int $newPort): void
    {
        $this->vacantPorts[$this->pcs[$name]['port']] = [
            'phy' => $this->pcs[$name]['phy'],
            'link' => $this->pcs[$name]['link'],
        ];
        $this->plugIn($name, $newPort, $this->pcs[$name]['mac']);
    }

    /**
     * 2台のPCの接続先を入れ替える。フレームは送信しないのでスイッチには伝わらない
     * ポートの先にはハブがあり、入れ替えてもリンクは切れない構成を想定している
     */
    public function swapPorts(string $a, string $b): void
    {
        $pcA = $this->pcs[$a];
        $pcB = $this->pcs[$b];
        $this->pcs[$a] = $this->connectTo($pcA, $pcB);
        $this->pcs[$b] = $this->connectTo($pcB, $pcA);
    }

    /**
     * PCの設定のまま、別のPCがつないでいた伝送線につなぎ替える
     * @param array{mac: MacAddress} $pc
     * @param array{phy: DuplexPhysicalLayer, link: PhysicalLink, port: int} $line
     * @return array{phy: DuplexPhysicalLayer, link: PhysicalLink, dl: DataLinkLayer, mac: MacAddress, port: int}
     */
    private function connectTo(array $pc, array $line): array
    {
        $dl = DataLinkLayerFactory::createEthernet($line['phy'], from: $pc['mac']);
        return [
            'phy' => $line['phy'],
            'link' => $line['link'],
            'dl' => $dl,
            'mac' => $pc['mac'],
            'port' => $line['port'],
        ];
    }

    /**
     * PCを移動した後のポートに届いたフレーム数を数える
     */
    public function countVacantPortFrames(int $port, int $waitMs = 300): int
    {
        usleep($waitMs * 1000);
        $count = 0;
        while ($this->vacantPorts[$port]['phy']->tryReceive() !== null) {
            $count++;
        }
        return $count;
    }

    /**
     * PCのケーブルを抜く
     */
    public function unplug(string $name, int $waitMs = 200): void
    {
        $this->pcs[$name]['link']->unplug();
        usleep($waitMs * 1000); // スイッチがリンクダウンを検知するまで待つ
    }

    /**
     * PCのケーブルを元のポートへ挿し直す
     */
    public function plugBack(string $name, int $waitMs = 200): void
    {
        $pc = $this->pcs[$name];
        $this->plugIn($name, $pc['port'], $pc['mac']);
        usleep($waitMs * 1000); // スイッチがリンクアップを検知するまで待つ
    }

    public function setPromiscuous(string $name): void
    {
        $this->pcs[$name]['dl']->setPromiscuous(true);
    }

    public function mac(string $name): MacAddress
    {
        return $this->pcs[$name]['mac'];
    }

    /**
     * 送信元MACアドレスを指定してフレームを流す (MACアドレスの詐称)
     */
    public function transmitAs(string $from, MacAddress $src, MacAddress $to, string $payload): void
    {
        $padded = str_pad($payload, EthernetFrame::MIN_PAYLOAD_BYTES, "\0");
        $frame = new EthernetFrame($to, $src, EtherType::LocalExperimental->toInt(), $padded);
        $this->transmit($from, $frame->toWire());
    }

    public function send(string $from, string $message, MacAddress $to): void
    {
        $this->pcs[$from]['dl']->send("$message\n", $to);
    }

    /**
     * プロトコル種別を指定してフレームを送信する
     */
    public function sendFrame(string $from, string $payload, MacAddress $to, EtherType $type): void
    {
        $this->pcs[$from]['dl']->sendFrame($payload, $to, $type);
    }

    /**
     * データリンク層を通さず、信号をそのまま伝送線に流す (壊れたフレームなどの実験用)
     */
    public function transmit(string $from, string $signal): void
    {
        $this->pcs[$from]['phy']->send($signal);
    }

    /**
     * 指定したPCのデータリンク層からフレームを受信する
     */
    public function receive(string $name): ?FrameInterface
    {
        return $this->pcs[$name]['dl']->receive();
    }

    /**
     * 送信元以外の各PCの受信結果を確認する
     * @return array<string, array{arrived: bool, accepted: bool, payload: ?string, etherType: ?int}>
     */
    public function observe(string $sender, int $waitMs = 300): array
    {
        usleep($waitMs * 1000);
        $results = [];

        foreach ($this->pcs as $name => $pc) {
            if ($name === $sender) {
                continue;
            }

            $read = [$pc['phy']->getRxStream()];
            $write = null;
            $except = null;
            // データが到着しているか
            if (@stream_select($read, $write, $except, 0, 10000)) {
                // 受信試行
                $frame = $pc['dl']->receive();
                $results[$name] = [
                    'arrived' => true,
                    'accepted' => $frame !== null,
                    // パディングの除去は本来上位層の役割。文字列メッセージには長さ情報がないのでここで除去する
                    'payload' => $frame === null ? null : rtrim($frame->getPayload(), "\0"),
                    'etherType' => $frame?->getEtherType(),
                ];
            } else {
                $results[$name] = ['arrived' => false, 'accepted' => false, 'payload' => null, 'etherType' => null];
            }
        }
        return $results;
    }

    /**
     * 準備のために流したフレームを各PCの受信線から取り除く
     */
    public function settle(int $waitMs = 300): void
    {
        usleep($waitMs * 1000);
        foreach ($this->pcs as $pc) {
            while ($pc['phy']->tryReceive() !== null) {
            }
        }
    }

    /**
     * スイッチのログを表示してスイッチを停止する
     */
    public function finish(): void
    {
        echo "【スイッチ内部の動作ログ (switch.log)】\n";
        echo file_get_contents($this->switchLogPath);
        $this->stop();
    }

    private function stop(): void
    {
        if (is_resource($this->switchProc)) {
            proc_terminate($this->switchProc);
            proc_close($this->switchProc);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
