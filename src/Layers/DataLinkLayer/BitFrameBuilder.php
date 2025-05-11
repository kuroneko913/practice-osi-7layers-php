<?php

namespace App\Layers\DataLinkLayer;

/**
 * バイト列フレームを取り扱う
 */
class BitFrameBuilder implements FrameBuilderInterface
{
    public function __construct(
        private string $to,
        private string $from,
        private string $type
    ) {}

    public function buildFrame(string $data): string
    {
        $fields = [
            'DEST' => str_pad($this->to, 12, "\0"),
            'SRC' => str_pad($this->from, 12, "\0"),
            'TYPE' => pack('n', hexdec(str_replace('0x', '', $this->type))),
            'PAYLOAD' => str_pad($data, 46, "\0"),  // minimum Ethernet payload size
            'CRC' => pack('N', crc32(str_pad($data, 46, "\0")))
        ];

        $bitString = '';
        foreach ($fields as $key => $value) {
            $bytes = is_string($value) ? $value : strval($value);
            foreach (str_split($bytes) as $char) {
                $bitString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
            }
        }
        return $bitString;
    }

    public function parseFrame(string $raw): ?string
    {
        // 各フィールドのバイト長
        $lengths = [
            'DEST' => 12,    // 12文字 → 96ビット
            'SRC' => 12,     // 12文字 → 96ビット
            'TYPE' => 2,     // 2バイト → 16ビット
            'CRC' => 4   // 4バイト → 32ビット
        ];

        $bitIndex = 0;

        // DEST
        $dest = '';
        for ($i = 0; $i < $lengths['DEST']; $i++) {
            $byte = substr($raw, $bitIndex, 8);
            $dest .= chr(bindec($byte));
            $bitIndex += 8;
        }

        // SRC
        $src = '';
        for ($i = 0; $i < $lengths['SRC']; $i++) {
            $byte = substr($raw, $bitIndex, 8);
            $src .= chr(bindec($byte));
            $bitIndex += 8;
        }

        // TYPE
        $typeBits = substr($raw, $bitIndex, 16);
        $type = strtoupper("0x" . str_pad(dechex(bindec($typeBits)), 4, '0', STR_PAD_LEFT));
        $bitIndex += 16;
        if (strtolower($type) !== strtolower($this->type)) {
            return null;
        }

        // PAYLOAD
        $payload = '';
        $payloadBitsLength = strlen($raw) - $bitIndex - ($lengths['CRC'] * 8);
        for ($i = 0; $i < $payloadBitsLength; $i += 8) {
            $byte = substr($raw, $bitIndex + $i, 8);
            $payload .= chr(bindec($byte));
        }
        $bitIndex += $payloadBitsLength;

        $payloadTrimmed = rtrim($payload, "\0");  // remove Ethernet-style padding

        // CRC
        $crc = substr($raw, $bitIndex, 32);
        $expectedCrc = crc32($payload);
        // $crcは32ビットのビット列（例: "101010...010"）
        $crcBytes = '';
        for ($i = 0; $i < 32; $i += 8) {
            $byte = substr($crc, $i, 8);
            $crcBytes .= chr(bindec($byte));
        }
        $actualCrc = unpack('N', $crcBytes)[1];

        // 宛先チェック
        if (rtrim($dest, "\0") !== rtrim($this->to, "\0")) {
            return null;
        }

        // CRCチェック
        if ($expectedCrc !== $actualCrc) {
            return null;
        }
        return $payloadTrimmed;
    }

    public function isFrameEnd(string $raw): bool
    {
        // Total bits = DEST (12B) + SRC (12B) + TYPE (2B) + PAYLOAD (46B) + CRC (4B)
        $totalBytes = 12 + 12 + 2 + 46 + 4;
        $totalBits = $totalBytes * 8;
        return strlen($raw) >= $totalBits;
    }

    public function getFrameLength(string $raw): int
    {
        // 固定長: DEST(12) + SRC(12) + TYPE(2) + PAYLOAD(46) + CRC(4) = 76バイト * 8ビット
        $totalBytes = 12 + 12 + 2 + 46 + 4;
        $totalBits = $totalBytes * 8;
        return $totalBits;
    }
}
