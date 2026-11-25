<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: VLAN】1台のスイッチを、論理的に別々のネットワークに分ける\n";
echo "════════════════════════════════════════════════════════════════\n";

// 営業部 VLAN 10: PC-A (Port 1), PC-B (Port 2) / 開発部 VLAN 20: PC-C (Port 3), PC-D (Port 4)
$lab = new SwitchLab(switchEnv: ['PORT_VLANS' => '1:10,2:10,3:20,4:20']);
echo "VLAN 10: PC-A, PC-B / VLAN 20: PC-C, PC-D\n\n";

$snooper = null;
$report = function (string $sender) use ($lab, &$snooper): void {
    foreach ($lab->observe($sender) as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] " . ($name === $snooper ? '👀 盗聴した内容' : '⭕ 受信した内容') . ": \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-A -> ALL: ブロードキャスト\n";
$lab->send('A', '営業部のみなさんへ', MacAddress::broadcast());
$report('A');

echo "▶ PC-C -> ALL: もう一方のVLANでもブロードキャスト\n";
$lab->send('C', '開発部のみなさんへ', MacAddress::broadcast());
$report('C');

echo "▶ PC-DのNICをプロミスキャスモードにして、PC-A -> PC-B (スイッチはPC-Bの居場所をまだ知りません)\n";
$lab->setPromiscuous('D');
$snooper = 'D';
$lab->send('A', '営業部の秘密', $lab->mac('B'));
$report('A');

echo "▶ PC-A -> PC-C: 別のVLANのPCへMACアドレスを指定して送信\n";
$lab->send('A', '開発部へ', $lab->mac('C'));
$report('A');

echo "▶ PC-A -> {$lab->ip('C')}: 別のVLANのPCへIPアドレスで送信\n";
$lab->sendPacket('A', '開発部へ', $lab->ip('C'));
$received = $lab->exchange();
echo isset($received['C']) ? "  [PC-C] ⭕ 受信しました\n" : "  [PC-C] 🛡️ 届きません (ARPの応答がなく、PC-Cの MAC アドレスが分からない)\n";
$lab->printArpTable('A');

echo "\n💡 解説:\n";
echo "  ・ブロードキャストも、未知の宛先へのフラッディングも、同じVLANのポートにしか流れません。\n";
echo "  ・プロミスキャスモードのPC-Dでも、別のVLANのフレームは届かないので読めません。\n";
echo "  ・その代わり、VLANをまたぐと通信できなくなります。ARPのリクエストも届かないからです。\n";
echo "  ・VLANの間をつなぐには、ルーター (L3) が必要です。\n\n";

$lab->finish(lastLines: 30);
