<?php
/**
 * PyHP test suite — every case runs twice: through the tree-walking
 * interpreter and through the transpiler (generated PHP executed by php).
 *
 * Run:  php tests/run_tests.php
 */

require_once __DIR__ . '/../src/runtime.php';
require_once __DIR__ . '/../src/Lexer.php';
require_once __DIR__ . '/../src/Parser.php';
require_once __DIR__ . '/../src/Interpreter.php';
require_once __DIR__ . '/../src/Transpiler.php';

/* ================================================================ */
/* test cases                                                       */
/* ================================================================ */

$T = [];

$T[] = ['arithmetic & precedence', <<<'CODE'
print(2 + 3 * 4);
print((2 + 3) * 4);
print(2 ** 3 ** 2);
print(-2 ** 2);
print(10 % 3);
print(-7 % 3);
print(7 % -3);
print(7 / 2);
print(1 + 2.5);
print(2 + "x");
CODE
, "14\n20\n512\n-4\n1\n2\n-2\n3.5\n3.5\n2x\n"];

$T[] = ['strings & f-strings', <<<'CODE'
s = "hello";
print(s.upper(), s[0], s[-1], len(s));
print("ab" + "cd", "rep" * 2);
name = "PyHP";
print(f"hi {name}, 1+1={1 + 1}, {{literal}}");
print("a\tb\nc".find("\n"));
print("Hello, World".split(", "));
print("-".join(["a", "b", "c"]));
print("abcdef"[1:4], "abcdef"[::-2]);
CODE
, "HELLO h o 5\nabcd reprep\nhi PyHP, 1+1=2, {literal}\n3\n['Hello', 'World']\na-b-c\nbcd fdb\n"];

$T[] = ['equality & truthiness', <<<'CODE'
print(1 == 1.0, "1" == 1, true == 1, [1, 2] == [1, 2]);
print({"a": 1} == {"a": 1}, null == null, null == 0);
print("a" < "b", [1, 2] < [1, 3], 2 != 2);
print(true and 1, false or "x", not "");
CODE
, "true false true true\ntrue true false\ntrue true false\n1 x true\n"];

$T[] = ['list methods', <<<'CODE'
xs = [3, 1, 2];
xs.append(5); xs.insert(0, 9);
print(xs);
xs.sort();
print(xs, xs.index(3), xs.count(2));
print(xs.pop(), xs.pop(0), xs);
print([1, 2] + [3], [0] * 3, 2 in xs, 7 not in xs);
try { xs[99]; } catch (IndexError e) { print("caught:", e.message); }
CODE
, "[9, 3, 1, 2, 5]\n[1, 2, 3, 5, 9] 2 1\n9 1 [2, 3, 5]\n[1, 2, 3] [0, 0, 0] true true\ncaught: list index 99 out of range (length 3)\n"];

$T[] = ['slicing', <<<'CODE'
xs = [0, 10, 20, 30, 40];
print(xs[1:3], xs[:2], xs[3:], xs[-2:], xs[::2], xs[::-1]);
print("python"[1:4], "abc"[::-1]);
try { xs[::0]; } catch (ValueError e) { print("caught:", e.message); }
CODE
, "[10, 20] [0, 10] [30, 40] [30, 40] [0, 20, 40] [40, 30, 20, 10, 0]\nyth cba\ncaught: slice step cannot be zero\n"];

$T[] = ['dicts', <<<'CODE'
m = {"a": 1, "b": 2, 3: "three"};
print(m["a"], m[3], len(m));
m["c"] = 9;
print(m.get("z", "dflt"), m.has("c"));
print(m.keys(), m.values());
print({"x": 1, "y": 2} == {"y": 2, "x": 1});
foreach k, v in m { echo k, " "; }
print("");
try { m["nope"]; } catch (KeyError e) { print("caught:", e.message); }
n = m.remove("a");
print(n, m);
print({"a": 1} + {"b": 2});
CODE
, "1 three 3\ndflt true\n['a', 'b', 3, 'c'] [1, 2, 'three', 9]\ntrue\na b 3 c \ncaught: key not found: 'nope'\n1 {'b': 2, 3: 'three', 'c': 9}\n{'a': 1, 'b': 2}\n"];

$T[] = ['comprehensions', <<<'CODE'
xs = [1, 2, 3, 4, 5];
print([x * x for x in xs]);
print([x for x in xs if x % 2 == 1]);
print({k: v * 10 for k, v in {"a": 1, "b": 2}});
print([c.upper() for c in "abc"]);
n = 3;
print([x + n for x in xs if x != n]);
print(dict([[1, "a"], [2, "b"]]));
CODE
, "[1, 4, 9, 16, 25]\n[1, 3, 5]\n{'a': 10, 'b': 20}\n['A', 'B', 'C']\n[4, 5, 7, 8]\n{1: 'a', 2: 'b'}\n"];

$T[] = ['control flow', <<<'CODE'
total = 0;
for (int i = 0; i < 10; i++) {
    if i == 3 { continue; }
    if i == 8 { break; }
    total += i;
}
print(total);
n = 0;
do { n++; } while n < 3;
print(n);
foreach x in [1, 2] {
    foreach y in [10, 20] {
        if y == 20 { break; }
        print(x * y);
    }
}
grade = 85;
if grade >= 90 { print("A"); } elseif grade >= 80 { print("B"); } else { print("C"); }
CODE
, "25\n3\n10\n20\nB\n"];

$T[] = ['functions, defaults, variadics, recursion', <<<'CODE'
function add(int a, int b) -> int { return a + b; }
print(add(2, 3));
function greet(name, greeting = "Hello") { return greeting + ", " + name + "!"; }
print(greet("Ada"), greet("Bob", "Yo"));
function sum_all(...nums) { return sum(nums); }
print(sum_all(1, 2, 3, 4));
function fib(int n) -> int { return n < 2 ? n : fib(n - 1) + fib(n - 2); }
print(fib(12));
sq = lambda x: x * x;
print(map(sq, [1, 2, 3]));
function make_counter() {
    n = 0;
    return function() { n += 1; return n; };
}
c = make_counter();
c(); c();
print(c());
function fact(n) {
    if n <= 1 { return 1; }
    function inner(k) { return k * fact(k - 1); }
    return inner(n);
}
print(fact(5));
try { add(1); } catch (TypeError e) { print("caught:", e.message); }
CODE
, "5\nHello, Ada! Yo, Bob!\n10\n144\n[1, 4, 9]\n3\n120\ncaught: add() missing required argument 'b'\n"];

$T[] = ['classes & inheritance', <<<'CODE'
class Animal {
    string name;
    function __construct(string name) { this.name = name; }
    function speak() { return this.name + " speaks"; }
}
class Dog extends Animal {
    function speak() { return super.speak() + " (woof)"; }
}
d = Dog("Rex");
print(d.speak(), isinstance(d, "Animal"), type(d));
print(d.name);
try { throw ValueError("bad"); } catch (ValueError e) { print("caught:", e.message); }
class Oops extends Exception {}
o = Oops("custom");
print(o.message, isinstance(o, "Exception"));
CODE
, "Rex speaks (woof) true Dog\nRex\ncaught: bad\ncustom true\n"];

$T[] = ['errors & exceptions', <<<'CODE'
try { throw "plain string"; } catch (RuntimeError e) { print(e.message); }
try { xs = [1]; print(xs[5]); } catch (e) { print(type(e), e.message); }
try { m = {}; m["k"]; } catch (KeyError e) { print("key"); }
try { 1 / 0; } catch (ZeroDivisionError e) { print("div"); }
try { int("nope"); } catch (ValueError e) { print("val"); }
try {
    try { throw ValueError("first"); } catch (ValueError e) { throw e; }
} catch (Exception e) { print("recaught:", e.message); }
try { print("body"); } finally { print("fin"); }
CODE
, "plain string\nIndexError list index 5 out of range (length 1)\nkey\ndiv\nval\nrecaught: first\nbody\nfin\n"];

$T[] = ['bitwise, hex & binary', <<<'CODE'
print(0xF0 & 0x0F, 0xF0 | 0x0F, 0xFF ^ 0x0F, ~5, 1 << 4, -16 >> 2);
print(hex(255), bin(10), ord("A"), chr(97));
f = 0b1010;
f |= 0b0101;
f &= 0b1110;
f ^= 0b1000;
print(bin(f));
CODE
, "0 255 240 -6 16 -4\n0xff 0b1010 65 a\n0b110\n"];

$T[] = ['static typing', <<<'CODE'
int t = 5;
t *= 3;
print(t);
float g = 2;
print(g);
string s = "x";
s += "!";
print(s);
list xs = [1];
dict d = {};
function clamp(int v, int lo, int hi) -> int { return v < lo ? lo : (v > hi ? hi : v); }
print(clamp(150, 0, 100));
try { int bad = 1; bad = "x"; } catch (TypeError e) { print("typed:", e.message); }
try { function nope() -> int { return "s"; } nope(); } catch (TypeError e) { print("ret:", e.message); }
try { t /= 2; } catch (TypeError e) { print("compound:", e.message); }
CODE
, "15\n2.0\nx!\n100\ntyped: type mismatch: cannot assign string to int variable 'bad'\nret: type mismatch: cannot assign string to int variable 'nope() return'\ncompound: type mismatch: cannot assign float to int variable 't'\n"];

$T[] = ['memory arena (malloc/free)', <<<'CODE'
arena = malloc_arena();
buf = arena.alloc(4);
buf.fill(65);
print(buf.to_string(), buf[0], buf.size());
buf.set(3, 90);
print(buf[3]);
try { buf.get(4); } catch (MemError e) { print("bounds"); }
try { buf.set(0, 300); } catch (ValueError e) { print("byte-range"); }
try { buf.write("12345", 0); } catch (MemError e) { print("overflow"); }
print(arena.used());
arena.free();
try { arena.free(); } catch (MemError e) { print("double"); }
try { buf[0]; } catch (MemError e) { print("uaf"); }
try { arena.alloc(2); } catch (MemError e) { print("dead"); }
CODE
, "AAAA 65 4\n90\nbounds\nbyte-range\noverflow\n4\ndouble\nuaf\ndead\n"];

$T[] = ['files & system access', <<<'CODE'
path = "/tmp/pyhp_suite_file.txt";
file_write(path, "one\ntwo");
print(file_read(path));
file_append(path, "\nthree");
print(file_read(path).split("\n"), len(file_read(path).split("\n")));
print(file_exists(path));
file_delete(path);
print(file_exists(path));
r = exec("echo pyhp-shell-ok");
print(r["stdout"], r["code"] == 0);
print(env("PATH") != null);
t1 = time();
sleep(3);
print(time() >= t1);
CODE
, "one\ntwo\n['one', 'two', 'three'] 3\ntrue\nfalse\npyhp-shell-ok true\ntrue\ntrue\n"];

$T[] = ['builtin functions', <<<'CODE'
print(range(4), range(2, 5), range(10, 0, -3));
print(len(range(3)), sum([1, 2, 3]), sum([1, 2], 10));
print(min([3, 1, 2]), max(3, 1, 2), abs(-4), floor(2.7), ceil(2.1), round(2.567, 2), sqrt(16));
print(sorted([3, 1, 2]), reversed([1, 2, 3]), enumerate(["a", "b"]));
print(zip([1, 2], ["x", "y"]));
print(any([0, null, false]), all([1, "x", true]));
print(int("42"), int(3.99), float("2.5"), str(12), bool(0), bool("x"));
print(list("abc"), list({"a": 1}), dict([[1, "a"]]));
print(type(1), type(1.5), type("s"), type(true), type(null), type([1]), type({"a": 1}), type(lambda x: x));
print(repr("hi"), repr([1, "a"]));
print(rand(1), rand(5, 5));
try { int("3.9"); } catch (ValueError e) { print("strict int"); }
CODE
, "[0, 1, 2, 3] [2, 3, 4] [10, 7, 4, 1]\n3 6 13\n1 3 4 2 3 2.57 4.0\n[1, 2, 3] [3, 2, 1] [[0, 'a'], [1, 'b']]\n[[1, 'x'], [2, 'y']]\nfalse true\n42 3 2.5 12 false true\n['a', 'b', 'c'] ['a'] {1: 'a'}\nint float string bool null list dict function\n'hi' [1, 'a']\n0 5\nstrict int\n"];

$T[] = ['argv', <<<'CODE'
a = argv();
print(len(a), a[0], int(a[1]));
CODE
, "2 hello 42\n", ['hello', '42']];

$T[] = ['foreach variants', <<<'CODE'
foreach x in [10, 20] { echo x, " "; }
print("");
foreach i, x in ["a", "b"] { echo i, "=", x, " "; }
print("");
foreach v in {"x": 1, "y": 2} { echo v, " "; }
print("");
foreach k, v in {"x": 1, "y": 2} { echo k, v, " "; }
print("");
foreach c in "ab" { echo c; }
print("");
CODE
, "10 20 \n0=a 1=b \n1 2 \nx1 y2 \nab\n"];

$T[] = ['closures capture by name (late binding)', <<<'CODE'
fns = [];
foreach x in [1, 2, 3] { fns.append(function() { return x; }); }
print(fns[0](), fns[1](), fns[2]());
add = function(a, b) { return a + b; };
print(add(2, 3));
CODE
, "3 3 3\n5\n"];

$T[] = ['reference semantics & copies', <<<'CODE'
a = [1, 2];
b = a;
b.append(3);
print(a, b);
c = a[:];
c.append(4);
print(a, c);
d = {"k": [1]};
d["k"].append(2);
print(d);
CODE
, "[1, 2, 3] [1, 2, 3]\n[1, 2, 3] [1, 2, 3, 4]\n{'k': [1, 2]}\n"];

$T[] = ['string methods', <<<'CODE'
s = "  Mixed Case  ";
print(s.strip().lower(), s.strip().upper());
print("a,b,,c".split(","));
print("hello".replace("l", "L"), "banana".count("an"), "banana".find("na"));
print("py".capitalize(), "PyHP".startswith("Py"), "PyHP".endswith("HP"), "abc".contains("b"));
print("abc".chars());
print(len("héllo"), "héllo"[1], "héllo"[::-1]);
CODE
, "mixed case MIXED CASE\n['a', 'b', '', 'c']\nheLLo 2 2\nPy true true true\n['a', 'b', 'c']\n5 é olléh\n"];

$T[] = ['operators: inc/dec, compound, ternary', <<<'CODE'
i = 5;
i++; i++; i--;
print(i);
i += 10; i -= 3; i *= 2; i **= 2;
print(i);
bits = 0b1100;
bits |= 0b0011; bits &= 0b1010; bits ^= 0b0110; bits <<= 2; bits >>= 1;
print(bits);
print(7 > 3 ? "big" : "small");
print(true ? false : true);
m = {};
m["count"] = 0;
m["count"] += 5;
print(m);
xs = [1, 2];
xs[0] += 10;
print(xs);
CODE
, "6\n676\n24\nbig\nfalse\n{'count': 5}\n[11, 2]\n"];

$T[] = ['nested data access', <<<'CODE'
data = {"users": [{"name": "ada", "tags": ["x", "y"]}, {"name": "bo", "tags": []}]};
print(data["users"][0]["tags"][1]);
print(data["users"][1]["name"]);
print(len(data["users"]));
data["users"][0]["tags"].append("z");
print(data["users"][0]["tags"]);
CODE
, "y\nbo\n2\n['x', 'y', 'z']\n"];

$T[] = ['functions as values', <<<'CODE'
function double(x) { return x * 2; }
print(map(double, [1, 2, 3]));
print(sorted(["b", "a", "c"]));
g = double;
print(g(21));
print(["apply:", map(lambda v: v + 1, [9, 19])]);
CODE
, "[2, 4, 6]\n['a', 'b', 'c']\n42\n['apply:', [10, 20]]\n"];

$T[] = ['echo & print basics', <<<'CODE'
echo "a", "b", 1, "\n";
print();
print("x");
echo;
CODE
, "ab1\n\nx\n"];

$T[] = ['membership & in operator', <<<'CODE'
print("a" in {"a": 1}, 1 in [1, 2], "ell" in "hello");
print(3 not in [1, 2], "z" not in {"a": 1});
CODE
, "true true true\ntrue true\n"];

$T[] = ['modules: relative require', <<<'CODE'
lib = require("./lib.pyh");
again = require("./lib.pyh");
print(lib.shout("modules work"), lib.double(21));
c = lib.Counter();
c.bump(4); c.bump(6);
print("count=" + str(c.count), "typed=" + str(c.limit * 2));
print("cached:", lib == again);
print("keys:", sorted(keys(lib)));
f = lib.shout;
print(f("as value"));
print(lib.isEven(10), lib.isEven(7));
CODE
, "MODULES WORK! 42\ncount=10 typed=20\ncached: true\nkeys: ['Counter', 'double', 'isEven', 'shout']\nAS VALUE!\ntrue false\n",
    ['files' => [
        'lib.pyh' => <<<'LIB'
// a tiny helper module: named functions, a lambda and a class
function shout(s) {
    return s.upper() + "!";
}

double = lambda n: n * 2;

function isEven(n) {
    return n % 2 == 0;
}

class Counter {
    int count = 0;
    int limit = 10;
    function bump(by) {
        this.count += by;
        return this.count;
    }
}
LIB
    ]]];

$T[] = ['modules: vendor package require', <<<'CODE'
strutil = require("strutil");
print(strutil.swapcase("AbC"), strutil.repeatChar("-", 5));
print(strutil.padLeft("7", 3), strutil.padLeft("42", 3));
w = strutil.Wizard("Ada");
w.cast("fire"); w.cast("ice");
print(w.spells, str(w));
print(type(w));
CODE
, "aBc -----\n  7  42\n['fire', 'ice'] Wizard(Ada)\nWizard\n",
    ['files' => [
        'vendor/strutil/pyhp.json' => '{"name":"strutil","version":"1.2.0","main":"src/strutil.pyh"}',
        'vendor/strutil/src/strutil.pyh' => <<<'PKG'
// strutil — fixture package for the test suite
function swapcase(s) {
    out = "";
    foreach c in s.chars() {
        if c == c.upper() {
            out = out + c.lower();
        } else {
            out = out + c.upper();
        }
    }
    return out;
}

function repeatChar(ch, n) {
    out = "";
    i = 0;
    while i < n {
        out += ch;
        i++;
    }
    return out;
}

function padLeft(s, width) {
    return repeatChar(" ", width - len(s)) + s;
}

class Wizard {
    string name = "";
    list spells = [];
    function __construct(name) {
        this.name = name;
    }
    function cast(spell) {
        this.spells.append(spell);
        return this;
    }
    function __str__() {
        return "Wizard(" + this.name + ")";
    }
}
PKG
    ]]];

$T[] = ['modules: require inside functions + nesting', <<<'CODE'
function viaHelper(s) {
    m = require("./sub/helper.pyh");
    return m.decorate(s);
}
print(viaHelper("deep"));
outer = require("./sub/helper.pyh");
inner = outer.loadInner();
print(inner.name(), outer.tag("x"));
CODE
, "<deep>\ninner-module [x]\n",
    ['files' => [
        'sub/helper.pyh' => <<<'H1'
function decorate(s) {
    return "<" + s + ">";
}

function loadInner() {
    return require("./inner.pyh");
}

function tag(s) {
    return "[" + s + "]";
}
H1
        ,
        'sub/inner.pyh' => <<<'H2'
function name() {
    return "inner-module";
}
H2
    ]]];

$T[] = ['json & crypto builtins', <<<'CODE'
d = json_parse('{"device":"th-1","ok":true,"r":[1,2.5,"x"],"nest":{"a":null}}');
print(d.device, d.ok, d.r[1], d.r[2], d.nest.a);
print(json_string({"b": 2, "list": [1, 2, 3]}));
print(md5("pyhp"));
print(sha256("pyhp"));
print(crc32("pyhp"));
print(base64e("hello"), "|", base64d("aGVsbG8="));
print(urlenc("a b&c=d"), "|", urldec("a%20b%26c%3Dd"));
try {
    json_parse("{broken");
} catch (ValueError e) {
    print("caught:", e.message);
}
CODE
, <<<"OUT"
th-1 true 2.5 x null
{"b":2,"list":[1,2,3]}
111149b979af7a51eb303a5faaee1a62
2ac2ce5eb073902398e8b5cd8b40a912cd7b6cc5742188ef55217263aee22c5a
ec7abccc
aGVsbG8= | hello
a%20b%26c%3Dd | a b&c=d
caught: json_parse(): invalid JSON — Syntax error

OUT
];

$T[] = ['stdlib: ai module (ML toolkit)', <<<'CODE'
ai = require("ai");
model = ai.linear_fit([0, 1, 2, 3, 4], [1, 3, 5, 7, 9], 0.05, 800);
print("w=", round(model.w, 4), " b=", round(model.b, 4));
print("predict(6)=", ai.linear_predict(model, 6));
print("r2=", ai.r2([2, 4, 6], [2, 4, 6]), " acc=", ai.accuracy([1, 0, 1], [1, 1, 1]));
print("dot=", ai.dot([1, 2, 3], [4, 5, 6]), " euclid=", ai.euclid([0, 0], [3, 4]));
km = ai.kmeans([[0, 0], [0, 1], [10, 10], [10, 11]], 2, 5);
print("labels=", km.labels);
split = ai.train_test_split(["a", "b", "c", "d", "e"], 0.4, 42);
print("train=", split.train, " test=", split.test);
print("minmax=", ai.minmax([10, 20, 30]), " sig=", round(ai.sigmoid(0), 5));
CODE
, <<<"OUT"
w= 2.0  b= 1.0
predict(6)= 13.000001754802
r2= 1.0  acc= 0.66666666666667
dot= 32.0  euclid= 5.0
labels= [0, 0, 1, 1]
train= ['a', 'd', 'b']  test= ['e', 'c']
minmax= [0, 0.5, 1]  sig= 0.5

OUT
];

$T[] = ['stdlib: sec module (security toolkit)', <<<'CODE'
sec = require("sec");
enc = sec.caesar("Attack at dawn", 3);
print(enc, " -> ", sec.crack_caesar(enc).plaintext);
print("rot13=", sec.rot13("Hello"), " xor=", sec.xor_cipher(sec.xor_cipher("data", "k"), "k"));
print("entropy=", sec.entropy("aabbccdd"));
s = sec.subnet("192.168.1.10/24");
print("net=", s.network, " mask=", s.mask, " hosts=", s.hosts);
print("ip=", sec.ip_to_int("10.0.0.1"), " back=", sec.int_to_ip(sec.ip_to_int("10.0.0.1")));
print("pwd=", sec.password_gen(10, 7));
print("log2(8)=", sec.log2(8));
print(sec.hexdump("AB"));
CODE
, <<<"OUT"
Dwwdfn dw gdzq  ->  Attack at dawn
rot13= Uryyb  xor= data
entropy= 2.0
net= 192.168.1.0  mask= 255.255.255.0  hosts= 254
ip= 167772161  back= 10.0.0.1
pwd= ARW9Ol!BKr
log2(8)= 3.0
00000000  41 42  |AB|


OUT
];

$T[] = ['stdlib: iot module (telemetry toolkit)', <<<'CODE'
iot = require("iot");
reads = iot.sensor_sim(99, 5, 20.0, 2.0);
print("reads=", reads);
st = iot.rolling_stats(reads);
print("min=", st.min, " count=", st.count);
print("crc16=", iot.crc16("123456789"));
wire = iot.mqtt_publish("dev-1", [21.5, 21.7], 1000);
msg = iot.mqtt_consume(wire);
print("topic=", msg.topic);
doc = json_parse(msg.payload);
print("device=", doc.device, " readings=", doc.readings);
batch = iot.telemetry_batch("d", [1, 2, 3, 4], 10, 2);
lines = batch.split("\n");
print("lines=", len(lines) - 1, " first=", iot.parse_telemetry(lines[0]).device);
print("clamp=", iot.clamp(15, 0, 10), " flags=", iot.threshold_flags([5, 95], 0, 50));
CODE
, <<<"OUT"
reads= [19.1026, 20.258, 18.1902, 19.0048, 18.282]
min= 18.1902  count= 5
crc16= 19255
topic= dev-1/telemetry
device= dev-1  readings= [21.5, 21.7]
lines= 2  first= d
clamp= 10  flags= [false, true]

OUT
];

/* ================================================================ */
/* runners                                                          */
/* ================================================================ */

function run_interpreter(string $code, array $args, array $files = []): string {
    $oldCwd = getcwd();
    $dir = null;
    if (count($files) > 0) {
        $dir = pyhp_test_fixture_dir($files);
        chdir($dir);
    }
    ob_start();
    try {
        pyhp_set_argv($args);
        $stmts = PyHPParser::parseSource($code);
        $interp = new PyHPInterpreter();
        if ($dir !== null) { $interp->setMainFile($dir . '/main.pyh'); }
        $interp->runProgram($stmts);
    } catch (PyHPExit $e) {
        // exit(code) — output already flushed
    } catch (Throwable $e) {
        ob_end_clean();
        if ($dir !== null) { chdir($oldCwd); pyhp_test_cleanup($dir); }
        return "<<exception>> " . get_class($e) . ": " . $e->getMessage();
    }
    $out = ob_get_clean();
    if ($dir !== null) { chdir($oldCwd); pyhp_test_cleanup($dir); }
    return $out;
}

function run_compiled(string $code, array $args, string $phpBin, array $files = []): string {
    $oldCwd = getcwd();
    $dir = null;
    if (count($files) > 0) {
        $dir = pyhp_test_fixture_dir($files);
        chdir($dir);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pyhp_t_') . '.php';
    try {
        $stmts = PyHPParser::parseSource($code);
        $t = new PyHPTranspiler();
        if ($dir !== null) { $t->setSourceFile($dir . '/main.pyh'); }
        $php = $t->transpile($stmts, $tmp);
        file_put_contents($tmp, $php);
    } catch (Throwable $e) {
        @unlink($tmp);
        if ($dir !== null) { chdir($oldCwd); pyhp_test_cleanup($dir); }
        return "<<exception>> " . get_class($e) . ": " . $e->getMessage();
    }
    $cmdParts = [escapeshellarg($phpBin), escapeshellarg($tmp)];
    foreach ($args as $a) { $cmdParts[] = escapeshellarg($a); }
    $out = shell_exec(implode(' ', $cmdParts) . ' 2>&1');
    @unlink($tmp);
    if ($dir !== null) { chdir($oldCwd); pyhp_test_cleanup($dir); }
    return $out ?? '';
}

/** Materialize module fixtures into a fresh temp dir (with main.pyh absent —
 *  the test code itself is main). */
function pyhp_test_fixture_dir(array $files): string {
    $base = tempnam(sys_get_temp_dir(), 'pyhp_fx_');
    unlink($base);
    mkdir($base, 0777, true);
    foreach ($files as $rel => $content) {
        $p = $base . '/' . $rel;
        $d = dirname($p);
        if (!is_dir($d)) { mkdir($d, 0777, true); }
        file_put_contents($p, $content);
    }
    return $base;
}

function pyhp_test_cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/* ================================================================ */
/* main                                                             */
/* ================================================================ */

$phpBin = PHP_BINARY;
$passed = 0; $failed = 0;
$results = [];

foreach ($T as $case) {
    [$name, $code, $expected] = [$case[0], $case[1], $case[2]];
    $opts = $case[3] ?? [];
    if (isset($opts['files']) || isset($opts['args'])) {
        $args = $opts['args'] ?? [];
        $files = $opts['files'] ?? [];
    } else {
        $args = $opts;
        $files = [];
    }
    $gotI = run_interpreter($code, $args, $files);
    $gotC = run_compiled($code, $args, $phpBin, $files);
    $okI = ($gotI === $expected);
    $okC = ($gotC === $expected);
    if ($okI && $okC) {
        $passed++;
        $results[] = sprintf("  %-46s %s", $name, "PASS (interp + compiled)");
    } else {
        $failed++;
        $results[] = sprintf("  %-46s FAIL", $name);
        if (!$okI) {
            $results[] = "    interpreter expected: " . json_encode($expected);
            $results[] = "    interpreter got:      " . json_encode($gotI);
        }
        if (!$okC) {
            $results[] = "    compiled expected:    " . json_encode($expected);
            $results[] = "    compiled got:         " . json_encode($gotC);
        }
    }
}

/* ---------------- native backend tests (need g++, skipped otherwise) ---------------- */
$hasGxx = trim((string)shell_exec('command -v g++ 2>/dev/null')) !== '';
if ($hasGxx) {
    $nativeCases = [
        ['native backend: core language', <<<'CODE'
function fib(n) {
    if n < 2 { return n; }
    return fib(n - 1) + fib(n - 2);
}
xs = [3, 1, 2];
xs.sort();
d = {"k": [1, 2, 3]};
print(fib(10), xs, d.k[2], len("abc"));
print([x * x for x in xs if x > 1], xs[1:], "a" + 1 + true);
try {
    throw ValueError("nope");
} catch (ValueError e) {
    print("caught", e.message);
}
CODE
, "55 [1, 2, 3] 3 3\n[4, 9] [2, 3] a1true\ncaught nope\n"],
        ['native backend: cpp blocks', <<<'CODE'
cpp {
    // pyhp-export: int64_t pyh_add3(int64_t a, int64_t b, int64_t c);
    int64_t pyh_add3(int64_t a, int64_t b, int64_t c) {
        return a + b * c;
    }
}
print("sum=", pyh_add3(2, 3, 4));
CODE
, "sum= 14\n"],
    ];
    foreach ($nativeCases as [$name, $code, $expected]) {
        $dir = pyhp_test_fixture_dir(['main.pyh' => $code]);
        $oldCwd = getcwd();
        chdir($dir);
        $bin = $dir . '/app';
        $build = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg(__DIR__ . '/../bin/pyhp')
            . ' build main.pyh --target native -o ' . escapeshellarg($bin) . ' 2>&1');
        $gotN = is_file($bin) ? (string)shell_exec(escapeshellarg($bin) . ' 2>&1') : "<<nobin>> " . $build;
        chdir($oldCwd);
        pyhp_test_cleanup($dir);
        $okN = ($gotN === $expected);
        if ($okN) {
            $passed++;
            $results[] = sprintf("  %-46s %s", $name, "PASS (native)");
        } else {
            $failed++;
            $results[] = sprintf("  %-46s FAIL (native)", $name);
            $results[] = "    native expected: " . json_encode($expected);
            $results[] = "    native got:      " . json_encode($gotN);
        }
    }
} else {
    $results[] = sprintf("  %-46s %s", "native backend tests", "SKIP (no g++ found)");
}

echo "PyHP test suite — {$passed} passed, {$failed} failed\n";
echo str_repeat("-", 72), "\n";
foreach ($results as $r) { echo $r, "\n"; }
echo str_repeat("-", 72), "\n";
exit($failed === 0 ? 0 : 1);
