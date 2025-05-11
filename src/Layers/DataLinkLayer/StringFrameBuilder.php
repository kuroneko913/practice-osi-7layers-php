<?php

namespace App\Layers\DataLinkLayer;

/**
 * 文字列フレームを取り扱う
 */
class StringFrameBuilder implements FrameBuilderInterface
{
    public function __construct(
        private string $to,
        private string $from,
        private string $type
    ) {}

    public function buildFrame(string $data): string
    {
        $crc = crc32($data);
        $frame = "<FRAME>DEST:$this->to|SRC:$this->from|TYPE:$this->type|PAYLOAD:$data|CRC:$crc</FRAME>";
        return $frame;
    }

    public function parseFrame(string $raw): ?string
    {
        // フレームをパース
        if (!preg_match('/<FRAME>(.*?)<\/FRAME>/', $raw, $matches)) {
            return null;
        }
        $parts = explode('|', $matches[1]);
        $frame = [];
        foreach ($parts as $part) {
            $item = explode(':', $part, 2);
            $frame[$item[0]] = $item[1];
        }
        // 型が一致しない場合は破棄
        if ($frame['TYPE'] !== $this->type) {
            return null;
        }
        // 宛先が自分でない場合は破棄
        if ($frame['DEST'] !== $this->to) {
            return null;
        }
        // CRCが一致しない場合は破棄
        if (isset($frame['CRC']) && $frame['CRC'] !== (string)crc32($frame['PAYLOAD'])) {
            return null;
        }
        // ペイロードがない場合は破棄
        if (!isset($frame['PAYLOAD'])) {
            return null;
        }
        return $frame['PAYLOAD'];
    }

    /**
     * フレームが終了しているかどうかを判断する
     * @param string $raw
     * @return bool
     */
    public function isFrameEnd(string $raw): bool
    {
        return preg_match('/<FRAME>(.*?)<\/FRAME>/', $raw, $matches);
    }

    public function getFrameLength(string $raw): int
    {
        if (preg_match('/<FRAME>.*?<\/FRAME>/', $raw, $matches)) {
            return strlen($matches[0]);
        }
        return 0;
    }
}
