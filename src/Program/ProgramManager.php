<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Program;

use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Exceptions\ConflictingProgramRecord;
use PandaBear\Mlm\Exceptions\InvalidExternalIdentity;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;

/**
 * Creates programs, the members who join them and the plans they own — the
 * writes the models already allow, with their uniqueness refused as a
 * domain answer rather than a database error.
 *
 * Nothing new is decided here: a program's code is unique, a member's code
 * and external identity are unique within its program, a plan's code is
 * unique within its program, and a member joins through its program, never
 * by naming one. Each write is one insert; the database's unique indexes
 * decide a race.
 */
final readonly class ProgramManager
{
    private const CODE_LENGTH = 64;

    private const NAME_LENGTH = 255;

    /**
     * @throws ConflictingProgramRecord
     */
    public function create(string $code, string $name): Program
    {
        $code = self::required('program code', $code, self::CODE_LENGTH);
        $name = self::required('program name', $name, self::NAME_LENGTH);

        try {
            return Program::query()->create(['code' => $code, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw ConflictingProgramRecord::programCode($code);
        }
    }

    /**
     * The member joins `$program`. An external identity is both halves or
     * neither.
     *
     * @throws ConflictingProgramRecord
     * @throws InvalidExternalIdentity
     */
    public function join(Program $program, string $memberCode, DateTimeInterface $joinedAt, ?string $externalType = null, ?string $externalId = null): Member
    {
        $memberCode = self::required('member code', $memberCode, self::CODE_LENGTH);
        $externalType = $externalType === '' ? null : $externalType;
        $externalId = $externalId === '' ? null : $externalId;

        try {
            return $program->members()->create([
                'member_code' => $memberCode,
                'external_type' => $externalType,
                'external_id' => $externalId,
                'joined_at' => $joinedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            $identityTaken = $externalType !== null && $externalId !== null && $program->members()
                ->where('external_type', $externalType)
                ->where('external_id', $externalId)
                ->exists();

            throw $identityTaken
                ? ConflictingProgramRecord::externalIdentity((string) $program->getKey(), $externalType, $externalId)
                : ConflictingProgramRecord::memberCode((string) $program->getKey(), $memberCode);
        }
    }

    /**
     * A plan of `$program`, with no version yet: versions are drafted and
     * moved through `PlanVersionLifecycle`.
     *
     * @throws ConflictingProgramRecord
     */
    public function addPlan(Program $program, string $code, string $name): Plan
    {
        $code = self::required('plan code', $code, self::CODE_LENGTH);
        $name = self::required('plan name', $name, self::NAME_LENGTH);

        try {
            return $program->plans()->create(['code' => $code, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw ConflictingProgramRecord::planCode((string) $program->getKey(), $code);
        }
    }

    private static function required(string $what, string $value, int $maxLength): string
    {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw ConflictingProgramRecord::blank($what, $maxLength);
        }

        return $value;
    }
}
