# Wiring the bench

Everything here is low voltage. No mains, no machinery, no safety devices.

Status: this plan has not been built, and the project does not need it: the simulator and the video cover the same flow. The DT-R008 side comes from the board analysis. Check any parts you buy against their own markings before connecting them.

## Parts

| Qty | Part | Job | Check when buying |
|---:|---|---|---|
| 4 | 30 mm arcade button with LED, blue | Bins A to D: lamp and button in one piece | LED rated **12 V**, not 5 V |
| 1 | 30 mm arcade button with LED, green | Parcel ready, and the green lamp | LED rated **12 V** |
| 1 | Red push button, normally open | Bin empty | No LED needed |
| 1 | Active buzzer, 12 V | Wrong pick | **Active**, with its own oscillator. A passive one stays silent on DC |
| 1 | Inline fuse holder and 1 A fuse | Protects the 12 V lamp wiring | |
| 3 | 5-way lever connectors | The +12 V, 0 V and input GND buses | |
| 1 set | Stranded wire, 0.5 mm², and spade connectors | Everything else | Match the connector size to the button tabs: sizes differ between brands |
| 1 | USB barcode reader, 2D imager | Item scans | A 2D imager also reads a code on a phone screen, so no printer is needed. A laser reader does not |
| 1 | USB webcam | Parcel photo | Any UVC webcam |

Already on the bench: the DT-R008 and its 12 V DC supply. The manual recommends 2 A; check yours. The lamps and buzzer draw little, but the supply also feeds the board.

## 12 V: lamps and buzzer

Relay contacts are dry, so the same 12 V supply can feed the board and these loads. Use the NO contact: with the board off, everything stays off.

```text
Supply +12 V ─┬─ board V+
              └─ fuse 1 A ── +12 V bus ─┬─ R1 COM   R1 NO ── LED+ button A  LED- ─┐
                                        ├─ R2 COM   R2 NO ── LED+ button B  LED- ─┤
                                        ├─ R3 COM   R3 NO ── LED+ button C  LED- ─┤
                                        ├─ R4 COM   R4 NO ── LED+ button D  LED- ─┤
                                        ├─ R5 COM   R5 NO ── LED+ green     LED- ─┤
                                        └─ R6 COM   R6 NO ── buzzer +    buzzer - ─┤
Supply 0 V ───┬─ board V-                                                         │
              └─ 0 V bus ─────────────────────────────────────────────────────────┘
```

LEDs and the buzzer have a polarity. Try each one on the 12 V supply, through the fuse, before wiring it in: if an LED stays dark, swap its two wires and mark the + side.

## Inputs

An input is active when its contact closes to the board's GND terminal, exactly like the jumper used in the tests. On each button use the two switch tabs marked COM and NO, not NC.

```text
Board GND ── input GND bus ─┬─ COM button A  NO ── I1
                            ├─ COM button B  NO ── I2
                            ├─ COM button C  NO ── I3
                            ├─ COM button D  NO ── I4
                            ├─ COM red button NO ── I5
                            └─ COM green button NO ── I6
```

No voltage goes on these wires from outside.

## Bringing it up

Check terminal names on the board's own printing, and test with a meter before power goes on.

1. **Power off.** With a meter, confirm no short between +12 V and 0 V.
2. **Board page, stack running, DT-R008 active.** Press *Test* on R1 to R6 one at a time and check that the right lamp flashes.
3. **Inputs.** Press each button and watch its state change on the same page.
4. **One order.** Add a test order on the Bench page and work it through, with a button first and then the reader.

## Barcode reader

The reader types like a keyboard and ends each code with Enter, which is what the Bench page expects. Most readers default to a US layout. Item codes have only letters and digits, so they arrive intact on an Italian keyboard too.

Print the labels from *Stock*, or show them on a phone if the reader is a 2D imager.

## Webcam

*Turn on camera* on the Bench page. The browser asks for permission, and the picture stays on this machine. The Bench page must stay open: the photo is taken by that page when *Parcel ready* closes the order.
