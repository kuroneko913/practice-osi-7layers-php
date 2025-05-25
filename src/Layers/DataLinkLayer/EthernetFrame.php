<?php

namespace App\Layers\DataLinkLayer;

use App\Layers\DataLinkLayer\MacAddress;

/**
 * IEEE 802.3 Ethernetフレームを表現する
 */
class EthernetFrame
{
    private string $preamble;
    private string $sfd;
    private string $frame;

    public function __construct(
        private MacAddress $destMac,
        private MacAddress $srcMac,
        private int $etherTypeOrLength,
        private string $payloadBits,
        private string $fcsBits
    ) {
        // Preamble: 7 bytes of 0x55 (01010101)
        $this->preamble = str_repeat(str_pad(decbin(0x55), 8, '0'), 7);
        
        // Start Frame Delimiter: 1 byte of 0xD5 (11010101)
        $this->sfd = str_pad(decbin(0xD5), 8, '0');
        
        $this->buildFrame();
    }

    /**
     * Ethernet FCS (CRC-32) 計算
     *
     * @param string $data バイト列データ
     * @return int 32bit 符号なし CRC-32 値
     */
    private static function calcEthernetFcs(string $data): int
    {
        return hexdec(hash('crc32b', $data)) & 0xFFFFFFFF;
    }

    private function buildFrame(): void
    {
        // Convert MAC addresses to bit strings
        $destBits = $this->macToBits($this->destMac->getAddress());
        $srcBits = $this->macToBits($this->srcMac->getAddress());
        
        // Convert EtherType/Length to 16-bit string
        $etherTypeBits = str_pad(decbin($this->etherTypeOrLength), 16, '0', STR_PAD_LEFT);
        
        // Calculate FCS if not provided
        if (empty($this->fcsBits)) {
            $bytes = $this->bitsToBytes($destBits . $srcBits . $etherTypeBits . $this->payloadBits);
            // ログ: 送信側のバイト列を16進で表示
            $crc = self::calcEthernetFcs($bytes);
            $crcBytes = pack('V', $crc);
            $crcBits = '';
            foreach (str_split($crcBytes) as $b) {
                $crcBits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
            }
            $this->fcsBits = $crcBits;
        }
        
        // Assemble complete frame
        $this->frame = $this->preamble . $this->sfd . 
                      $destBits . $srcBits . $etherTypeBits . 
                      $this->payloadBits . $this->fcsBits;
    }

    private function macToBits(string $mac): string
    {
        $bits = '';
        foreach (str_split($mac) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

/**
 * ビット文字列を実際のバイト列に変換する例
 */
    private function bitsToBytes(string $bitString): string
    {
        $bytes = '';
        $len   = strlen($bitString);
        for ($i = 0; $i < $len; $i += 8) {
            // 8 ビットずつ取り出し
            $octetBits = substr($bitString, $i, 8);
            if (strlen($octetBits) < 8) {
                // 最後に 8 ビットに満たない切れ端があれば捨てる
                break;
            }
            $bytes    .= chr(bindec($octetBits));
        }
        return $bytes;
    }

    public function getFrame(): string
    {
        return $this->frame;
    }

    public function getPreamble(): string
    {
        return $this->preamble . $this->sfd;
    }

    public function getDestMac(): MacAddress
    {
        return $this->destMac;
    }

    public function getSrcMac(): MacAddress
    {
        return $this->srcMac;
    }

    public function getEtherType(): int
    {
        return $this->etherTypeOrLength;
    }

    public function getPayload(): string
    {
        return $this->bitsToBytes($this->payloadBits);
    }

    public function getFrameLength(): int
    {
        return strlen($this->frame);
    }

    public function getFrameWithoutPreamble(): string
    {
        return substr($this->frame, strlen($this->preamble) + strlen($this->sfd));
    }

    /**
     * ビット列からEthernetFrameオブジェクトを作成する
     */
    public static function parseFromBits(string $frameBits): ?self
    {
        echo "[parseFromBits] input length: " . strlen($frameBits) . " bits\n";
        
        // プレアンブルとSFDの長さ
        $preambleLength = 7 * 8; // 7バイト × 8ビット
        $sfdLength = 1 * 8;      // 1バイト × 8ビット
        $headerLength = $preambleLength + $sfdLength;
        
        // 最小フレーム長チェック
        $minFrameLength = $headerLength + (6 + 6 + 2 + 46 + 4) * 8; // DEST + SRC + TYPE + MIN_PAYLOAD + FCS
        
        if (strlen($frameBits) < $minFrameLength) {
            echo "[parseFromBits] frame too short: " . strlen($frameBits) . " < " . $minFrameLength . "\n";
            return null;
        }
        
        // プレアンブルとSFDをスキップ
        $frameData = substr($frameBits, $headerLength);
        $bitIndex = 0;
        
        // DEST MAC (6バイト = 48ビット)
        $destBits = substr($frameData, $bitIndex, 48);
        $destMac = self::bitsToMacAddress($destBits);
        $bitIndex += 48;
        
        // SRC MAC (6バイト = 48ビット)
        $srcBits = substr($frameData, $bitIndex, 48);
        $srcMac = self::bitsToMacAddress($srcBits);
        $bitIndex += 48;
        
        // 802.3 Length field gives payload length in bytes
        $etherTypeBits = substr($frameData, $bitIndex, 16);
        $payloadBytes  = bindec($etherTypeBits) & 0xFFFF;
        // Convert payload length to bits
        $payloadBitsLength = $payloadBytes * 8;
        $totalBitsLength = $bitIndex + 16 + $payloadBitsLength + 32;
        if ($totalBitsLength > strlen($frameData)) {
            echo "[parseFromBits] total bits length exceeds frame data length: " . $totalBitsLength . " > " . strlen($frameData) . "\n";
            return null;
        }
        $bitIndex += 16;
        
        // Payload (残りのデータからFCSを除く)
        $payloadBits = substr($frameData, $bitIndex, $payloadBitsLength); // 最後の32ビット(FCS)を除く
        $bitIndex += $payloadBitsLength;
        
        // FCS (最後の4バイト = 32ビット)
        $fcsBits = substr($frameData, -32);
        
        try {
            $frame = new self(
                destMac: new MacAddress($destMac),
                srcMac: new MacAddress($srcMac),
                etherTypeOrLength: $payloadBitsLength,
                payloadBits: $payloadBits,
                fcsBits: $fcsBits
            );
            $frame->frame = $frameBits;
            echo "[parseFromBits] frame created successfully\n";
            return $frame;
        } catch (\Exception $e) {
            echo "[parseFromBits] exception: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * ビット列をMACアドレス文字列に変換
     */
    private static function bitsToMacAddress(string $bits): string
    {
        $mac = '';
        for ($i = 0; $i < 48; $i += 8) {
            $byte = substr($bits, $i, 8);
            if (strlen($byte) === 8) {
                $mac .= chr(bindec($byte));
            }
        }
        return $mac;
    }
    
    /**
     * ペイロードを文字列として取得（パディングを除去）
     */
    public function getPayloadString(): string
    {
        $payload = $this->bitsToBytes($this->payloadBits);
        return rtrim($payload, "\0");
    }
    
    /**
     * フレームの検証（CRCチェック）
     */
    public function isValid(): bool
    {
        // Extract the bit-string excluding preamble and SFD, then drop the last 32 bits (FCS)
        $dataBits = substr($this->frame, strlen($this->preamble) + strlen($this->sfd), -32);
        // Convert full data+FCS bits to bytes
        $fullBytes = $this->bitsToBytes($dataBits . $this->fcsBits);
        // Separate header+payload and FCS
        $recvBytes = substr($fullBytes, 0, -4);
        echo "[isValid bytes hex] " . strtoupper(bin2hex($recvBytes)) . "\n";
        // フレームデータからFCSを読み取る
        $fcsBytes = substr($fullBytes, -4);
        $crcData = unpack('Vcrc', $fcsBytes);
        $actualCrc = $crcData['crc'] & 0xFFFFFFFF;
        echo "[isValid] actualCrc: " . dechex($actualCrc) . "\n";

        // 読み取ったフレームデータからFCSを計算
        $expectedCrc = self::calcEthernetFcs($recvBytes) & 0xFFFFFFFF;

        $isValid = $expectedCrc === $actualCrc;
        if (!$isValid) {
            echo "[isValid] expected CRC: " . dechex($expectedCrc) . ", actual CRC: " . dechex($actualCrc) . ", valid: " . ($isValid ? "yes" : "no") . "\n";
        }
        return $isValid;
    }

    public function __toString(): string
    {
        $frames = [
            'destMac' => $this->destMac->getAddress(),
            'srcMac' => $this->srcMac->getAddress(),
            'etherTypeOrLength' => $this->etherTypeOrLength,
            'payloadBits' => $this->payloadBits,
            'fcsBits' => $this->fcsBits,
        ];
        $text = '';
        foreach ($frames as $key => $value) {
            $text .= $key . ': ' . $value . "\n";
        }
        return $text;
    }
}
