<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static guarantees of the runtime package (docs/production-readiness.md),
 * read from the source itself so a regression fails before it runs: the
 * domain never depends on the panel, writes stay in their writers, nothing
 * is executed from data, amounts never become floats, and nothing needs a
 * PHP newer than the declared 8.2.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * The services that own each write. A new file writing through the query
     * builder is a new writer, and has to be added here deliberately.
     */
    private const WRITERS = [
        'Binary/BinaryPlacementManager.php',
        'Binary/Pairing/BinaryPairingStateTransition.php',
        'Calculation/CalculationEngine.php',
        'Commission/CommissionAdjustmentEngine.php',
        'Commission/CommissionStatusWriter.php',
        'Commission/HybridCalculationEngine.php',
        'Finance/LedgerAccountManager.php',
        'Finance/LedgerRecorder.php',
        'Finance/WalletManager.php',
        'Genealogy/ClosureTree.php',
        'Genealogy/PlacementGenealogy.php',
        'Genealogy/SponsorGenealogy.php',
        'Matrix/MatrixNetworkManager.php',
        'Matrix/MatrixPlacementManager.php',
        'Payout/PayoutBatchManager.php',
        'Payout/PayoutManager.php',
        'Period/CommissionPeriodCalculator.php',
        'Period/CommissionPeriodFinalizer.php',
        'Period/CommissionPeriodManager.php',
        'Period/CommissionPeriodReleaser.php',
        'Planning/PlanDefinitionCloner.php',
        'Planning/PlanDefinitionEditor.php',
        'Planning/PlanVersionLifecycle.php',
        'Volume/VolumeRecorder.php',
    ];

    public function test_every_runtime_file_is_strictly_typed(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertStringContainsString('declare(strict_types=1);', $source, "{$path} is not strictly typed.");
        }
    }

    public function test_the_domain_never_depends_on_panda_panel(): void
    {
        foreach ($this->sources() as $path => $source) {
            if (str_starts_with($path, 'Panel/') || $path === 'PandaMlmPlugin.php') {
                continue;
            }

            $this->assertStringNotContainsString('PandaPanel\\', $source, "{$path} depends on Panda Panel.");
        }
    }

    public function test_query_builder_writes_happen_only_in_their_writers(): void
    {
        $writers = [];

        foreach ($this->sources() as $path => $source) {
            if (preg_match('/->(insert|insertGetId|insertOrIgnore|upsert|updateOrInsert|increment|decrement|update|delete|truncate)\(/', $this->code($source)) === 1) {
                $writers[] = $path;
            }
        }

        sort($writers);

        $this->assertSame(self::WRITERS, $writers, 'A file outside the dedicated writers writes through the query builder.');
    }

    public function test_nothing_is_executed_or_instantiated_from_data(): void
    {
        foreach ($this->sources() as $path => $source) {
            $code = $this->code($source);

            foreach ([
                '/\beval\s*\(/' => 'eval',
                '/(?<!->)(?<!::)(?<!function )\b(create_function|call_user_func|call_user_func_array|unserialize|exec|shell_exec|system|passthru|proc_open|popen)\s*\(/' => 'dynamic execution',
                '/(?<!->)(?<!::)\b(app|resolve)\(\s*\$|->make\(\s*\$/' => 'container resolution of a variable',
            ] as $pattern => $what) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$path}: {$what}.");
            }

            // Raw SQL is a constant, or — in the two network readers — built
            // from grammar-quoted column names alone, every value bound.
            if (! in_array($path, ['Binary/BinaryLegVolumeTotals.php', 'Metrics/NetworkVolumeTotals.php'], true)) {
                $this->assertDoesNotMatchRegularExpression('/\bDB::raw\(\s*\$|Raw\(\s*\$|Raw\(\s*\'[^\']*\'\s*\./', $code, "{$path}: raw SQL built from a variable.");
            }

            // One instantiation from a variable exists, of a class-string the
            // panel's own code names — never a value read from data.
            if ($path !== 'Panel/Support/Options.php') {
                $this->assertDoesNotMatchRegularExpression('/\bnew\s+\$/', $code, "{$path} instantiates a class from a variable.");
            }
        }
    }

    public function test_amounts_and_quantities_never_become_floats(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\(float\)|\bfloatval\s*\(|:\s*\??float\b|\bfloat\s+\$|\b(round|floor|ceil|fmod|number_format)\s*\(/',
                $this->code($source),
                "{$path} uses floating-point arithmetic.",
            );
        }
    }

    public function test_no_debugging_artifact_ships(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/\b(dd|dump|var_dump|print_r|ray|ddd|error_log)\s*\(|\bsleep\s*\(|Log::/', $this->code($source), "{$path} contains a debugging artifact.");

            // The one pause is a deliberate back-off before retrying a
            // deadlocked stateful calculation.
            if ($path !== 'Calculation/StatefulCalculationTransaction.php') {
                $this->assertDoesNotMatchRegularExpression('/\busleep\s*\(/', $this->code($source), "{$path} pauses.");
            }
        }
    }

    /**
     * composer.json promises PHP 8.2: nothing newer may be used.
     */
    public function test_nothing_needs_a_php_newer_than_8_2(): void
    {
        foreach ($this->sources() as $path => $source) {
            $code = $this->code($source);

            foreach ([
                '/#\[\\\\?(Override|Deprecated)\b/' => 'a PHP 8.3+ attribute',
                '/\bconst\s+(string|int|float|bool|array|self|static|\?[a-z]+)\s+[A-Z_]+\s*=/' => 'a typed class constant (8.3)',
                '/\b(json_validate|mb_str_pad|str_increment|str_decrement|array_find|array_find_key|array_any|array_all|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|request_parse_body|http_get_last_response_headers|bcround|bcfloor|bcceil|bcdivmod)\s*\(/' => 'a function added after 8.2',
                '/\b(public|protected|private)\s*\(set\)/' => 'asymmetric visibility (8.4)',
                '/(?<![\w(:>$])new\s+[A-Z][\w\\\\]*\([^()]*\)\s*->/' => 'new without parentheses (8.4)',
                '/::\{\$/' => 'a dynamic class constant fetch (8.3)',
                '/\bRandom\\\\Randomizer\b|\bgetBytesFromString\b/' => 'a PHP 8.3 random API',
            ] as $pattern => $what) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$path} uses {$what}.");
            }
        }
    }

    /**
     * A payout spends a wallet's ledger balance, and nothing else decides it.
     */
    public function test_payouts_never_read_periods_plans_runs_commissions_or_genealogy(): void
    {
        foreach ($this->sources() as $path => $source) {
            if (! str_starts_with($path, 'Payout/')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\b(PlanVersion|CommissionPeriod\w*|CalculationRun|Commission|SponsorGenealogy|PlacementGenealogy|BinaryGenealogy|MatrixGenealogy)\b(?!Status)|mlm_(plan_versions|commission_periods|calculation_runs|commissions|genealogy_paths|sponsor_edges|placement_edges)/',
                $this->code($source),
                "{$path} reads beyond the wallet.",
            );
        }
    }

    public function test_history_is_never_deleted_by_cascade(): void
    {
        foreach ((array) glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            $source = (string) file_get_contents((string) $migration);
            $name = basename((string) $migration);

            // Every foreign key says what a delete does.
            $this->assertSame(
                preg_match_all('/->constrained\(/', $source),
                preg_match_all('/->constrained\([^;]*->(restrictOnDelete|cascadeOnDelete)\(\)/', $source),
                "{$name} has a foreign key without an explicit delete rule.",
            );

            // Only a draft's own definition goes with it: a component with its
            // version, a rule with its component. Nothing financial or
            // historical ever cascades.
            if (! in_array($name, ['2026_09_28_000011_create_mlm_plan_components_table.php', '2026_09_28_000012_create_mlm_plan_rules_table.php'], true)) {
                $this->assertStringNotContainsString('cascadeOnDelete', $source, "{$name} cascades a delete.");
            }
        }
    }

    public function test_no_tenancy_and_no_metric_shorthand(): void
    {
        $root = dirname(__DIR__, 2);
        $text = implode("\n", [...array_values($this->sources()), (string) file_get_contents($root.'/README.md'), (string) file_get_contents($root.'/resources/lang/en/mlm.php'), (string) file_get_contents($root.'/resources/lang/id/mlm.php')]);

        $this->assertDoesNotMatchRegularExpression('/tenan(t|cy)/i', implode("\n", $this->sources()));
        $this->assertDoesNotMatchRegularExpression('/\b(PV|BV|GV)\b/', $text);
    }

    /**
     * @return array<string, string> path under src/ => source
     */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2).'/src/';
        $sources = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($sources);

        return $sources;
    }

    /**
     * The source without its comments and strings' prose, so a docblock
     * that mentions `dd()` or `->update()` is not mistaken for code.
     */
    private function code(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
