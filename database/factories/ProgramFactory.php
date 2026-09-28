<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Program;

/**
 * @extends Factory<Program>
 */
final class ProgramFactory extends Factory
{
    protected $model = Program::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'PRG-'.Str::upper(Str::random(10));

        return [
            'code' => $code,
            'name' => "Program {$code}",
        ];
    }
}
