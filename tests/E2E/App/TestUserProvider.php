<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Bolt\Entity\User;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<User>
 */
final class TestUserProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, User::class, true);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ($identifier !== TestUserRepository::USERNAME) {
            throw new UserNotFoundException();
        }

        return TestUserRepository::user();
    }
}
