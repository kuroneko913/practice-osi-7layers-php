<?php

namespace App\Layers\NetworkLayer;

/**
 * IPv4パケットを表現する (RFC 791)
 */
class Ipv4Packet
{
    public const HEADER_BYTES = 20;
    /** Ethernetのペイロード上限からヘッダを除いた長さ。断片化は実装していない */
    public const MAX_PAYLOAD_BYTES = 1500 - self::HEADER_BYTES;

    /** 実験用のプロトコル番号 (RFC 3692)。上位のプロトコルを実装するまで使う */
    public const PROTOCOL_EXPERIMENTAL = 253;

    private const VERSION = 4;
    private const FLAG_DONT_FRAGMENT = 0x4000;
    private const FLAG_MORE_FRAGMENTS = 0x2000;
    private const FRAGMENT_OFFSET_MASK = 0x1FFF;

    public function __construct(
        private IpAddress $source,
        private IpAddress $destination,
        private int $protocol,
        private string $payload,
        private int $identification = 0,
        private int $ttl = 64,
    ) {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException('ペイロードが長すぎます (断片化は実装していません)');
        }
    }

    public function getSource(): IpAddress
    {
        return $this->source;
    }

    public function getDestination(): IpAddress
    {
        return $this->destination;
    }

    public function getProtocol(): int
    {
        return $this->protocol;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function toBytes(): string
    {
        $header = $this->buildHeader(0);
        return $this->buildHeader(self::calcChecksum($header)) . $this->payload;
    }

    private function buildHeader(int $checksum): string
    {
        return pack(
            'CCnnnCCn',
            (self::VERSION << 4) | (self::HEADER_BYTES / 4),
            0,
            self::HEADER_BYTES + strlen($this->payload),
            $this->identification,
            self::FLAG_DONT_FRAGMENT,
            $this->ttl,
            $this->protocol,
            $checksum
        ) . $this->source->getBytes() . $this->destination->getBytes();
    }

    /**
     * バイト列をパースする。不正なパケットは null を返す
     * 全長の欄で切り出すので、Ethernetのパディングはここで取り除かれる
     */
    public static function parse(string $bytes): ?self
    {
        if (strlen($bytes) < self::HEADER_BYTES) {
            return null;
        }

        $fields = unpack('Cvihl/Ctos/ntotal/nid/nfrag/Cttl/Cprotocol/nchecksum', $bytes);
        $headerBytes = ($fields['vihl'] & 0x0F) * 4;
        if (($fields['vihl'] >> 4) !== self::VERSION
            || $headerBytes < self::HEADER_BYTES
            || $fields['total'] < $headerBytes
            || $fields['total'] > strlen($bytes)) {
            return null;
        }

        // チェックサムの欄を含めて計算すると、正しいヘッダなら0になる
        if (self::calcChecksum(substr($bytes, 0, $headerBytes)) !== 0) {
            return null;
        }

        // 断片化は実装していないので、断片は受け取らない
        if (($fields['frag'] & self::FLAG_MORE_FRAGMENTS) !== 0 || ($fields['frag'] & self::FRAGMENT_OFFSET_MASK) !== 0) {
            return null;
        }

        return new self(
            source: IpAddress::fromBytes(substr($bytes, 12, 4)),
            destination: IpAddress::fromBytes(substr($bytes, 16, 4)),
            protocol: $fields['protocol'],
            payload: substr($bytes, $headerBytes, $fields['total'] - $headerBytes),
            identification: $fields['id'],
            ttl: $fields['ttl'],
        );
    }

    /**
     * ヘッダチェックサム: 16ビットごとの1の補数和の、1の補数 (RFC 791)
     */
    private static function calcChecksum(string $header): int
    {
        $sum = array_sum(unpack('n*', $header));
        while ($sum > 0xFFFF) {
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        return ~$sum & 0xFFFF;
    }
}
