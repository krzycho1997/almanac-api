<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Controller\HealthController;
use App\Shared\Health\HealthCheck;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testReportsEveryCheckByItsNameInNameOrder(): void
    {
        // Given
        $controller = new HealthController([self::check('queue', ['depth' => 'low']), self::check('circuits', ['apod' => 'open'])]);

        // When
        $body = (string) $controller()->getContent();

        // Then
        self::assertSame('{"status":"ok","checks":{"circuits":{"apod":"open"},"queue":{"depth":"low"}}}', $body);
    }

    public function testReportsAnEmptyObjectWithoutChecks(): void
    {
        // When
        $body = (string) new HealthController([])()->getContent();

        // Then
        self::assertSame('{"status":"ok","checks":{}}', $body);
    }

    public function testLetsAFailingCheckFailTheRequest(): void
    {
        // Given
        $controller = new HealthController([self::failingCheck()]);

        // Then / When
        $this->expectException(\RuntimeException::class);
        $controller();
    }

    /**
     * @param array<string, string> $status
     */
    private static function check(string $name, array $status): HealthCheck
    {
        return new readonly class($name, $status) implements HealthCheck {
            /**
             * @param array<string, string> $status
             */
            public function __construct(private string $name, private array $status)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function report(): array
            {
                return $this->status;
            }
        };
    }

    private static function failingCheck(): HealthCheck
    {
        return new readonly class implements HealthCheck {
            public function name(): string
            {
                return 'failing';
            }

            public function report(): array
            {
                throw new \RuntimeException('The check failed.');
            }
        };
    }
}
