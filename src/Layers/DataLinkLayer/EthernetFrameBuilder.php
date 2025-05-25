<?php

namespace App\Layers\DataLinkLayer;

use App\Layers\DataLinkLayer\EthernetFrame;
use App\Layers\DataLinkLayer\MacAddress;

/**
 * Ethernetフレームを取り扱う
 */
class EthernetFrameBuilder implements FrameBuilderInterface
{
    private string $preamble;

    public function __construct(
        private string $to,
        private string $from,
        private string $type
    ) {
        // フレームの始点のビット列をEthernetの仕様に沿って設定
        // 7 × 0x55 → ビット列"01010101"を7回
        $preamble = str_repeat(str_pad(decbin(0x55), 8, '0'), 7);
        $sfd = str_pad(decbin(0xD5), 8, '0');
        $this->preamble = $preamble . $sfd;
    }

    public function buildFrame(string $data): string
    {
        // ペイロードをビット列に変換
        $payloadBits = '';
        $paddedData = str_pad($data, 46, "\0");  // minimum Ethernet payload size
        foreach (str_split($paddedData) as $char) {
            $payloadBits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        
        $payloadBytes = strlen($payloadBits) / 8;
        if ($payloadBytes > 1500) {
            throw new \RuntimeException("Payload size exceeds maximum Ethernet payload size (1500 bytes)");
        }

        $frame = new EthernetFrame(
            destMac: new MacAddress($this->to),
            srcMac: new MacAddress($this->from),
            etherTypeOrLength: $payloadBytes,
            payloadBits: $payloadBits,
            fcsBits: ''  // FCSは自動計算される
        );

        // テスト用にフレームデータをバイト列に変換
        $ethernetFrame = EthernetFrame::parseFromBits($frame->getFrame());
        echo "[buildFrame] parsedFrameLength: " . strlen($ethernetFrame->getFrame()) . "\n";
        if ($ethernetFrame && $ethernetFrame->isValid()) {
            echo "CRC OK!\n";
        } else {
            echo "CRC NG...!\n";
        }
        
        return $frame->getFrame();
    }

    public function parseFrame(string $raw): ?string
    {
        // EthernetFrameクラスを使用してパース
        $ethernetFrame = EthernetFrame::parseFromBits($raw);
        
        if ($ethernetFrame === null) {
            echo "[parseFrame] failed to parse frame\n";
            return null;
        }
        
        // フレームの検証
        if (!$ethernetFrame->isValid()) {
            echo "[parseFrame] frame validation failed (CRC error)\n";
            return null;
        }
        
        
        // Convert binary MAC to hex string (lowercase, no separators)
        $destBytes = $ethernetFrame->getDestMac()->getAddress();
        $destHex = bin2hex($destBytes);
        if (strtolower($destHex) !== strtolower($this->to)) {
            echo "[parseFrame] dest: $destHex !== {$this->to}\n";
            return null;
        }
        
        $srcBytes = $ethernetFrame->getSrcMac()->getAddress();
        $srcHex = bin2hex($srcBytes);
        echo "\n{dest: $destHex, src: $srcHex, length: " . $ethernetFrame->getEtherType() . ", payload: " . $ethernetFrame->getPayloadString() . "}\n";
        
        return $ethernetFrame->getPayloadString();
    }

    public function isFrameEnd(string $raw): bool
    {
        // Minimum bits for header (without CRC): 7B preamble + 1B SFD + 6B DEST + 6B SRC + 2B LENGTH
        $minimumHeaderBits = (7 + 1 + 6 + 6 + 2) * 8;
        if (strlen($raw) < $minimumHeaderBits) {
            return false;
        }
        $lengthFieldOffset = (7 + 1 + 6 + 6) * 8;
        $payloadBitsLength = bindec(substr($raw, $lengthFieldOffset, 16));
        $totalBitsLength = $minimumHeaderBits + ($payloadBitsLength * 8) + 32;
        return $totalBitsLength <= strlen($raw);
    }

    public function getFrameLength(string $raw): int
    {
        return strlen($raw);
    }

    public function getPreamble(): string
    {
        return $this->preamble;
    }
}
