# 実験環境の使い方

スイッチ、PC、ケーブル、キャプチャ、実験スクリプトの使い方をまとめます。仕組みの解説と観察結果は、各層のドキュメントにあります。

| やりたいこと | 参照するドキュメント |
| :--- | :--- |
| L2スイッチの学習、フラッディング、盗聴、ループを試す | [L2スイッチと4台のPC実験](L2SwitchAndLimits.md) |
| VLANで分ける、トランクで2台のスイッチをつなぐ | [VLANとトランク](VLAN.md) |
| IPアドレスで送る、ARPを観察する | [第3層: ARPとIPv4](NetworkLayer.md) |

すべてのコマンドは、リポジトリのルートで実行します。スイッチ、PC、ケーブルは、それぞれ別のターミナルで動かします。

---

## 1. スイッチ (`src/switch.php`)

```bash
php src/switch.php
```

設定は環境変数で指定します。

| 環境変数 | 既定値 | 内容 |
| :--- | :--- | :--- |
| `PORT_COUNT` | `4` | ポート数。ケーブルをつなぐ場合は、そのポートも含めて指定する |
| `FIFO_DIR` | `tmp/switch` | 伝送線（FIFO）を置くディレクトリ。2台目のスイッチは別のディレクトリにする |
| `CAPTURE_PATH` | なし | 受信した正常なフレームを libpcap 形式で書き出すファイル |
| `MAC_AGING_SECONDS` | `300` | 学習したMACアドレスの保持時間（秒） |
| `MAC_TABLE_SIZE` | `1024` | MACアドレステーブルに登録できるエントリ数 |
| `MAC_KEEP_ON_LINK_DOWN` | なし | `1` にすると、リンクダウンしてもエントリを消さない（ハブ越しの構成を再現する） |
| `PORT_VLANS` | なし（全ポート VLAN 1） | ポートごとのVLAN。例: `1:10,2:10,3:20,4:20` |
| `TRUNK_PORTS` | なし | トランクにするポート。例: `5` |

> 💡 同じ `FIFO_DIR` でスイッチは1台しか起動できません。2台目を起動しようとすると、`すでに別のスイッチが動いています` と表示して終了します。

---

## 2. PC (`src/node.php`)

```bash
php src/node.php --config=config/nodes/a.json
```

設定ファイル（`config/nodes/a.json` 〜 `g.json`）に、名前、ポート、MACアドレス、IPアドレスなどを書いておきます。引数と環境変数は、設定ファイルより優先されます。

| 引数 | 環境変数 | 設定ファイル | 内容 |
| :--- | :--- | :--- | :--- |
| `--config` | | | 設定ファイル |
| `--port` | `PORT` | `port` | つなぐスイッチのポート（必須） |
| `--mac` | `MACADDRESS` | `mac` | 自分のMACアドレス（必須、12桁の16進数） |
| `--ip` | `IPADDRESS` | `ip` | 自分のIPアドレス。省略するとIPを使わない |
| `--name` | `NODE_NAME` | `name` | 表示名 |
| `--hosts` | | `hosts` | IPアドレスと名前の対応表（`config/hosts`） |
| `--ethers` | | `ethers` | MACアドレスと名前の対応表（`config/ethers`） |
| | `FIFO_DIR` | `fifoDir` | つなぐスイッチの伝送線のディレクトリ |
| `--promiscuous` | | | 宛先に関係なく、すべてのフレームを受信する |
| `--to`、`--message` | | | 1通だけ送って終了する（ワンショット送信） |
| | `BIT_INTERVAL_US` | | 1ビットを送るごとに待つ時間（マイクロ秒）。既定値は1000 |

対話モードでは、次のように入力します。

| 入力 | 動作 |
| :--- | :--- |
| `192.168.1.2 こんにちは` | IPアドレス宛てに送る（MACアドレスはARPで解決する） |
| `B こんにちは` | `config/hosts` の名前のIPアドレス宛てに送る |
| `aabbccddee0b こんにちは` | MACアドレス宛てに、IPを使わずにフレームを直接送る |
| `ALL こんにちは` | ブロードキャストで全員に送る |
| `arp` | ARPキャッシュを表示する |
| `quit` | 終了する |

> 💡 同じポートに2台のPCはつなげません。PCを `Ctrl+C` で終了すると、ケーブルを抜いたのと同じくリンクダウンになります。

---

## 3. ケーブル (`src/cable.php`)

2つのポートをつなぎ、信号をそのまま反対側へ流します。

```bash
php src/cable.php --ports=5,6                                       # 同じスイッチの2ポートをつなぐ (ループになる)
php src/cable.php --ports=5,3 --fifo-dirs=tmp/switch,tmp/switch2    # 2台のスイッチをつなぐ
```

| 引数 | 既定値 | 内容 |
| :--- | :--- | :--- |
| `--ports` | `5,6` | ケーブルの両端を挿すポート |
| `--fifo-dirs` | 両端とも `FIFO_DIR` | 両端のスイッチの伝送線のディレクトリ |
| `--delay-ms` | `0` | 1フレームを通すごとに待つ時間（ミリ秒）。ループをゆっくり観察したいときに使う |

---

## 4. Wireshark で見る

スイッチを `CAPTURE_PATH` 付きで起動し、別のターミナルで Wireshark に流し込みます。

```bash
CAPTURE_PATH=logs/capture.pcap php src/switch.php
```

```bash
tail -f -c +1 logs/capture.pcap | /Applications/Wireshark.app/Contents/MacOS/Wireshark -k -i -
```

- キャプチャは、スイッチが受信した時点のフレームを記録します（ポートミラーリングに相当）。どのポートへ転送したかや、スイッチが送信時に付けたVLANタグは記録しません。
- スイッチを起動し直すとキャプチャファイルが作り直されるので、Wireshark も起動し直してください。古いまま動いていると `Unknown error: -14` で止まります。
- プリアンブル、SFD、FCS は記録しません。FCS エラーなどで破棄したフレームも記録しません。

---

## 5. よく使う構成

### 4台のPCと1台のスイッチ

```bash
php src/switch.php
```

```bash
php src/node.php --config=config/nodes/a.json   # b.json, c.json, d.json も同様
```

### ループ

```bash
PORT_COUNT=6 php src/switch.php
```

```bash
php src/cable.php --ports=5,6
```

### VLAN

```bash
PORT_VLANS=1:10,2:10,3:20,4:20 php src/switch.php
```

### 2台のスイッチとトランク

```bash
PORT_COUNT=5 PORT_VLANS=1:10,2:10,3:20,4:20 TRUNK_PORTS=5 php src/switch.php
```

```bash
FIFO_DIR=tmp/switch2 PORT_COUNT=3 PORT_VLANS=1:10,2:20 TRUNK_PORTS=3 php src/switch.php
```

```bash
php src/cable.php --ports=5,3 --fifo-dirs=tmp/switch,tmp/switch2
```

PC-E（`e.json`）と PC-G（`g.json`）は、設定ファイルの `fifoDir` で2台目のスイッチにつながります。

---

## 6. 実験スクリプト

```bash
php src/experiment.php                       # すべての実験を順に実行する
php src/Experiments/known_unicast.php        # 1つの実験だけ実行する
```

- 各実験は、スイッチとPCを自分で起動し、MACアドレステーブルが空の状態から始めます。
- スイッチのログは `logs/switch.log`（2台の実験では `logs/sw1.log`、`logs/sw2.log`）に残ります。
- 手動で動かしているスイッチがあると、同じ伝送線を使えず実験が始まりません。止めてから実行するか、`FIFO_DIR` で別のディレクトリを指定してください。

```bash
FIFO_DIR=tmp/lab php src/experiment.php
```

実験の一覧と結果は、[L2スイッチ](L2SwitchAndLimits.md)、[ARPとIPv4](NetworkLayer.md)、[VLAN](VLAN.md) の各ドキュメントにあります。

---

## 7. 1対1の通信 (`src/sender.php`, `src/receiver.php`)

スイッチを作る前の、1本の伝送線で2台をつなぐ構成です。Docker Compose で起動します（`make receiver`、`make sender`）。単方向の伝送線なので、送信側は受信側のMACアドレスを静的に登録しています。
