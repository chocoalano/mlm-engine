<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Matrix;

use PandaBear\Mlm\Exceptions\InvalidMatrixNetwork;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\Program;

/**
 * Configures a program's matrix network (ADR-027) — the only way one is
 * written.
 *
 * A program has one matrix network, and its width — how many numbered slots
 * every matrix parent has — is structure: set once, never changed. There is
 * no reconfiguration, and nothing else is stored.
 */
final readonly class MatrixNetworkManager
{
    public const MAX_WIDTH = 100;

    /**
     * The program's matrix network, created with `$width` if it has none.
     * Configuring it again with the same width returns it; with another
     * width it is refused.
     *
     * The width is a PHP integer from 1 to 100 — never "3", 3.0, true or
     * null: nothing is cast.
     *
     * @param  int  $width
     *
     * @throws InvalidMatrixNetwork
     */
    public function configure(Program $program, mixed $width): MatrixNetwork
    {
        if (! is_int($width) || $width < 1 || $width > self::MAX_WIDTH) {
            throw InvalidMatrixNetwork::width($width);
        }

        $connection = $program->getConnection();

        // Under the program's lock — the lock every genealogy write takes —
        // so two first configurations run one at a time: the second sees
        // the first's network, and a matrix write never sees a half-made one.
        return $connection->transaction(static function () use ($connection, $program, $width): MatrixNetwork {
            $stored = Program::on($connection->getName())->whereKey($program->getKey())->lockForUpdate()->first()
                ?? throw InvalidMatrixNetwork::missingProgram((string) $program->getKey());

            $existing = MatrixNetwork::on($connection->getName())->where('program_id', $stored->getKey())->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->width !== $width) {
                    throw InvalidMatrixNetwork::widthConflict((string) $stored->getKey(), $existing->width, $width);
                }

                return $existing;
            }

            $network = new MatrixNetwork;
            $id = $network->newUniqueId();
            $now = $network->freshTimestamp();

            $connection->table('mlm_matrix_networks')->insert([
                'id' => $id,
                'program_id' => $stored->getKey(),
                'width' => $width,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return MatrixNetwork::on($connection->getName())->findOrFail($id);
        });
    }
}
