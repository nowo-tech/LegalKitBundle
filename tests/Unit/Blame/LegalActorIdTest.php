<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Blame;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class LegalActorIdTest extends TestCase
{
    public function testKeepsTheId(): void
    {
        self::assertSame('acc-1', (new LegalActorId('acc-1'))->id);
        self::assertSame(str_repeat('a', 191), (new LegalActorId(str_repeat('a', 191)))->id);
    }

    #[DataProvider('invalid')]
    public function testRefusesEmptyOrTooLong(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LegalActorId($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'too long' => [str_repeat('a', 192)];
    }

    public function testRawEntityManager(): void
    {
        self::assertSame('legal', NowoLegalKitExtension::rawEntityManager(['doctrine' => ['entity_manager' => 'legal']]));
        self::assertNull(NowoLegalKitExtension::rawEntityManager(['doctrine' => ['entity_manager' => '']]));
        self::assertNull(NowoLegalKitExtension::rawEntityManager(['doctrine' => 'x']));
        self::assertNull(NowoLegalKitExtension::rawEntityManager([]));
    }
}
