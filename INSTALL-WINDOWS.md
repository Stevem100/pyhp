# Installing PyHP on Windows

PyHP ships as a single folder — a Windows launcher (`pyhp.bat`) plus the language
sources. The only prerequisite is **PHP 8.0 or newer (CLI)**; no PHP extensions
are needed. Total setup time: about 5 minutes.

---

## Step 1 — Install PHP 8.0+ (one time)

Pick **one** of the three options below.

### Option A — winget (easiest, Windows 10/11)

Open PowerShell or Command Prompt and run:

```
winget install PHP.PHP.8.3
```

`PHP.PHP.8.4` works too. Then **close and reopen your terminal** and verify:

```
php -v
```

You should see `PHP 8.3.x (cli)` (or newer).

### Option B — official PHP zip (manual)

1. Open <https://windows.php.net/download/> in your browser.
2. Under the latest PHP 8.3 row, download the **x64 Non Thread Safe → Zip**
   build. (CLI use does not need the Thread Safe / Apache build.)
3. Right-click the downloaded zip → **Extract All…** → extract to `C:\php`,
   so that `C:\php\php.exe` exists.
4. Add `C:\php` to your PATH:
   1. Press **Win**, type `environment variables`, open
      **Edit the system environment variables** → **Environment Variables…**
   2. Under *User variables*, select **Path** → **Edit…** → **New**
   3. Type `C:\php` → **OK** → **OK** → **OK**.
5. Open a **new** terminal and verify: `php -v`.

### Option C — you already have XAMPP / phpstudy / Laragon

Locate the existing `php.exe`, e.g. `C:\xampp\php\php.exe`, and do **one** of:

- add its folder (`C:\xampp\php`) to PATH exactly as in Option B, step 4, or
- tell PyHP where PHP lives (new terminal afterwards):

  ```
  setx PHP_BIN "C:\xampp\php\php.exe"
  ```

---

## Step 2 — Install PyHP

1. Download `pyhp-0.1.0.zip`.
2. Right-click it → **Extract All…** and extract to `C:\`
   (the zip contains a `pyhp\` folder). You end up with:

```
C:\pyhp\
├── pyhp.bat              ← Windows launcher (type: pyhp)
├── INSTALL-WINDOWS.md    ← this file
├── README.md             ← language tour + reference
├── bin\pyhp              ← the language CLI (started by PHP)
├── src\…                 ← lexer, parser, interpreter, transpiler, runtime
├── examples\*.pyh        ← 5 runnable examples
└── tests\run_tests.php   ← 26-case test suite
```

> Tip: avoid extracting into a path containing spaces or non-ASCII characters.

---

## Step 3 — First run

Open **Command Prompt** or **PowerShell**:

```
C:\> cd C:\pyhp
C:\pyhp> pyhp run examples\01_hello.pyh
C:\pyhp> pyhp compile examples\02_collections.pyh
C:\pyhp> pyhp build examples\01_hello.pyh -o hello.php
C:\pyhp> php hello.php
C:\pyhp> pyhp repl
```

In PowerShell, if `pyhp` is not recognized, run `.\pyhp.bat run …`
(PowerShell requires the `.\` prefix for scripts in the current folder),
or use the plain-PHP form `php bin\pyhp run examples\01_hello.pyh`.

### REPL quick tour

```
C:\pyhp> pyhp repl
PyHP 0.1.0 interactive shell — PHP syntax, Python brains, C muscles.
pyhp> x = 5
pyhp> [n ** 2 for n in range(x)]
=> [0, 1, 4, 9, 16]
pyhp> :quit
```

---

## Step 4 (optional) — Make `pyhp` available everywhere

Add `C:\pyhp` to your PATH, using the same GUI steps as in Option B, step 4.
Keep `pyhp.bat` in the same folder as `bin\` — the launcher resolves the
language files relative to its own location.

New terminals will now accept `pyhp …` from any directory.

---

## Step 5 (optional) — Run the test suite

```
cd C:\pyhp
php tests\run_tests.php
```

Expected result: **26/26 PASS** — every test runs on *both* engines
(built-in interpreter and transpiled fast path) and must produce
byte-identical output on each.

---

## Windows notes

- **Exiting the REPL**: type `:quit` (or press **Ctrl+Z** then **Enter**).
  Ctrl+D is a Linux/macOS shortcut.
- **Garbled characters**: run `chcp 65001` to switch the console to UTF-8.
  Example source comments contain UTF-8 punctuation (e.g. `—`), which the
  default legacy codepage may not display correctly.
- **First launch of php.exe** may trigger a SmartScreen warning →
  *More info* → *Run anyway*.
- **PowerShell execution policy** does not affect PyHP: `pyhp.bat` is a plain
  batch file, not a PowerShell script.
- **`php bin\pyhp …` works everywhere** — the launcher is pure convenience;
  every command in README.md also works in the `php bin\pyhp` form.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `'php' is not recognized` | PHP is not on PATH yet — install per Step 1, then **reopen** the terminal. Already have PHP? Use `setx PHP_BIN "C:\path\to\php.exe"`. |
| `unexpected token 'match'` or similar parse errors from PHP | An old PHP (7.x) is answering first. Run `where php` to see which one; uninstall/deprioritize it or set `PHP_BIN` to the 8.x `php.exe`. |
| `'pyhp' is not recognized` | `cd C:\pyhp` first; in PowerShell use `.\pyhp.bat`; or add `C:\pyhp` to PATH (Step 4). |
| `PyHP: cannot find script '...'` | Give the full path (`pyhp run C:\pyhp\examples\01_hello.pyh`) or `cd` into `C:\pyhp`. Use backslashes or quotes for paths with spaces. |
| REPL prompt does not echo while typing | Normal on stock Windows PHP (no readline extension) — your input is still received; press Enter to submit. |
| Window flashes and closes | You double-clicked `pyhp.bat`. Run it from a terminal instead (Step 3); double-click without arguments now pauses so you can read the usage text. |
