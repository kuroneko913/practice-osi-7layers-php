<?php

namespace App\Layers\DataLinkLayer;

use App\Layers\LayerInterface;
use App\Layers\DataLinkLayer;
use App\Layers\DataLinkLayer\StringFrameBuilder;
use App\Layers\DataLinkLayer\BitFrameBuilder;

class Factory
{
    public static function createString(
        LayerInterface $lowerLayer,
        string $type = '',
        string $to = '',
        string $from = ''
    ): DataLinkLayer
    {
        $builder = new StringFrameBuilder($to, $from, $type);
        return new DataLinkLayer($lowerLayer, $builder);
    }

    public static function createBit(
        LayerInterface $lowerLayer,
        string $type = '',
        string $to = '',
        string $from = ''
    ): DataLinkLayer
    {
        $builder = new BitFrameBuilder($to, $from, $type);
        return new DataLinkLayer($lowerLayer, $builder);
    }
}
