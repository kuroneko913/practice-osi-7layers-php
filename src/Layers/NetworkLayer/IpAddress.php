<?php

namespace App\Layers\NetworkLayer;

/**
 * IPv4アドレスを表現する
 */
class IpAddress
{
    /** ネットワークバイトオーダーの4バイト */
    private string $bytes;

    public function __construct(string $address)
    {
        $bytes = @inet_pton($address);
        if ($bytes === false || strlen($bytes) !== 4) {
            throw new \InvalidArgumentException("IPv4アドレスの形式が不正です: $address");
        }
        $this->bytes = $bytes;
    }

    /**
     * 文字列から生成する。形式が不正な場合は null を返す (ユーザー入力の検証用)
     */
    public static function tryFromString(string $address): ?self
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : new self($address);
    }

    /**
     * ネットワークバイトオーダーの4バイトから生成する
     */
    public static function fromBytes(string $bytes): self
    {
        return new self((string)@inet_ntop($bytes));
    }

    /**
     * 限定ブロードキャストアドレス (255.255.255.255) を返す
     */
    public static function broadcast(): self
    {
        return new self('255.255.255.255');
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function equals(IpAddress $other): bool
    {
        return $this->bytes === $other->bytes;
    }

    public function isBroadcast(): bool
    {
        return $this->bytes === "\xff\xff\xff\xff";
    }

    public function __toString(): string
    {
        return (string)inet_ntop($this->bytes);
    }
}
