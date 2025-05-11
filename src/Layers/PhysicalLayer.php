<?php

namespace App\Layers;

/**
 * 物理層
 */
class PhysicalLayer
{
    private $fifo;

    /**
     * コンストラクタ
     *
     * @param string $path 伝送線相当のファイルパス
     * @param string $mode ファイルの読み書きモード
     */
    public function __construct(string $path, string $mode = 'w')
    {
        $this->fifo = fopen($path, $mode);
    }

    /**
     * ビットを送信する
     *
     * @param string $bit
     */
    public function sendBit(string $bit): void
    {
        fwrite($this->fifo, $bit);
        fflush($this->fifo);
        usleep(100000); // 疑似的な送信間隔
    }

    /**
     * ビットを受信する
     *
     * @return string
     */
    public function receiveBit(): string
    {
        return fread($this->fifo, 1);
    }

    public function __destruct()
    {
        fclose($this->fifo);
    }
}
