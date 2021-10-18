<?php

function bar(int $arg): int
{
    return $arg ** 2;
}

function foo(mixed $value): mixed
{
    return is_int($value) ? bar($value) : $value;
}

foo(value: 42);

$a = 23;

foo(value: $a);

$a = 'foobar';

array_key_exists($a, [1, 2, 3]);

$a = 4;

function quatz(int ...$is): void { }

quatz(1, 2, 3, $a);

function abc(mixed $b): void
{
    if (is_int($b)) {
        foo($b);
    }
}

abc($a);

$pi = 3.14;

if ($pi > 3) { }

for ($i = 0; $i < 2; $i++) { }
