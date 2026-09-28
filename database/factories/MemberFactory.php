<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Member;

/**
 * No external identity by default: most tests are about the member itself,
 * and one that needs an identity passes it.
 *
 * @extends Factory<Member>
 */
final class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => ProgramFactory::new(),
            'member_code' => 'MBR-'.Str::upper(Str::random(10)),
            'external_type' => null,
            'external_id' => null,
            'joined_at' => CarbonImmutable::now(),
        ];
    }
}
