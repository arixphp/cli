<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ArrTest extends TestCase
{
    #[Test]
    public function it_resolves_dot_paths_preserves_null_and_detects_existing_keys(): void
    {
        $values = ['user' => ['name' => 'Ada'], 'items' => ['x'], 'a' => null];
        $this->assertTrue(Arr::get($values, 'user.name') === 'Ada');
        $this->assertTrue(Arr::get($values, 'a', 'fallback') === null);
        $this->assertTrue(Arr::get($values, 'items.0') === 'x');
        $this->assertTrue(Arr::get($values, 'missing', 'fallback') === 'fallback');
        $this->assertTrue(Arr::get($values, 'user.name.deep', 'fallback') === 'fallback');
        $this->assertTrue(Arr::has($values, 'a') === true);
        $this->assertTrue(Arr::has($values, 'user.name') === true);
        $this->assertTrue(Arr::has($values, 'user.missing') === false);
    }

    #[Test]
    public function it_writes_dot_paths_creating_intermediate_arrays(): void
    {
        $array = [];
        Arr::set($array, 'a.b.c', 1);
        $this->assertTrue($array === ['a' => ['b' => ['c' => 1]]], var_export($array, true));

        $array = ['a' => 'scalar'];
        Arr::set($array, 'a.b', 1);
        $this->assertTrue($array === ['a' => ['b' => 1]], var_export($array, true));

        $array = ['items' => ['x']];
        Arr::set($array, 'items.1', 'y');
        $this->assertTrue($array === ['items' => ['x', 'y']], var_export($array, true));

        $array = ['a' => ['b' => 1], 'c' => 2];
        Arr::forget($array, 'a.b');
        $this->assertTrue($array === ['a' => [], 'c' => 2], var_export($array, true));
        Arr::forget($array, 'missing.path');
        $this->assertTrue($array === ['a' => [], 'c' => 2], var_export($array, true));

        $this->assertTrue(Arr::add(['a' => 1], 'a', 2) === ['a' => 1]);
        $this->assertTrue(Arr::add([], 'a', 2) === ['a' => 2]);
    }

    #[Test]
    public function it_filters_keys_and_flattens_arrays_with_dot(): void
    {
        $values = ['a' => ['b' => 1, 'keep' => 2], 'c' => 3];
        $this->assertTrue(Arr::except($values, ['a.b']) === ['a' => ['keep' => 2], 'c' => 3]);
        $this->assertTrue(Arr::except($values, 'a.b') === ['a' => ['keep' => 2], 'c' => 3]);
        $this->assertTrue(Arr::only($values, ['a', 'c']) === ['a' => ['b' => 1, 'keep' => 2], 'c' => 3]);
        $this->assertTrue(Arr::only($values, 'missing') === []);

        $flattened = Arr::dot(['p' => ['price' => 100], 'list' => ['x'], 'null' => null]);
        $this->assertTrue($flattened === ['p.price' => 100, 'list.0' => 'x', 'null' => null], var_export($flattened, true));
    }

    #[Test]
    public function it_searches_arrays_with_callbacks_and_dot_paths(): void
    {
        $rows = [
            ['user' => ['name' => 'ada', 'role' => 'admin']],
            ['user' => ['name' => 'bob', 'role' => 'viewer']],
        ];
        $this->assertTrue(Arr::pluck($rows, 'user.name') === ['ada', 'bob']);
        $this->assertTrue(Arr::pluck($rows, 'user.name', 'user.role') === ['admin' => 'ada', 'viewer' => 'bob']);

        $this->assertTrue(Arr::first([2, 4, 5], static fn (int $value): bool => $value % 2 === 1) === 5);
        $this->assertTrue(Arr::first([2, 4], static fn (int $value): bool => $value % 2 === 1, 'none') === 'none');
        $this->assertTrue(Arr::first([2, 4]) === 2);
        $this->assertTrue(Arr::last([1, 2, 3]) === 3);
        $this->assertTrue(Arr::last([], null, 'empty') === 'empty');

        $filtered = Arr::where(['a' => 1, 'b' => 2], static fn (int $value): bool => $value > 1);
        $this->assertTrue($filtered === ['b' => 2], var_export($filtered, true));
        $renumbered = Arr::where(['a' => 1, 'b' => 2], static fn (int $value): bool => $value > 1, false);
        $this->assertTrue($renumbered === [0 => 2], var_export($renumbered, true));

        $this->assertTrue(Arr::firstWhere($rows, 'user.role', 'viewer') === $rows[1]);
        $this->assertTrue(Arr::firstWhere($rows, 'user.role', 'missing') === null);
    }
}
