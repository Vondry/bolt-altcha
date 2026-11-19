<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use Symfony\Component\Clock\MockClock;
use Tomvondracek\Altcha\Challenge\ChallengeFactory;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Challenge\ReplayStore;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Verification\Verifier;

final readonly class AltchaServices
{
    public function __construct(
        public AltchaConfigLoader $loader,
        public HmacKeyProvider $keys,
        public ChallengeFactory $factory,
        public ReplayStore $replayStore,
        public Verifier $verifier,
        public MockClock $clock,
        public Protection $protection,
    ) {
    }
}
