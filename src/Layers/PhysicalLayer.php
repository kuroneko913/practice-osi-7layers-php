<?php

namespace App\Layers;

/**
 * 物理層
 *
 * ビット列の中身は解釈せず、ひとまとまりの信号（バースト）単位で送受信する。
 * 実機の物理層が符号でフレームの区切りを示すのと同様に、送信の最後に終端シンボルを流す。
 */
class PhysicalLayer implements LayerInterface
{
    /** 信号の終わりを示す終端シンボル。上位層には見せない */
    public const END_OF_STREAM = "\x04";

    private $fifo;

    private string $buffer = '';

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
     * バーストを送信する
     *
     * @param string $bit
     */
    public function send(mixed $bit): void
    {
        fwrite($this->fifo, $bit . self::END_OF_STREAM);
        fflush($this->fifo);
        usleep(100000); // 疑似的な送信間隔
    }

    /**
     * バーストを受信する
     *
     * @return string 受信したバースト。伝送線が閉じられた場合は空文字
     */
    public function receive(): string
    {
        while (true) {
            $char = fread($this->fifo, 1);
            if ($char === false || $char === '') {
                return '';
            }
            if ($char === self::END_OF_STREAM) {
                $burst = $this->buffer;
                $this->buffer = '';
                return $burst;
            }
            $this->buffer .= $char;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fifo)) {
            fclose($this->fifo);
        }
    }
}
