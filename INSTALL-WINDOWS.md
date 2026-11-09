# Installing PyHP on Windows

PyHP ships as a single folder — a Windows launcher (`pyhp.bat`) plus the language
sources. The only prerequisite is **PHP 8.0 or newer (CLI)**; no PHP extensions
are needed.

There are two ways to install it:

1. **The installer** (below) — one command, no admin rights, sets everything up.
2. **The manual route** — Steps 1–5 further down, if you prefer to control every step.

---

## The installer (recommended)

### From PowerShell (copy-paste, one line)

```powershell
irm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 | iex
```

### From cmd.exe (one line)

```bat
curl -fsSL https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 -o "%TEMP%\pyhp-install.ps1" && powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\pyhp-install.ps1"
```

### Or: download the zip and double-click

1. On the repo page (github.com/Stevem100/pyhp) click **Code → Download ZIP**.
2. Extract it, then double-click **`install.bat`**.

### What the installer does

- Installs PyHP to **`C:\pyhp`** (change the location with `-InstallDir`; if
  Windows denies writing to `C:\` the installer tells you exactly how to fix
  it — run the terminal once as Administrator, or pick a per-user folder).
- **PHP handling is automatic** — it reuses PHP if you already have it (PATH,
  `PHP_BIN`, or a previous install); otherwise it downloads PHP 8.3 NTS x64
  (~34 MB, once) **into the PyHP folder**, so PyHP is fully self-contained and
  never conflicts with any other PHP on your system.
- Adds the PyHP folder to your **user PATH**, so `pyhp` works in every new terminal.
- Runs a smoke test and prints next steps.

### Upgrading from PyHP 0.1.x–0.3.x

`pyhp update` only exists from **0.4.0** on — on older installs it prints
`unknown command 'update'` and the banner still says `PyHP 0.1.0`. The fix is
a single step: **re-run the installer above** (it installs the current version
into `C:\pyhp` and adds it to your user PATH). From then on the installed copy
updates itself with `pyhp update` — no more reinstalling.

If typing `pyhp` still shows the old banner or `Press any key to continue`, an
old install folder is still winning the PATH race: run the `uninstall.ps1`/
`uninstall.bat` from **that** folder (or just delete the old folder), reopen
your terminal, and `pyhp version` will show the new one.

### Installer parameters (optional)

Download `install.ps1` first, then run it with parameters:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\install.ps1 -InstallDir C:\pyhp
powershell -NoProfile -ExecutionPolicy Bypass -File .\install.ps1 -NoPath      # don't touch PATH
powershell -NoProfile -ExecutionPolicy Bypass -File .\install.ps1 -SkipPhp     # you manage PHP yourself
```

### Uninstall

Double-click **`uninstall.bat`** in the install folder (or run
`uninstall.ps1 -InstallDir <dir>` from anywhere). It removes the PATH entry and
deletes the files.

---

# Manual install (Steps 1–5)

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

1. Download `pyhp-0.5.0.zip` (from the repo's **Releases** page).
2. Right-click it → **Extract All…** and extract to `C:\`
   (the zip contains a `pyhp\` folder). You end up with:

```
C:\pyhp\
├── pyhp.bat              ← Windows launcher (type: pyhp)
├── INSTALL-WINDOWS.md    ← this file
├── README.md             ← language tour + reference
├── bin\pyhp              ← the language CLI (started by PHP)
├── src\…                 ← lexer, parser, interpreter, transpiler, runtime
├── docs\REFERENCE.md     ← the full language reference
├── examples\*.pyh        ← 10 runnable examples
└── tests\run_tests.php   ← 37-case test suite
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
PyHP 0.5.0 interactive shell — PHP syntax, Python brains, C muscles.
pyhp> x = 5
pyhp> [n ** 2 for n in range(x)]
=> [0, 1, 4, 9, 16]
pyhp> :quit
```

---

## Step 4 (optional) — Make `pyhp` available everywhere

Add `C:\pyhp` (or wherever you extracted) to your PATH, using the same GUI
steps as in Option B, step 4. Keep `pyhp.bat` in the same folder as `bin\` —
the launcher resolves the language files relative to its own location. (The
installer does this automatically and adds `C:\pyhp` for you.)

New terminals will now accept `pyhp …` from any directory.

---

## Step 5 (optional) — Run the test suite

```
cd C:\pyhp
php tests\run_tests.php
```

Expected result: **37/37 PASS** — every test runs on *both* engines
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
- **`pyhp update` keeps the install current** — run it inside any installed
  PyHP (from 0.4.0 on) and it refreshes the folder it lives in, preserving a
  bundled PHP. Install location (e.g. `C:\pyhp`) never changes.
- **`php bin\pyhp …` works everywhere** — the launcher is pure convenience;
  every command in README.md also works in the `php bin\pyhp` form.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `'php' is not recognized` | PHP is not on PATH yet — install per Step 1, then **reopen** the terminal. Already have PHP? Use `setx PHP_BIN "C:\path\to\php.exe"`. |
| `unexpected token 'match'` or similar parse errors from PHP | An old PHP (7.x) is answering first. Run `where php` to see which one; uninstall/deprioritize it or set `PHP_BIN` to the 8.x `php.exe`. |
| `'pyhp' is not recognized` | `cd C:\pyhp` first; in PowerShell use `.\pyhp.bat`; or add the install folder to PATH (Step 4). |
| `pyhp version` shows an old version (e.g. 0.1.0) | An old install folder is first on PATH. Delete it (or run its `uninstall.bat`), reopen the terminal. |
| `unknown command 'update'` | Your install predates 0.4.0 — re-run the current `install.ps1` once; `pyhp update` works from then on. |
| `PyHP: cannot find script '...'` | Give the full path (`pyhp run C:\pyhp\examples\01_hello.pyh`) or `cd` into the install folder. Use backslashes or quotes for paths with spaces. |
| REPL prompt does not echo while typing | Normal on stock Windows PHP (no readline extension) — your input is still received; press Enter to submit. |
| Window flashes and closes | You double-clicked `pyhp.bat`. Run it from a terminal instead (Step 3); double-click without arguments now pauses so you can read the usage text. |
