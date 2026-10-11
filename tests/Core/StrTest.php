<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StrTest extends TestCase
{
    #[Test]
    public function it_handles_multibyte_length_case_and_search(): void
    {
        $this->assertTrue(Str::length('Hola') === 4);
        $this->assertTrue(Str::length('Ñandú') === 5);
        $this->assertTrue(Str::length('') === 0);
        $this->assertTrue(Str::upper('Ñandú') === 'ÑANDÚ');
        $this->assertTrue(Str::lower('ÑANDÚ') === 'ñandú');
        $this->assertTrue(Str::contains('Hola Mundo', 'Mundo') === true);
        $this->assertTrue(Str::contains('Hola Mundo', ['xyz', 'Mundo']) === true);
        $this->assertTrue(Str::contains('Hola Mundo', '') === false);
        $this->assertTrue(Str::startsWith('Hola Mundo', 'Hola') === true);
        $this->assertTrue(Str::startsWith('Hola Mundo', ['Adios', 'Hola']) === true);
        $this->assertTrue(Str::endsWith('Hola Mundo', 'Mundo') === true);
        $this->assertTrue(Str::endsWith('Hola Mundo', '') === false);
    }

    #[Test]
    public function it_extracts_truncates_and_slugs(): void
    {
        $this->assertTrue(Str::before('hola:mundo:fin', ':') === 'hola');
        $this->assertTrue(Str::before('hola', ':') === 'hola');
        $this->assertTrue(Str::after('hola:mundo:fin', ':') === 'mundo:fin');
        $this->assertTrue(Str::after('hola', ':') === 'hola');
        $this->assertTrue(Str::beforeLast('hola:mundo:fin', ':') === 'hola:mundo');
        $this->assertTrue(Str::afterLast('hola:mundo:fin', ':') === 'fin');
        $this->assertTrue(Str::between('[hola]', '[', ']') === 'hola');
        $this->assertTrue(Str::between('hola', '[', ']') === '');
        $this->assertTrue(Str::limit('Hola Mundo', 4) === 'Hola...');
        $this->assertTrue(Str::limit('Hi', 10) === 'Hi');
        $this->assertTrue(Str::limit('Ñandú vive', 5) === 'Ñandú...');
        $this->assertTrue(Str::words('hola mundo cruel', 2) === 'hola mundo...');
        $this->assertTrue(Str::slug('Hola Mundo Ñandú!') === 'hola-mundo-nandu');
        $this->assertTrue(Str::slug('Hola_Mundo', '_') === 'hola_mundo');
    }

    #[Test]
    public function it_converts_case_matches_patterns_and_generates_tokens(): void
    {
        $this->assertTrue(Str::camel('foo_bar-baz qux') === 'fooBarBazQux');
        $this->assertTrue(Str::snake('fooBarBaz') === 'foo_bar_baz');
        $this->assertTrue(Str::kebab('fooBarBaz') === 'foo-bar-baz');
        $this->assertTrue(Str::studly('foo-bar_baz qux') === 'FooBarBazQux');
        $this->assertTrue(Str::headline('fooBar_baz-qux') === 'Foo Bar Baz Qux');
        $this->assertTrue(Str::replace('mundo', 'Arix', 'hola mundo') === 'hola Arix');
        $this->assertTrue(Str::repeat('ab', 3) === 'ababab');
        $this->assertTrue(Str::is('foo*', 'foobar') === true);
        $this->assertTrue(Str::is('foo', 'foobar') === false);
        $token = Str::random(16);
        $this->assertTrue(strlen($token) === 16 && preg_match('/^[A-Za-z0-9]{16}$/', $token) === 1);
        $uuid = Str::uuid();
        $this->assertTrue(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1);
    }

    #[Test]
    public function it_rejects_random_length_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Str::random(0);
    }
}
