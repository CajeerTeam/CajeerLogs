<?php
declare(strict_types=1);

namespace Cajeer\Logs\Tests\Kernel;

use Cajeer\Logs\Kernel\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testVersion(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        self::assertSame('0.1.0-initial', $app->version());
    }
}
