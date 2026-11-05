<?php

namespace App\Devices;

/**
 * フレームを libpcap 形式で書き出す (Wireshark や tcpdump で読める)
 * 書き出しの途中でも読めるよう、1フレームごとにファイルへ反映する
 */
class PcapWriter
{
    private const MAGIC_NUMBER = 0xa1b2c3d4;
    private const VERSION_MAJOR = 2;
    private const VERSION_MINOR = 4;
    private const SNAPLEN = 65535;
    private const LINKTYPE_ETHERNET = 1;

    /** @var resource */
    private $file;

    public function __construct(string $path)
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            throw new \RuntimeException("キャプチャファイルを開けません: $path");
        }
        $this->file = $file;

        // グローバルヘッダ: マジックナンバー, バージョン, 時差, 精度, 最大長, リンク種別
        fwrite($this->file, pack(
            'VvvVVVV',
            self::MAGIC_NUMBER,
            self::VERSION_MAJOR,
            self::VERSION_MINOR,
            0,
            0,
            self::SNAPLEN,
            self::LINKTYPE_ETHERNET
        ));
        fflush($this->file);
    }

    /**
     * @param string $frameBytes 宛先MACアドレスから始まるフレームのバイト列
     */
    public function write(string $frameBytes): void
    {
        $now = microtime(true);
        $seconds = (int)$now;
        $microseconds = (int)(($now - $seconds) * 1000000);
        $length = strlen($frameBytes);

        // パケットヘッダ: 秒, マイクロ秒, 記録した長さ, 元の長さ
        fwrite($this->file, pack('VVVV', $seconds, $microseconds, $length, $length) . $frameBytes);
        fflush($this->file);
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }
}
