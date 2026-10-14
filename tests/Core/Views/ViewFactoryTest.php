<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Views\Contracts\View as ViewContract;
use Arix\Views\View;
use Arix\Views\ViewFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ViewFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_named_views_with_data(): void
    {
        $view = (new ViewFactory())->make('layouts.main', ['title' => 'Hello']);

        $this->assertInstanceOf(ViewContract::class, $view);
        $this->assertInstanceOf(View::class, $view);
        $this->assertSame('layouts.main', $view->name());
        $this->assertSame(['title' => 'Hello'], $view->data());
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_view_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ViewFactory())->make($name);
    }

    public static function invalidNames(): array
    {
        return [
            [''],
            ['../secret'],
            ['layouts/main'],
            ['a..b'],
        ];
    }

    #[Test]
    #[DataProvider('invalidData')]
    public function it_rejects_reserved_template_data(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ViewFactory())->make('index', $data);
    }

    public static function invalidData(): array
    {
        return [
            [['this' => 'x']],
            [['GLOBALS' => 'x']],
            [['__arixPath' => 'x']],
            [[0 => 'x']],
            [['9lives' => 'x']],
        ];
    }
}
