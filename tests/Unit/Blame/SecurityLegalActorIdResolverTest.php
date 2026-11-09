<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Blame;

use Nowo\LegalKitBundle\Blame\SecurityLegalActorIdResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

/**
 * Default actor id resolver: getId() first, the user identifier otherwise, never longer than the column.
 */
final class SecurityLegalActorIdResolverTest extends TestCase
{
    public function testCurrentActor(): void
    {
        self::assertNull((new SecurityLegalActorIdResolver())->currentActorId(), 'No SecurityBundle');
        $storage  = new TokenStorage();
        $resolver = new SecurityLegalActorIdResolver($storage);
        self::assertNull($resolver->currentActorId(), 'No token');
        $user = new InMemoryUser('ann', null);
        $storage->setToken(new UsernamePasswordToken($user, 'main'));
        self::assertSame('ann', $resolver->currentActorId());
    }

    public function testActorIds(): void
    {
        $resolver = new SecurityLegalActorIdResolver();
        self::assertSame('42', $resolver->actorIdFor(new class {
            public function getId(): int
            {
                return 42;
            }
        }));
        self::assertSame('0195f2c0-uuid', $resolver->actorIdFor(new class {
            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return '0195f2c0-uuid';
                    }
                };
            }
        }));
        self::assertSame('acc-7', $resolver->actorIdFor(new class {
            public function getId(): string
            {
                return 'acc-7';
            }
        }));
        self::assertNull($resolver->actorIdFor(new class {
            public ?int $id = null;

            public function getId(): ?int
            {
                return $this->id;
            }
        }), 'Unsaved entity that is no security user');
        self::assertNull($resolver->actorIdFor(new class {
            /** @return list<int> */
            public function getId(): array
            {
                return [1];
            }
        }));
        self::assertNull($resolver->actorIdFor(new stdClass()));
        self::assertSame('bob', $resolver->actorIdFor(new InMemoryUser('bob', null)));
        self::assertNull($resolver->actorIdFor(new InMemoryUser(str_repeat('x', 192), null)), 'Longer than the column');
    }
}
