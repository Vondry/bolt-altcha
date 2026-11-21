<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Bolt\Entity\User;
use Bolt\Repository\UserRepository;

/**
 * Bolt's UserRepository without Doctrine: one editor, `admin` / PASSWORD (hashed as plaintext
 * in the test kernel).
 */
final class TestUserRepository extends UserRepository
{
    public const USERNAME = 'admin';
    public const PASSWORD = 'correct horse battery staple';

    /** Counts the lookups: a request ALTCHA rejects must not reach the database. */
    public int $lookups = 0;

    /**
     * @phpstan-ignore constructor.missingParentCall (a stand-in: the parent needs Doctrine)
     */
    public function __construct()
    {
    }

    public function findOneByCredentials(string $username): ?User
    {
        ++$this->lookups;

        return $username === self::USERNAME ? self::user() : null;
    }

    public static function user(): User
    {
        $user = new User();
        $user->setUsername(self::USERNAME);
        $user->setPassword(self::PASSWORD);
        $user->setRoles(['ROLE_ADMIN']);

        return $user;
    }
}
