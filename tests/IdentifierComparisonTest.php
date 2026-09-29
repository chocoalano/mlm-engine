<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;

/**
 * Business identifiers are compared exactly, on every database: two values
 * that differ only in letter case are two different values. MySQL's default
 * collations compare case-insensitively, so this is not free there.
 */
final class IdentifierComparisonTest extends DatabaseTestCase
{
    use BuildsLedgers;
    use RecordsVolume;

    public function test_program_codes_are_compared_exactly(): void
    {
        Program::create(['code' => 'MAIN', 'name' => 'Main']);
        Program::create(['code' => 'main', 'name' => 'Main, lower case']);

        $this->assertSame(1, Program::where('code', 'main')->count());
    }

    public function test_member_codes_and_external_identities_are_compared_exactly(): void
    {
        $program = Program::factory()->create();
        $program->members()->create(['member_code' => 'MBR-A', 'external_type' => 'user', 'external_id' => 'ABC', 'joined_at' => now()]);
        $program->members()->create(['member_code' => 'mbr-a', 'external_type' => 'user', 'external_id' => 'abc', 'joined_at' => now()]);

        $this->assertSame(1, $program->members()->where('member_code', 'mbr-a')->count());
        $this->assertSame(1, $program->members()->where('external_id', 'abc')->count());
    }

    public function test_plan_codes_are_compared_exactly(): void
    {
        $program = Program::factory()->create();
        $program->plans()->create(['code' => 'STANDARD', 'name' => 'Standard']);
        $program->plans()->create(['code' => 'standard', 'name' => 'Standard, lower case']);

        $this->assertSame(1, $program->plans()->where('code', 'standard')->count());
    }

    public function test_idempotency_keys_differing_in_case_are_different_commands(): void
    {
        $member = Member::factory()->create();

        $upper = $this->record($member, '10', 'ORDER:ORD-1', sourceId: 'ORD-1');
        $lower = $this->recorder()->record(new RecordVolume(
            member: $member,
            type: 'sales',
            quantity: Quantity::of('99'),
            sourceType: 'order',
            sourceId: 'ord-1',
            idempotencyKey: 'order:ord-1',
            effectiveAt: CarbonImmutable::parse('2026-06-01 12:00:00'),
        ));

        $this->assertFalse($lower->is($upper));
        $this->assertSame('99', $lower->quantity->value());
        $this->assertSame('109', $this->totals()->forMember($member, 'sales')->value());
    }

    public function test_ledger_identifiers_and_currencies_are_compared_exactly(): void
    {
        $member = Member::factory()->create();
        $wallet = $this->walletAccount($member);
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');

        $upper = $this->postLedger($member->program, [[$clearing, '-10'], [$wallet, '10']], 'ADJUSTMENT:ADJ-1', 'ADJ-1');
        $lower = $this->postLedger($member->program, [[$clearing, '-99'], [$wallet, '99']], 'adjustment:adj-1', 'adj-1');

        $this->assertFalse($lower->is($upper));
        $this->assertSame('109', $this->balances()->forAccount($wallet)->value());
        $this->assertSame(1, LedgerTransaction::query()->where('idempotency_key', 'adjustment:adj-1')->count());
        $this->assertSame(0, Wallet::query()->where('currency', 'idr')->count());
        $this->assertSame(0, LedgerAccount::query()->where('key', 'ADJUSTMENT.CLEARING')->count());
    }
}
