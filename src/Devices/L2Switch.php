<?php

namespace App\Devices;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\EthernetFrame;
use App\Layers\DataLinkLayer\EthernetFrameParser;
use App\Layers\DataLinkLayer\FrameInterface;
use App\Layers\DataLinkLayer\FrameParserInterface;
use App\Layers\DataLinkLayer\MacAddress;
use App\Layers\DuplexPhysicalLayer;
use App\Layers\PhysicalLink;

/**
 * L2 イーサネットスイッチ (Bridge)
 *
 * 各ポートの物理層から受け取った信号をフレームとして解釈し、
 * 宛先MACアドレスに応じて適切なポートへ転送（ストア＆フォワード）します。
 * EtherTypeは解釈しないため、未知のプロトコルのフレームもそのまま転送します。
 *
 * 主な機能:
 * 1. FCS検査 - 壊れたフレームの破棄
 * 2. MACアドレス学習 (Learning)
 * 3. ユニキャスト転送 (Forwarding)
 * 4. フラッディング (Flooding) - 未知の宛先 / ブロードキャスト / マルチキャスト
 * 5. フィルタリング (Filtering) - 送信元と同一ポート宛ての破棄
 * 6. エージング (Aging) - 一定時間送信のないMACアドレスをテーブルから削除
 * 7. リンク状態の監視 - PCが接続していないポートへは転送せず、リンクダウンしたポートのエントリを削除
 */
class L2Switch
{
    /** @var array<int, array{phy: DuplexPhysicalLayer, link: PhysicalLink, linkUp: bool, txPath: string, rxPath: string}> */
    private array $ports = [];

    /** @var array<string, array{mac: MacAddress, port: int, learnedAt: float, hitCount: int}> MAC(12hex) => 情報 */
    private array $macTable = [];

    /** @var resource|null 伝送線を使っている間持ち続けるロック */
    private $lock = null;

    /**
     * @param int $portCount スイッチのポート数 (デフォルト: 4)
     * @param string $fifoDir FIFOを配置するディレクトリ
     * @param FrameParserInterface $parser 信号をフレームとして解釈するパーサ
     * @param float $agingSeconds 学習したMACアドレスの保持時間 (デフォルト: IEEE 802.1D-1998 の推奨値 300秒)
     * @param int $macTableSize MACアドレステーブルに登録できるエントリ数
     * @param PcapWriter|null $capture 受信した正常なフレームの書き出し先 (ポートミラーリングに相当)
     * @param bool $keepMacOnLinkDown リンクダウンしてもエントリを消さない (ハブ越しの構成の再現用)
     */
    public function __construct(
        private int $portCount = 4,
        private string $fifoDir = '/tmp/practice-osi-switch',
        private FrameParserInterface $parser = new EthernetFrameParser(),
        private float $agingSeconds = 300.0,
        private int $macTableSize = 1024,
        private ?PcapWriter $capture = null,
        private bool $keepMacOnLinkDown = false,
    ) {
        if (!is_dir($this->fifoDir)) {
            mkdir($this->fifoDir, 0777, true);
        }

        $this->lockFifoDir();
        $this->initPorts();
    }

    /**
     * 同じ伝送線を使うスイッチが2台動かないようにする
     *
     * 2台のスイッチが同じFIFOを読むと、1つのフレームの信号を奪い合って、どちらも壊れたフレームとして破棄してしまう。
     * ロックはプロセスが終了するとOSが解放するので、強制終了しても次のスイッチを起動できる。
     */
    private function lockFifoDir(): void
    {
        $lockPath = $this->fifoDir . '/switch.lock';
        $file = fopen($lockPath, 'c+');
        if ($file === false || !flock($file, LOCK_EX | LOCK_NB)) {
            $pid = $file === false ? '' : trim((string)stream_get_contents($file));
            throw new \RuntimeException(
                "{$this->fifoDir} では、すでに別のスイッチが動いています" . ($pid === '' ? '' : " (PID: $pid)")
                . "。そのスイッチを終了するか、FIFO_DIR で別のディレクトリを指定してください"
            );
        }
        ftruncate($file, 0);
        fwrite($file, (string)getmypid());
        fflush($file);
        $this->lock = $file;
    }

    /**
     * 各ポートの物理層を初期化
     */
    private function initPorts(): void
    {
        ob_implicit_flush(true);

        for ($i = 1; $i <= $this->portCount; $i++) {
            // スイッチにとって:
            // rxPath = PCからスイッチへの送信線
            // txPath = スイッチからPCへの送信線
            $rxPath = $this->fifoDir . "/port_{$i}_to_switch";
            $txPath = $this->fifoDir . "/switch_to_port_{$i}";

            $phy = new DuplexPhysicalLayer($txPath, $rxPath, intervalUs: 0);
            // 古い残骸データがあれば空読みしてクリア
            $phy->clear();

            $this->ports[$i] = [
                'phy' => $phy,
                'link' => new PhysicalLink($this->fifoDir . "/port_{$i}.link"),
                'linkUp' => false,
                'rxPath' => $rxPath,
                'txPath' => $txPath,
            ];
        }
    }

    /**
     * スイッチのメインループ
     */
    public function run(): void
    {
        $this->printBanner();

        while (true) {
            foreach (array_keys($this->ports) as $portNum) {
                $this->refreshLink($portNum);
            }

            $readStreams = [];
            $portMap = [];
            foreach ($this->ports as $portNum => $port) {
                $rx = $port['phy']->getRxStream();
                if (is_resource($rx)) {
                    $readStreams[] = $rx;
                    $portMap[(int)$rx] = $portNum;
                }
            }

            if (empty($readStreams)) {
                usleep(50000);
                continue;
            }

            $write = null;
            $except = null;
            $numChanged = @stream_select($readStreams, $write, $except, 0, 50000); // 50ms

            if ($numChanged === false || $numChanged === 0) {
                continue;
            }

            foreach ($readStreams as $stream) {
                $portNum = $portMap[(int)$stream] ?? null;
                if ($portNum === null) {
                    continue;
                }

                while (($burst = $this->ports[$portNum]['phy']->tryReceive()) !== null) {
                    $this->handleBurst($portNum, $burst);
                }
            }
        }
    }

    /**
     * ポートのリンク状態を確認し、変化していれば反映する
     * @return bool リンクが確立しているかどうか
     */
    private function refreshLink(int $port): bool
    {
        $isUp = $this->ports[$port]['link']->isUp();
        if ($isUp !== $this->ports[$port]['linkUp']) {
            $this->ports[$port]['linkUp'] = $isUp;
            echo "\n🔌 [Port $port] リンク" . ($isUp ? 'アップ' : 'ダウン') . "\n";
            if (!$isUp && !$this->keepMacOnLinkDown) {
                $this->forgetPort($port);
            }
        }
        return $isUp;
    }

    /**
     * リンクダウンしたポートで学習したエントリを削除する
     */
    private function forgetPort(int $port): void
    {
        foreach ($this->macTable as $key => $entry) {
            if ($entry['port'] === $port) {
                echo "  🧹 [MAC削除] " . $entry['mac']->format() . " (Port $port) を削除しました\n";
                unset($this->macTable[$key]);
            }
        }
    }

    /**
     * 受信した信号をフレームとして検査し、問題なければスイッチング処理を行う
     */
    private function handleBurst(int $inPort, string $burst): void
    {
        $frame = $this->parser->parse($burst);
        if ($frame === null) {
            echo "\n🗑️ [Port $inPort] フレームとして解釈できない信号を破棄\n";
            return;
        }
        if (!$frame->isValid()) {
            echo "\n🗑️ [Port $inPort] FCSエラーのフレームを破棄 (送信元: " . $frame->getSrcMac()->format() . ")\n";
            return;
        }

        if ($frame instanceof EthernetFrame) {
            $this->capture?->write($frame->toCaptureBytes());
        }

        $this->handleFrame($inPort, $frame, $burst);
    }

    /**
     * 1フレームのスイッチング処理
     * 受信した信号は作り直さず、そのまま転送する
     */
    private function handleFrame(int $inPort, FrameInterface $frame, string $burst): void
    {
        $destMac = $frame->getDestMac();
        $srcMac = $frame->getSrcMac();
        $type = EtherType::fromInt($frame->getEtherType())?->name ?? 'unknown';

        $timestamp = date('H:i:s') . sprintf('.%03d', (microtime(true) - floor(microtime(true))) * 1000);
        echo "\n" . str_repeat('─', 60) . "\n";
        echo "[$timestamp] 📥 [Port $inPort] フレーム受信\n";
        echo "  ├─ 送信元 MAC (SRC) : " . $srcMac->format() . "\n";
        echo "  ├─ 宛先   MAC (DEST): " . $destMac->format() . ($destMac->isBroadcast() ? ' (BROADCAST)' : '') . "\n";
        echo "  └─ EtherType        : " . sprintf('0x%04X', $frame->getEtherType()) . " ($type)\n";

        $this->ageOut();

        // 1. MACアドレス学習 (Learning) - グループアドレスは送信元として学習しない
        // 送信直後にケーブルが抜かれた場合は、すでにいないPCを覚えないよう学習しない
        if (!$srcMac->isMulticast() && $this->refreshLink($inPort)) {
            $this->learnMac($srcMac, $inPort);
        }

        // 2. 転送判定 (Forwarding / Flooding / Filtering)
        if ($destMac->isBroadcast()) {
            // ブロードキャスト
            echo "  👉 アクション: 📢 [FLOODING] ブロードキャストのため、Port $inPort 以外の全ポートへフラッディング！\n";
            $this->flood($inPort, $burst);
        } elseif ($destMac->isMulticast()) {
            // マルチキャスト
            echo "  👉 アクション: 📢 [FLOODING] マルチキャストのため、Port $inPort 以外の全ポートへフラッディング！\n";
            $this->flood($inPort, $burst);
        } elseif (!isset($this->macTable[$destMac->toHex()])) {
            // 未知のユニキャスト (Unknown Unicast)
            echo "  👉 アクション: ❓ [FLOODING] 未知の宛先 (" . $destMac->format() . ") のため、Port $inPort 以外の全ポートへフラッディング！\n";
            $this->flood($inPort, $burst);
        } else {
            // 学習済みのユニキャスト
            $targetPort = $this->macTable[$destMac->toHex()]['port'];
            if ($targetPort === $inPort) {
                // 送信元と同じポートへの転送はフィルタリング（破棄）
                echo "  👉 アクション: 🛑 [FILTERING] 宛先が受信ポートと同一 (Port $inPort) のため破棄\n";
            } else {
                echo "  👉 アクション: 🎯 [FORWARDING] 学習済み宛先！ Port $inPort -> Port $targetPort にピンポイント転送 (他ポートには流しません)\n";
                $this->sendToPort($targetPort, $burst);
            }
        }

        $this->printMacTable();
    }

    /**
     * 保持時間を過ぎたエントリをMACアドレステーブルから削除する
     */
    private function ageOut(): void
    {
        $now = microtime(true);
        foreach ($this->macTable as $key => $entry) {
            if ($now - $entry['learnedAt'] >= $this->agingSeconds) {
                echo "  ⌛ [エージング] " . $entry['mac']->format() . " (Port {$entry['port']}) を保持時間切れで削除しました\n";
                unset($this->macTable[$key]);
            }
        }
    }

    /**
     * MAC学習
     */
    private function learnMac(MacAddress $mac, int $port): void
    {
        $key = $mac->toHex();
        $isNew = !isset($this->macTable[$key]);
        $moved = !$isNew && ($this->macTable[$key]['port'] !== $port);

        // テーブルが満杯なら新しいアドレスは学習できない (既存のエントリは追い出さない)
        if ($isNew && count($this->macTable) >= $this->macTableSize) {
            echo "  🚫 [MAC学習失敗] テーブルが満杯のため " . $mac->format() . " を学習できません\n";
            return;
        }

        if ($isNew) {
            echo "  ✨ [MAC学習] 新規登録: Port $port に " . $mac->format() . " を学習しました\n";
        } elseif ($moved) {
            $oldPort = $this->macTable[$key]['port'];
            echo "  🔄 [MAC移動] ポート変更: " . $mac->format() . " が Port $oldPort -> Port $port に移動しました\n";
        }

        $count = ($this->macTable[$key]['hitCount'] ?? 0) + 1;
        $this->macTable[$key] = [
            'mac' => $mac,
            'port' => $port,
            'learnedAt' => microtime(true),
            'hitCount' => $count,
        ];
    }

    /**
     * フラッディング (受信ポート以外の全ポートにフレームを送信)
     */
    private function flood(int $inPort, string $burst): void
    {
        $sentPorts = [];
        for ($p = 1; $p <= $this->portCount; $p++) {
            if ($p === $inPort) {
                continue; // 受信元ポートには流さない
            }
            if ($this->sendToPort($p, $burst)) {
                $sentPorts[] = "Port $p";
            }
        }
        echo "     └─ 転送先: " . ($sentPorts === [] ? '(リンクアップしているポートなし)' : implode(', ', $sentPorts)) . "\n";
    }

    /**
     * 指定したポートにフレームを送信
     * @return bool リンクダウンしていて送信できなかった場合は false
     */
    private function sendToPort(int $port, string $burst): bool
    {
        if (!isset($this->ports[$port]) || !$this->refreshLink($port)) {
            return false;
        }

        $this->ports[$port]['phy']->send($burst);
        return true;
    }

    /**
     * MACアドレステーブルの表示
     */
    public function printMacTable(): void
    {
        echo "  ┌── MAC Address Table (FDB: Forwarding Database) ──┐\n";
        if (empty($this->macTable)) {
            echo "  │ (学習済みエントリなし)                          │\n";
        } else {
            foreach ($this->macTable as $info) {
                $formattedMac = $info['mac']->format();
                $port = $info['port'];
                $count = $info['hitCount'];
                printf("  │  Port %d  ↔  %s  (hits: %2d)           │\n", $port, $formattedMac, $count);
            }
        }
        echo "  └────────────────────────────────────────────────┘\n";
    }

    private function printBanner(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║            L2 Ethernet Switch (4-Port)                   ║\n";
        echo "║  Layer 2 Switching, Learning & Flooding Simulation       ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n";
        echo "ポート数: {$this->portCount}\n";
        echo "FIFO配置ディレクトリ: {$this->fifoDir}\n";
        for ($i = 1; $i <= $this->portCount; $i++) {
            echo "  Port $i: [PC -> SW] {$this->ports[$i]['rxPath']}\n";
            echo "          [SW -> PC] {$this->ports[$i]['txPath']}\n";
        }
        echo "\nスイッチ待機中... (Ctrl+C で終了)\n\n";
    }
}
