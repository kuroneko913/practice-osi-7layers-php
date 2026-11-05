<?php

namespace App\Layers\DataLinkLayer;

/**
 * 受信した信号を文字列フレームとして解釈する
 */
class StringFrameParser implements FrameParserInterface
{
    public function parse(string $raw): ?FrameInterface
    {
        $pattern = '/\A<FRAME>DEST:(?<dest>[^|]+)\|SRC:(?<src>[^|]+)\|TYPE:0x(?<type>[0-9A-Fa-f]{4})\|PAYLOAD:(?<payload>.*)\|CRC:(?<crc>\d+)<\/FRAME>\z/s';
        if (!preg_match($pattern, $raw, $matches)) {
            return null;
        }

        $dest = MacAddress::tryFromHex($matches['dest']);
        $src = MacAddress::tryFromHex($matches['src']);
        if ($dest === null || $src === null) {
            return null;
        }

        return new StringFrame(
            destMac: $dest,
            srcMac: $src,
            etherType: hexdec($matches['type']),
            payload: stripcslashes($matches['payload']),
            crc: $matches['crc'],
        );
    }
}
