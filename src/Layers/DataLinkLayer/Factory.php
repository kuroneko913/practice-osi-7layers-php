<?php

namespace App\Layers\DataLinkLayer;

use App\Layers\LayerInterface;
use App\Layers\DataLinkLayer;
use Psr\Log\LoggerInterface;

/**
 * 同じ形式のBuilderとParserを組み合わせてデータリンク層を作成する
 */
class Factory
{
    public static function createString(
        LayerInterface $lowerLayer,
        MacAddress $from = new MacAddress('000000000000'),
        ?LoggerInterface $logger = null,
    ): DataLinkLayer
    {
        return new DataLinkLayer(
            lowerLayer: $lowerLayer,
            frameBuilder: new StringFrameBuilder(from: $from),
            frameParser: new StringFrameParser(),
            myMac: $from,
            logger: $logger,
        );
    }

    public static function createEthernet(
        LayerInterface $lowerLayer,
        MacAddress $from,
        ?LoggerInterface $logger = null,
    ): DataLinkLayer
    {
        return new DataLinkLayer(
            lowerLayer: $lowerLayer,
            frameBuilder: new EthernetFrameBuilder(from: $from),
            frameParser: new EthernetFrameParser(),
            myMac: $from,
            logger: $logger,
        );
    }
}
