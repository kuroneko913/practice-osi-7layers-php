<?php

namespace App\Layers\DataLinkLayer;

use App\Layers\LayerInterface;
use App\Layers\DataLinkLayer;
use App\Layers\DataLinkLayer\StringFrameBuilder;
use Psr\Log\LoggerInterface;

class Factory
{
    public static function createString(
        LayerInterface $lowerLayer,
        string $type = '',
        string $to = '',
        string $from = '',
        ?LoggerInterface $logger = null,
    ): DataLinkLayer
    {
        $builder = new StringFrameBuilder($to, $from, $type);
        return new DataLinkLayer(lowerLayer:$lowerLayer, frameBuilder:$builder, logger:$logger);
    }

    public static function createEthernet(
        LayerInterface $lowerLayer,
        string $type = '',
        string $to = '',
        string $from = '',
        ?LoggerInterface $logger = null,
    ): DataLinkLayer
    {
        $builder = new EthernetFrameBuilder($to, $from, $type);
        return new DataLinkLayer(lowerLayer:$lowerLayer, frameBuilder:$builder, logger:$logger);
    }
}
