<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeZone;
use PandaBear\Mlm\Support\EffectiveMoment;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * One rule for every business moment the package stores or compares — a
 * volume entry's, a genealogy path's: the same instant, in the application's
 * timezone, to the second.
 */
final class EffectiveMomentTest extends TestCase
{
    public function test_a_moment_is_the_same_instant_in_the_application_timezone_to_the_second(): void
    {
        $given = new DateTime('2026-04-01 17:00:00.999999', new DateTimeZone('Asia/Jakarta'));

        $moment = EffectiveMoment::of($given);

        $this->assertInstanceOf(CarbonImmutable::class, $moment);
        $this->assertSame('2026-04-01 10:00:00.000000 UTC', $moment->format('Y-m-d H:i:s.u e'));

        // The caller's value is left as it was.
        $this->assertSame('2026-04-01 17:00:00.999999 Asia/Jakarta', $given->format('Y-m-d H:i:s.u e'));
    }

    public function test_volume_moments_follow_the_same_rule(): void
    {
        $at = CarbonImmutable::parse('2026-04-01 06:00:00.500000', 'America/New_York');

        $this->assertSame(
            EffectiveMoment::of($at)->format('Y-m-d H:i:s.u e'),
            VolumeInput::moment($at)->format('Y-m-d H:i:s.u e'),
        );
        $this->assertSame('2026-04-01 10:00:00.000000 UTC', VolumeInput::moment($at)->format('Y-m-d H:i:s.u e'));
    }
}
