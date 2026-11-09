# AI Development with PyHP

PyHP is a strong fit for AI work where you want **scripting speed of
authoring** and **C-level execution**: data plumbing in clean PHP-family
syntax, numerics that compile to native machine code with
`pyhp build --target native`, and a dependency story that works like
Composer/npm (`pyhp pkg`).

This guide is written against PyHP 0.3.0. Every snippet was executed on all
three engines (interpreter, PHP build, native build) with byte-identical
results. Run the full walkthrough with
`pyhp run examples/08_ai_ml.pyh`.

## 1. The mental model

- **Syntax** — PHP braces-and-semicolons, no `$` before variables.
- **Semantics** — Python: lists/dicts alias like Python objects, slicing with
  steps, comprehensions, lambdas, classes with `super`, Python truthiness.
- **Muscles** — C: static types, bitwise ops, hex/binary literals, `cpp {
  ... }` blocks, and a native C++ backend for whole programs.

## 2. Loading the AI toolkit

`ai` ships with the language — no install step:

```c
ai = require("ai");
```

Everything in `ai` is pure and deterministic (seeded LCGs instead of wall-clock
randomness), so training runs are reproducible and cross-engine identical.

## 3. Regression in 8 lines

```c
ai = require("ai");

xs = [0, 1, 2, 3, 4, 5, 6, 7];
ys = [1.1, 2.9, 5.2, 6.8, 9.2, 11.1, 12.8, 15.2];

model = ai.linear_fit(xs, ys, 0.02, 4000);   // gradient descent on MSE
echo "y = ", model.w, "x + ", model.b, "\n";
echo "x=10 -> ", ai.linear_predict(model, 10), "\n";
pred = [ai.linear_predict(model, x) for x in xs];
echo "R^2 = ", ai.r2(pred, ys), "\n";
```

`linear_fit` returns `{"w", "b", "loss"}`. Increase `epochs` until `loss`
flattens; `lr` around `0.01..0.1` works for inputs in small ranges — use
`ai.standardize(xs)` first when they don't.

## 4. Classification metrics

```c
actual  = ["spam", "ham", "spam", "spam", "ham"];
guessed = ["spam", "ham", "ham",  "spam", "ham"];

echo ai.accuracy(guessed, actual), "\n";   // 0.8
echo ai.mse([2, 4], [3, 5]), "\n";         // 1.0
echo ai.mae([2, 4], [3, 5]), "\n";         // 1.0
```

## 5. Clustering

```c
points = [[0, 0], [1, 0], [0, 1], [9, 9], [10, 9], [9, 10]];
km = ai.kmeans(points, 2, 20);             // {"centroids", "labels", "inertia"}
echo km.labels, "\n";                      // [0, 0, 0, 1, 1, 1]
echo ai.nearest_centroid(km, [8, 10]), "\n";  // 1
```

k-means starts from deterministic spread-out points, so the same input always
produces the same clustering on every engine.

## 6. Feature engineering & splits

```c
z = ai.standardize([10, 20, 30, 40]);      // {"values", "mean", "std"}
norm = ai.minmax([14, 22, 35, 47]);        // [0, 0.21.., 0.56.., 1]

split = ai.train_test_split(range(100), 0.3, 7);   // (xs, testRatio, seed)
train = split.train;  test = split.test;
```

## 7. When it gets heavy: embed C++ or compile native

Two escalation paths, both first-class:

**a) A hot kernel as a `cpp { ... }` block** (interpreted PyHP stays in
control of the loop scaffolding):

```c
cpp {
    // pyhp-export: double pyh_sqdist(int64_t n, const char* unused);
    double pyh_sqdist(int64_t n, const char* unused) { return n * n; }
}
// bridge types are int64_t / double / const char*
```

**b) Compile the whole program to a native binary** — the AI code itself
becomes machine code:

```
pyhp build train.pyh --target native -o train
./train
```

Benchmarks on the bundled examples: the native binary evaluates the
`ai.linear_fit` walkthrough ~5-10x faster than the interpreter (gradient-descent
loops are pure arithmetic — exactly what the C++ runtime is good at), while the
code you write stays identical.

## 8. JSON data pipelines

AI glue code lives on JSON; PyHP has it built in (also in the native backend):

```c
row = json_parse('{"text": "great phone", "label": "pos"}');
echo row.label, " ", row.text.upper(), "\n";
out = json_string({"pred": 0.97, "label": "pos"});
file_write("predictions.jsonl", out + "\n");   // NDJSON append with file_append
```

## 9. Packaging your model library

```c
mkdir mymodels && cd mymodels
pyhp pkg init mymodels       # pyhp.json + src/mymodels.pyh
# ... implement functions in src/mymodels.pyh ...
pyhp pkg add mathx           # declare a dependency from the registry
```

Consumers then do `m = require("mymodels");`. The manifest is `pyhp.json`,
packages land in `vendor/` — the same mental model as Composer/npm, sized for a
language standard library.

## 10. Determinism note

`ai.rng_next(state)` / `ai.rand_list(seed, n)` are 32-bit LCGs chosen so that
every intermediate value fits exactly in a 64-bit integer — the sequences are
bit-identical in the interpreter, the PHP build and the native build. Don't use
them for cryptography (see the `sec` guide for security-relevant helpers and
their limits).
