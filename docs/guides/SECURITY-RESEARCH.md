# Security Research with PyHP

PyHP's pitch for security work: **readable tooling you can hand to a shell**.
The `sec` stdlib module covers the classical-crypto and analysis layer, the
language itself gives you C-level primitives (bitwise ops, hex literals,
`cpp { ... }` blocks, `exec()`/`system()` for piping to other tools), and
`pyhp build --target native` produces self-contained binaries you can drop on a
test box with no runtime installed.

**Scope & ethics** — this toolkit targets CTFs, labs, and systems you are
explicitly authorized to test. Classical ciphers and toy generators are for
education; they are not production cryptography.

Run the full walkthrough with `php bin/pyhp run examples/09_security.pyh`.

## 1. Classical ciphers, forward and backward

```c
sec = require("sec");

sec.caesar("Attack at dawn", 3);      // Dwwdfn dw gdzq
sec.rot13("Hello");                   // Uryyb
vig = sec.vigenere("ATTACKATDAWN", "LEMON");   // LXFOPVEFRNHR
sec.vigenere_dec(vig, "LEMON");       // ATTACKATDAWN
x = sec.xor_cipher("hidden message", "key");   // repeating-key XOR
sec.xor_cipher(x, "key");             // hidden message  (encrypt == decrypt)
```

## 2. Brute-forcing Caesar with English scoring

```c
cracked = sec.crack_caesar("Aol xbpjr iyvdu mve qbtwz vcly aol shgf kvn");
echo cracked.plaintext, " (shift ", cracked.shift, ")\n";
// The quick brown fox jumps over the lazy dog (shift 7)
```

`crack_caesar` tries all 26 shifts and picks the smallest chi-square score
against English letter frequencies (`sec.english_score`). `sec.char_freq(s)`
gives you the sorted `[char, count]` table for your own analysis passes.

## 3. Entropy & structure sniffing

```c
sec.entropy("hello world");        // 2.845 bits/char  (structured)
sec.entropy("x7$Q#mZ!2");          // 3.17  bits/char  (near-random for its length)
sec.log2(8);                       // 3.0  — pure-PyHP log2, engine-exact
sec.hexdump("GET /flag HTTP/1.1");
// 00000000  47 45 54 20 2f 66 6c 61 67 20 48 54 54 50 2f 31  |GET /flag HTTP/1|
```

Rule of thumb while triaging strings: high entropy + low ASCII-printability =
encoded/encrypted blob; hexdump it.

## 4. Network math without external tools

```c
net = sec.subnet("10.0.5.130/26");
// {"network": "10.0.5.128", "broadcast": "10.0.5.191", "mask": "255.255.255.192",
//  "first": "10.0.5.129", "last": "10.0.5.190", "hosts": 62, "prefix": 26}
sec.ip_to_int("10.0.0.1");     // 167772161
sec.int_to_ip(167772161);      // 10.0.0.1
sec.host_range("192.168.1.0/29", 16);   // ["192.168.1.1", ... ".6"] (capped, on purpose)
```

## 5. Hashing and encodings

Built-ins (no module needed): `md5(s)`, `sha256(s)`, `crc32(s)`,
`base64e/base64d`, `urlenc/urldec`. File hashing:

```c
sec = require("sec");
echo sec.hash_file("/etc/hosts"), "\n";    // sha256 hex
```

`sha256` is also implemented inside the native C++ backend, so a compiled
tool hashes with the same digest — verified byte-identical against the PHP
engines by the test suite.

## 6. Orchestration: PyHP as the glue

The "C DNA" built-ins are the bridge to the rest of your toolchain:

```c
out = exec("nmap -p 80,443 10.0.0.0/24");    // {"stdout": ..., "code": ...}
if out.code == 0 {
    foreach line in out.stdout.split("\n") {
        if line.contains("open") { echo "[+] ", line, "\n"; }
    }
}
```

`file_read/file_write/file_append/file_exists/mkdir/env` cover the lab-report
side: write findings as NDJSON (`json_string` one record per line), hash every
artifact you collected, and archive outputs with `base64e`.

## 7. Drop-in native tools

```c
// recon-notes.pyh — becomes ./recon-notes, no interpreter needed on the box
echo "sha256 of the file: ", sha256(file_read(argv()[0])), "\n";
```

```bash
php bin/pyhp build recon-notes.pyh --target native -o recon-notes
./recon-notes evidence.txt
```

Static-type annotations (`int port = 80;`) catch config mistakes before they
hit the wire; `try/catch` around `exec()` keeps a scan running when a single
host misbehaves.

## 8. What this toolkit deliberately is *not*

- Not a crypto library: `xor_cipher`, Caesar/Vigenère and `password_gen` are
  educational and demo-grade. `password_gen` is seeded-deterministic so demos
  reproduce — use a real CSPRNG for anything real.
- Not a scanner: `host_range` is capped and pure math. Pair it with your own
  authorized tooling via `exec()`.
- Not stealthy: readability and reproducibility are the design goals.

If you need speed on a heavy analysis loop (e.g. scoring big corpora), drop the
inner loop into a `cpp { ... }` block or compile the whole tool natively — the
`ai` guide shows both escalation paths with numbers.
