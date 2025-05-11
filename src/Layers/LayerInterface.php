<?php

namespace App\Layers;

/**
 * 層のインターフェース
 */
interface LayerInterface
{
    /**
     * データを送信する
     *
     * @param mixed $data 送信するデータ
     */
    public function send(mixed $data): void;

    /**
     * データを受信する
     *
     * @return mixed 受信したデータ
     */
    public function receive(): mixed;
}
