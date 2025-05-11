<?php

namespace App\Layers;

class SessionLayer implements LayerInterface
{
    private $lowerLayer;

    public function __construct(LayerInterface $lowerLayer)
    {
        $this->lowerLayer = $lowerLayer;
    }

    public function send(mixed $data): void
    {
        $this->lowerLayer->send($data);
    }

    public function receive(): mixed
    {
        return $this->lowerLayer->receive();
    }
}
