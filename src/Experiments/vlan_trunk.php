<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: VLANトランク】802.1Qタグで、2台のスイッチの間に複数のVLANを通す\n";
echo "════════════════════════════════════════════════════════════════\n";

// スイッチ1: PC-A (VLAN 10), PC-C (VLAN 20) / スイッチ2: PC-E (VLAN 10), PC-G (VLAN 20) / Port 3 同士をトランクでつなぐ
$root = dirname(__DIR__, 2);
$switchEnv = fn(string $name) => [
    'PORT_VLANS' => '1:10,2:20',
    'TRUNK_PORTS' => '3',
    'CAPTURE_PATH' => "$root/logs/vlan_trunk_$name.pcap",
];
$sw1 = new SwitchLab(3, $switchEnv('sw1'), [
    'A' => ['port' => 1, 'mac' => 'aabbccddee0a', 'ip' => '192.168.1.1'],
    'C' => ['port' => 2, 'mac' => 'aabbccddee0c', 'ip' => '192.168.1.3'],
], 'sw1');
$sw2 = new SwitchLab(3, $switchEnv('sw2'), [
    'E' => ['port' => 1, 'mac' => 'aabbccddee0e', 'ip' => '192.168.1.5'],
    'G' => ['port' => 2, 'mac' => 'aabbccddee0f', 'ip' => '192.168.1.7'],
], 'sw2');
$trunk = SwitchLab::plugCable($sw1, 3, $sw2, 3);
echo "スイッチ1 Port 3 ⇄ スイッチ2 Port 3 をトランクでつなぎました\n";
echo "VLAN 10: PC-A (スイッチ1), PC-E (スイッチ2) / VLAN 20: PC-C (スイッチ1), PC-G (スイッチ2)\n\n";

$report = function (string $sender) use ($sw1, $sw2, $trunk): void {
    $relayed = SwitchLab::relayCable($trunk, 0.5);
    echo "  (トランクを通ったフレーム: $relayed 個)\n";
    foreach (array_merge($sw1->observe($sender, 0), $sw2->observe($sender, 0)) as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] ⭕ 受信した内容: \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-A (VLAN 10) -> ALL\n";
$sw1->send('A', 'VLAN 10 のみなさんへ', MacAddress::broadcast());
$report('A');

echo "▶ PC-C (VLAN 20) -> ALL\n";
$sw1->send('C', 'VLAN 20 のみなさんへ', MacAddress::broadcast());
$report('C');

echo "▶ PC-A -> {$sw2->ip('E')} (別のスイッチにいる、同じVLANのPC-E) へIPアドレスで送信\n";
$sw1->sendPacket('A', 'トランク越しのこんにちは', $sw2->ip('E'));
$received = [];
for ($i = 0; $i < 8; $i++) {
    SwitchLab::relayCable($trunk, 0.15);
    $received = array_merge_recursive($received, $sw1->exchange(1), $sw2->exchange(1));
}
echo isset($received['E'])
    ? "  [PC-E] ⭕ {$received['E'][0]->getSource()} から受信: \"{$received['E'][0]->getPayload()}\"\n"
    : "  [PC-E] ── 未受信\n";

echo "▶ PC-A -> {$sw2->ip('G')} (別のスイッチにいる、別のVLANのPC-G) へIPアドレスで送信\n";
$sw1->sendPacket('A', '届かないはず', $sw2->ip('G'));
$received = [];
for ($i = 0; $i < 8; $i++) {
    SwitchLab::relayCable($trunk, 0.15);
    $received = array_merge_recursive($received, $sw1->exchange(1), $sw2->exchange(1));
}
echo isset($received['G']) ? "  [PC-G] ⭕ 受信しました\n" : "  [PC-G] 🛡️ 届きません (VLAN 10 のARPリクエストはVLAN 20 に流れない)\n";

echo "\n💡 解説:\n";
echo "  ・スイッチ1はトランクへ送るフレームに802.1Qタグ (VLAN ID) を付け、スイッチ2はタグを見てVLANを判断します。\n";
echo "  ・PC側はタグを知りません。タグはスイッチ同士の間だけで使われ、PCへ送るときに外されます。\n";
echo "  ・1本のトランクでVLAN 10とVLAN 20の両方を運びながら、VLANの分離は保たれています。\n";
echo "  ・キャプチャ: logs/vlan_trunk_sw2.pcap を開くと、トランクから届いたタグ付きフレームを確認できます。\n\n";

echo "【スイッチ1】\n";
$sw1->finish(lastLines: 20);
echo "\n【スイッチ2】\n";
$sw2->finish(lastLines: 20);
