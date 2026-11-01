# Simulation video

`docs/media/bench-simulation.mp4`: 54 seconds, no voice, text on screen. The frame is split: an animated bench on the left, the real panel on the right.

## What is real and what is not

- **Real:** the panel, the workflow rules and the database. The recording placed real orders through `POST /api/orders`, typed the scans and pressed the buttons on the panel page and the simulator controls.
- **Simulated:** the board (`SimulatedAdapter`), the orders and customers, and the whole bench on the left: shelf, lamps, baskets, hand, reader and buzzer are a drawing. Its lamps follow the state the panel reported during the recording.
- **Added in editing:** the orange frames and zooms on the panel, the moving signals, the step bar, the captions and the closing card with the Levixflow name and site. The signals are a picture of the path, not a measurement of it.
- **Not shown:** nothing in the video proves the physical bench works, because it was never built.

## What not to say

- No clients, savings or results: there are none.
- Not "real time" or "reliable": it is a prototype on a local network.
- The 70 to 122 ms figure runs from reception at the bridge to the relay confirmation, not from a physical contact. If you quote it, say so.
- A scan proves the code read, not what is in the parcel.
- Always say the bench is an animation.
- Do not frame the *Board* page if a real board is active: it shows serial and address. No `.env` on screen.
