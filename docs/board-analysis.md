# Board analysis

Sources: *IOT Relay User Manual V1.9.8.1* (UM), *IOT Relay Programing Manual V2.0.2* (PM), product pages on dingtian-tech.com and dtwonder.com (read on 2026-10-31).
The "Documented" table reports the sources. What was tried on the real board is under "Observed on the board", and it wins over the sources.

## 1. Hardware

### Documented

| Topic | Fact | Source |
|---|---|---|
| Power | 12/24 V DC recommended; 8 channels: 0.5 A at 12 V, 2 A, 5 W supply recommended | UM 1.2 |
| Inputs | Contact between `In` and board `GND`. LOW 0 V; HIGH 3.3 V (HW < V1.8) or 3.3 to 24 V (HW ≥ V1.8) | UM 3.5 |
| Relays | NO/NC, 30 V DC / 10 A, 250 V AC / 10 A | UM 1.2 |
| Network | Default Ethernet IP 192.168.1.100, admin/admin, DHCP off in the sample screen | UM 4.1 |
| Identity | *Setting* page: hardware and software version, serial. UDP search `05 AA` to 224.0.2.11:60000 | UM 4.2, PM 2.2.8 |
| MQTT | v3.1.1, the board is a client, broker with user and password, **no TLS**, LWT on `/out/lwt_availability` | UM 4.3, PM 5 |
| HTTP CGI | Read relays, read inputs, command with echo; wrong password = no reply (contradicted on the board: it answers `-7`) | PM 3 |
| Modbus TCP | Registers documented, not needed: it does not notify changes | PM 4 |
| Firmware | The standard firmware takes no user firmware: we leave it alone | dtwonder.com |

### Observed on the board (2026-10-31)

Serial, MAC and real network data are kept out of this repository. Here `<SN>` stands for the serial.

- *Setting* page: model Dingtian DT-R008, hardware V3.6J, software V3.1.2178A (build 2023/04/13).
- Hardware ≥ V1.8, so inputs read HIGH between 3.3 and 24 V. Hardware ≥ V3, so reset with the FACTORY button.
- The software is newer than the manuals: HTTPS and the Wi-Fi/AP switches are not described there.
- HTTP Session = No.
- Real MQTT: over Wi-Fi the client is `dingtian-relay<SN>` and topics are `/dingtian/relay<SN>/out/...`, without the `w` the manual shows.
- On connection the board publishes the full state: `lwt_availability`, `hw_version`, `sw_version` (not in the manual), `r1-8`, `i1-8`, `relay1-8`, `input1-8`, `ip`, `sn`, `mac`, `input_cnt`, `relay_cnt`.
- `i1` reads `OFF` while `input1` reads `HIGH`: `iX` is inverted compared with the level, so we use `inputX`.
- The full state is published again every 30 seconds, as *Keep Alive Second* = 30. It works as a heartbeat and as a resync. Duplicates are the norm, so only a change of value counts.
- Input test with a jumper between I1 and GND: closed = LOW, open = HIGH, relays still. On a change the board publishes `i1` and `input1` at once, outside the 30-second cycle.
- Refitting the jumper by hand produced 11 changes in 6 seconds, all received, ending on LOW. The firmware's 40 ms debounce does not filter a contact that unstable, so the flow counts one press per input inside a pause.
- MQTT command on `in/control` (JOGGING, 500 ms) with the Relay Password: `r1` = ON after 108 ms, `r1` = OFF another 488 ms later. Confirmation over MQTT works without HTTP.
- Board off for about 4 minutes: last heartbeat at 20:07:28, `offline` at 20:10:13, so 2 min 45 s later. On return, at 20:12:20, full state published and I1 correct.
- Short interruption (16 seconds at most): `offline` arrived only on reconnection, together with `online`.
- So the panel does not depend on the LWT: the board counts as "not reporting" after 45 seconds of silence, about 2 minutes before `offline`.
- The board's broker fields take 31 characters at most: a longer password gets truncated and the broker answers "not authorised".
- With the broker in Docker Desktop, the log shows the Docker gateway's address, not the board's.
- HTTP reads: `relay_cgi_load.cgi` returns `&0&8&0&0&0&0&0&0&0&0&`, `input.cgi` returns `&0&0&8&1&1&1&1&1&1&1&1&`. Same format as the manual; at rest 8 relays OFF and 8 inputs HIGH.
- `/r.1` answers 404 "This URI does not exist": the per-channel reads in the manual (PM 3.6, 3.7) do not work on this firmware.
- `Input Control Relay` sits on the *Input Link Relay* page, not on *Relay Connect*. It was Yes, with each input driving the relay of the same number; now No, checked after saving. Each input is Momentary, Action Level LOW, Debounce 40 ms.
- *Relay Connect*, MQTT section: Head slash on, QoS 1, Retain on, KeepAlive 120 s, MFR `dingtian`, Area empty. No TLS option. `Power Failure Recovery Relay` is No.
- *IP WatchDog* off. *Relay Task*: the tasks seen (1 to 13 and 20 to 27) are off.
- The board does not close the HTTP connection, so `file_get_contents` waits until its timeout on every request. curl is fine.
- Delay command on R1 (`type=2`, 5 s): reply `&0&2&0&1&5&`, state read ON, then automatic return to OFF.
- Wrong relay password: over HTTP the board answers `&-7&1&0&1&5&`, over MQTT it ignores the command with no message at all. In both cases the relay stays still. Over MQTT the refusal shows only as a missing confirmation.
- MQTT `ON/OFF` command: the slash must not be escaped. With `ON\/OFF`, which is valid JSON, the board ignores the command without a reply; with `ON/OFF` the relay confirms in 65 ms.
- PC on standby from 22:10 to 22:55: broker and bridge stopped, board disconnected. On wake-up the board reconnected in 7 seconds and R1 stayed ON, with no intervention. Inputs moved during that time leave no trace if they return to the previous level.
- From the panel: pulse on R2 confirmed 62 ms after sending, back to OFF another 534 ms later.
- An `ON/OFF` OFF command sent during an 8-second JOGGING stops the relay at once, and it stays off when the original time runs out. The 8-second pulse confirmed in 64 ms.
- From the press reaching the bridge to the relay confirmation: 70 to 122 ms. The time between the physical contact and that reception was not measured.
- Unsolved: the page reports ETH DHCP = No and IP 192.168.1.100, but 15 DHCP Discover from the ETH MAC were captured in 45 seconds on the cable, and that IP did not answer.

### What this means for the project

- **Event time is arrival time.** Messages carry neither a timestamp nor a sequence number. The panel says so.
- **Duplicates.** With no ID, a duplicate can only be told by comparing with the last known state of the channel.
- **Topic `inputX`, not `iX`.** `inputX` reports `HIGH`/`LOW`. `iX` reports `ON`/`OFF` and the manual does not say which level that is.
- **Commands on `in/control` only.** It is the one topic with a `pass` field.
- **Confirming a command.** The board does not answer a command over MQTT. "Sent" only means the broker took it. "Confirmed" means the relay state came back with the expected value.
- **Lost press.** If the network drops during a short press, the event can be lost: no source documents a buffer. On reconnection the state is resynced, not the history.
- **Security is limited by the board.** Clear-text MQTT and a relay password from 0 to 9999. Fine on a controlled LAN, never on the Internet.

### Contradictions between sources

| Topic | Source A | Source B | Decision |
|---|---|---|---|
| MQTT TLS | Download page: "MQTT/MQTTS" | UM: "MQTT(without tls)" | Assume no TLS |
| AC voltage | UM: 12/24 VAC | Download page: "5V/24VAC" | 12 V DC only |
| Current | Product page: 1.5 A at 12 V | UM: 0.5 A, 2 A recommended | 12 V DC, 2 A supply |

The UM 3.5 diagram shows lamps wired to live and neutral: **do not follow it**. This project uses low voltage only.

## 2. MQTT or HTTP CGI?

**HTTP CGI for the first tests**: it needs only an IP, does not depend on a broker and gives a readable result at once.

**MQTT only for the application.** Polling can miss a short press; MQTT reports it at once. The board publishes its full state on connection and every 30 seconds, so HTTP is not needed for resync either. HTTP stays in `diagnostics/`.

## 3. Test sequence

| # | Test | Expected | Where |
|---|---|---|---|
| 1 | Parser on the manual's examples | Correct states, errors refused | No board |
| 2 | `status` and `pulse` on the fake board | ON confirmed, back to OFF | No board |
| 3 | Broker | Anonymous refused, authenticated accepted | No board |
| 4 | *Setting* page | Serial, HW and SW noted | Board |
| 5 | `status` | 8 relays OFF, 8 inputs HIGH | Board |
| 6 | Contact on I1 and `status` | I1 changes, no relay moves | Board |
| 7 | `pulse` on a relay with no load | Audible click, back to OFF | Board |
| 8 | Wrong password | Command refused, relay still | Board |
| 9 | MQTT: `mosquitto_sub` and a contact | Real topics and payloads noted | Board |
| 10 | Board off | `lwt_availability` = `offline`, delay noted | Board |

All passed on 2026-10-31, with a jumper in place of a button.

```bash
docker compose run --rm --entrypoint php diag test.php
```

```bash
docker compose run --rm diag status BOARD_IP
```

```bash
docker compose run --rm diag pulse BOARD_IP 1 --confirm
```

```bash
docker compose exec mosquitto sh -c 'mosquitto_sub -u "$MQTT_USER" -P "$MQTT_PASSWORD" -t "/dingtian/#" -v'
```

## 4. Board checklist

To repeat the bench with another board:

- [ ] 12 V DC, 2 A supply, `V+`/`V-` polarity checked with a meter
- [ ] Hardware ≥ V1.8 and firmware ≥ 2.17 on the *Setting* page
- [ ] **Input Control Relay = No** on *Input Link Relay*: otherwise the inputs move the relays by themselves
- [ ] **Power Failure Recovery Relay = No**
- [ ] No *Relay Task* and no *IP Watchdog* active
- [ ] *Relay Password* equal to `DTR008_RELAY_PASSWORD` in `.env`
- [ ] Address reserved on the router
- [ ] Web password and access point changed if the board leaves a demo bench: in this demo they stay at factory values by choice
