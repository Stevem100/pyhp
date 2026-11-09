# IoT with PyHP

Edge and gateway code wants three things: **tiny readable logic**, **checksums
and framing you can trust**, and **a binary that runs without a runtime**.
PyHP hits all three: the `iot` stdlib module for signal conditioning and
telemetry, a CRC-16/Modbus implementation verified against the reference
vector, JSON/NDJSON built in, and `pyhp build --target native` to fuse your
PyHP plus any embedded C++ into one executable for the device.

Run the full walkthrough with `pyhp run examples/10_iot.pyh`.

## 1. Simulate, then condition, a sensor

```c
iot = require("iot");

raw = iot.sensor_sim(42, 12, 21.0, 1.5);   // deterministic stream: seed, n, base, noise
smooth = iot.moving_avg(raw, 4);           // trailing window
fast   = iot.ema(raw, 0.6);                // exponential smoothing
st     = iot.rolling_stats(raw);           // {"min","max","mean","std","count"}
```

Everything is seeded-deterministic: the same call yields the same readings on a
laptop, in CI, and on the compiled device firmware logic — which makes
regression tests for your telemetry pipeline trivial.

## 2. Alarms with hysteresis

```c
flags = iot.threshold_flags(raw, 20.0, 22.5);   // bool per reading

prev = null;
foreach x in raw {
    prev = iot.deadband(x, prev, 0.4);   // ignore jitter < 0.4
    // act only when `prev` actually moved
}
echo iot.clamp(15, 0, 10), "\n";          // 10
```

## 3. CRC-16/Modbus integrity

```c
iot.crc16("123456789");    // 19255  (0x4B37 — the standard reference vector)
```

The implementation is pure PyHP: identical results in the interpreter, the PHP
build and the native build, so a gateway script and compiled device code agree
on every frame.

## 4. Telemetry: JSON in, NDJSON out

```c
line = iot.telemetry("greenhouse/th-1", [21.5, 21.7], 1730000000);
// {"device":"greenhouse/th-1","ts":1730000000,"readings":[21.5,21.7]}

doc = iot.parse_telemetry(line);      // json_parse under the hood
echo doc.device, " ", doc.readings[0], "\n";

batch = iot.telemetry_batch("th-1", readings, ts, 2);   // NDJSON, 2 readings per line
file_append("ingest.ndjson", batch);
```

`json_parse` / `json_string` are language built-ins (also in the native
backend), so ingestion and emission never need an extra dependency.

## 5. Publish/subscribe-style framing with CRC trailers

```c
wire = iot.mqtt_publish("greenhouse/th-1", smooth, 1730000000);
msg  = iot.mqtt_consume(wire);          // verifies CRC, then parses
echo msg.topic, "\n";                   // greenhouse/th-1/telemetry
echo json_parse(msg.payload).readings, "\n";
```

The frame layout is documented and stable:

```
[len(topic): 2 bytes big-endian][topic][len(payload): 4 bytes big-endian][payload][crc16: 4 hex chars]
```

(Trailer bytes are ASCII hex on purpose — PyHP strings are Unicode, and
keeping frames ASCII means `len()` and slicing behave identically in every
engine and transport.)

## 6. Native deployment: one binary for the device

```c
// firmware_logic.pyh
iot = require("iot");

cpp {
    // pyhp-export: int64_t pyh_adc_scale(int64_t raw);
    int64_t pyh_adc_scale(int64_t raw) {          // 12-bit ADC -> centivolts
        return raw * 3300 / 4095;
    }
}

while true {
    raw = 2748;                                    // ...read from the ADC
    volts = pyh_adc_scale(raw) / 1000.0;           // C-speed conversion
    echo iot.telemetry("sensor-1", [volts], time()), "\n";
    sleep(1000);
}
```

```bash
pyhp build firmware_logic.pyh --target native -o sensor-agent
scp sensor-agent gateway:/usr/local/bin/
```

The `cpp { ... }` block is where hardware-specific code lives (register math,
protocol bit twiddling — PyHP's bitwise operators `& | ^ ~ << >>` and
`0x`/`0b` literals cover the glue). The PyHP around it handles control flow,
JSON and logging.

## 7. Talking to real hardware

For buses that need an OS behind them, use `exec()`/`system()` to drive the
platform's own tools and keep PyHP as the logic layer:

```c
out = exec("i2cget -y 1 0x48 0x00");     // {"stdout": "0x1e", "code": 0}
raw = int(out.stdout[2:]);               // "1e" -> 30 (int() parses decimal)
```

Keep the boundary at the process call: parse with slices + `int()`, or move
hex/binary decoding into a tiny `cpp { ... }` helper where `strtol` is
available.

## 8. Sizing note

The native binary for the bundled `10_iot.pyh` example is a self-contained
executable (typical build ~200 KB with -O2). Startup is single-digit
milliseconds versus ~30-60 ms for the PHP interpreter path — relevant when a
cron-like loop wakes the device repeatedly.
