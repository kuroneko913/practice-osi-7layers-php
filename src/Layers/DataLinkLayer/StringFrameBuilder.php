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
        $crc = sprintf('%u', crc32($data));
        $frame = "<FRAME>DEST:$this->to|SRC:$this->from|TYPE:$this->type|PAYLOAD:$data|CRC:$crc</FRAME>";
        return $frame;
    }

    public function parseFrame(string $raw): ?string
    {
        // フレームをパースして各フィールドを正確に抽出
        $pattern = '/<FRAME>DEST:(?<dest>[^|]+)\|SRC:(?<src>[^|]+)\|TYPE:(?<type>[^|]+)\|PAYLOAD:(?<payload>.*)\|CRC:(?<crc>\d+)<\/FRAME>/';
        if (!preg_match($pattern, $raw, $matches)) {
            return null;
        }
        $frame = [
            'DEST'    => $matches['dest'],
            'SRC'     => $matches['src'],
            'TYPE'    => $matches['type'],
            'PAYLOAD' => $matches['payload'],
            'CRC'     => $matches['crc'],
        ];
        // 型が一致しない場合は破棄
        if ($frame['TYPE'] !== $this->type) {
            return null;
        }
        // 宛先が自分でない場合は破棄
        if ($frame['DEST'] !== $this->to) {
            return null;
        }
        // CRCが一致しない場合は破棄
        if (isset($frame['CRC'])) {
            $expected = sprintf('%u', crc32($frame['PAYLOAD']));
            if ($frame['CRC'] !== $expected) {
                echo "[parseFrame] crc mismatch: {$frame['CRC']} != {$expected}\n";
                return null;
            }
        }
        // ペイロードがない場合は破棄
        if (!isset($frame['PAYLOAD'])) {
            return null;
        }
        echo "\n{src:{$frame['SRC']}, dest:{$frame['DEST']}, type:{$frame['TYPE']}}\n";
        return $frame['PAYLOAD'];
    }

    public function getPreamble(): string
    {
        return '<FRAME>';
    }

    /**
     * フレームが終了しているかどうかを判断する
     * @param string $raw
     * @return bool
     */
    public function isFrameEnd(string $raw): bool
    {
        // frame end detected by presence of closing tag
        return str_contains($raw, '</FRAME>');
    }

    public function getFrameLength(string $raw): int
    {
        $endTag = '</FRAME>';
        $pos = strpos($raw, $endTag);
        if ($pos === false) {
            return 0;
        }
        // タグ位置 + タグ長 ＝ フレーム全体の長さ
        return $pos + strlen($endTag);
    }
}
