<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Blame;

use Nowo\LegalKitBundle\Blame\SecurityLegalActorIdResolver;
use Nowo\LegalKitBundle\Tests\Support\Blame\TestAdminUser;
use Nowo\LegalKitBundle\Tests\Support\Blame\TestCustomerUser;
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
        self::assertTrue($resolver->hasCurrentActor());
        self::assertFalse((new SecurityLegalActorIdResolver())->hasCurrentActor());
        self::assertFalse((new SecurityLegalActorIdResolver(new TokenStorage()))->hasCurrentActor());
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

    public function testZeroNegativeAndBlankIdsAreRefused(): void
    {
        $resolver = new SecurityLegalActorIdResolver();
        foreach ([0, -5, '0', '-2', ' '] as $id) {
            self::assertNull($resolver->actorIdFor(new TestCustomerUser($id)), 'id ' . $id . ' never falls back to the identifier');
        }
    }

    public function testAcceptedUserClassesFailClosedForOtherIdentityDomains(): void
    {
        $resolver = new SecurityLegalActorIdResolver(null, [TestCustomerUser::class]);

        self::assertSame('5', $resolver->actorIdFor(new TestCustomerUser(5)));
        self::assertNull($resolver->actorIdFor(new TestAdminUser(5)), 'Admin#5 is not recorded as Customer#5');
        self::assertSame('5', (new SecurityLegalActorIdResolver())->actorIdFor(new TestAdminUser(5)), 'Empty list: any actor (one identity domain)');
    }
}
