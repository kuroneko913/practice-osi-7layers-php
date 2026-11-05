<?php

namespace App\Layers;

/**
 * 全二重（送受信が独立した伝送線）物理層
 *
 * 実際のLANケーブル（ツイストペアケーブル）が送信ペア線(Tx)と
 * 受信ペア線(Rx)を別々に持っているのと同様に、送信用と受信用で
 * 独立したFIFO（伝送線）を用いて通信を行います。
 */
class DuplexPhysicalLayer implements LayerInterface
{
    /** @var resource|null */
    private $txFifo = null;
    /** @var resource|null */
    private $rxFifo = null;

    private int $intervalUs;

    private string $rxBuffer = '';

    /**
     * @param string $txPath 送信用FIFOのパス
     * @param string $rxPath 受信用FIFOのパス
     * @param int|null $intervalUs 1ビット送信ごとの遅延マイクロ秒 (未指定時は環境変数 BIT_INTERVAL_US または 0)
     */
    public function __construct(
        private string $txPath,
        private string $rxPath,
        ?int $intervalUs = null
    ) {
        // 環境変数または引数からビット送信間隔を取得 (デフォルトはシミュレーションが快適に進むよう 1000us = 1ms)
        if ($intervalUs !== null) {
            $this->intervalUs = $intervalUs;
        } else {
            $envInterval = getenv('BIT_INTERVAL_US');
            $this->intervalUs = ($envInterval !== false) ? (int)$envInterval : 1000;
        }

        // 送信用FIFOを 'r+' で開く（相手プロセスが未起動でもブロックしないように）
        if (!file_exists($this->txPath)) {
            posix_mkfifo($this->txPath, 0666);
        }
        $this->txFifo = fopen($this->txPath, 'r+');
        stream_set_blocking($this->txFifo, false);

        // 受信用FIFOを 'r+' で開く
        if (!file_exists($this->rxPath)) {
            posix_mkfifo($this->rxPath, 0666);
        }
        $this->rxFifo = fopen($this->rxPath, 'r+');
        stream_set_blocking($this->rxFifo, false);
    }

    /**
     * バーストを送信する。最後に終端シンボルを流して信号の終わりを示す
     */
    public function send(mixed $bit): void
    {
        if (!is_resource($this->txFifo)) {
            return;
        }

        $signal = $bit . PhysicalLayer::END_OF_STREAM;
        $length = strlen($signal);
        for ($i = 0; $i < $length; $i++) {
            fwrite($this->txFifo, $signal[$i]);
            fflush($this->txFifo);
            if ($this->intervalUs > 0) {
                usleep($this->intervalUs);
            }
        }
    }

    /**
     * バーストを受信する
     * @param int $timeoutMs タイムアウトミリ秒 (デフォルト: 500ms)
     * @return string 受信したバースト。タイムアウト時は空文字
     */
    public function receive(int $timeoutMs = 500): string
    {
        $startTime = microtime(true);
        while (true) {
            $burst = $this->tryReceive();
            if ($burst !== null) {
                return $burst;
            }
            if ($timeoutMs > 0 && (microtime(true) - $startTime) * 1000 > $timeoutMs) {
                return ''; // タイムアウト
            }
            // データが来ていない場合は少しスリープしてCPU負荷を抑制
            usleep(2000); // 2ms
        }
    }

    /**
     * 届いている分だけ読み、バーストが揃っていれば返す (待たない)
     * @return string|null 揃っていなければ null。途中まで届いた分は次回に持ち越す
     */
    public function tryReceive(): ?string
    {
        if (!is_resource($this->rxFifo)) {
            return null;
        }

        while (true) {
            $pos = strpos($this->rxBuffer, PhysicalLayer::END_OF_STREAM);
            if ($pos !== false) {
                $burst = substr($this->rxBuffer, 0, $pos);
                $this->rxBuffer = substr($this->rxBuffer, $pos + 1);
                return $burst;
            }
            $chunk = fread($this->rxFifo, 4096);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->rxBuffer .= $chunk;
        }
    }

    /**
     * 読み込み済みで、まだ取り出していないバーストがあるかどうか
     * stream_select では検知できないので、受信の監視と合わせて確認する
     */
    public function hasBufferedBurst(): bool
    {
        return str_contains($this->rxBuffer, PhysicalLayer::END_OF_STREAM);
    }

    /**
     * 伝送線に残っている古いデータを捨てる
     */
    public function clear(): void
    {
        foreach ([$this->txFifo, $this->rxFifo] as $fifo) {
            while (is_resource($fifo) && !in_array(fread($fifo, 4096), ['', false], true)) {
            }
        }
        $this->rxBuffer = '';
    }

    /**
     * 受信用ストリームリソースを取得する (stream_select による到着監視用)
     * @return resource|null
     */
    public function getRxStream()
    {
        return $this->rxFifo;
    }

    public function __destruct()
    {
        if (is_resource($this->txFifo)) {
            fclose($this->txFifo);
        }
        if (is_resource($this->rxFifo)) {
            fclose($this->rxFifo);
        }
    }
}

