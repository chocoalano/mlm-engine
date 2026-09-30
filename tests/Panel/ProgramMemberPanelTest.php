<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Panel\Resources\Programs\ProgramResource;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Tables\TableSchema;

/**
 * Programs and members created by an operator through `ProgramManager`,
 * system accounts opened and a matrix width fixed through their services —
 * and nothing about a program or member edited or deleted.
 */
final class ProgramMemberPanelTest extends PanelTestCase
{
    public function test_an_operator_creates_programs_through_the_program_manager(): void
    {
        $this->grant(MlmPermission::PROGRAMS_VIEW, MlmPermission::PROGRAMS_OPERATE);
        $resolved = $this->spyOn([ProgramManager::class]);

        $this->submitTable('mlm-programs', 'new-program', ['code' => 'MAIN', 'name' => 'Main Program'])->assertSessionHas('success', 'Program created.');
        $this->assertSame([['MAIN', 'Main Program']], Program::query()->get(['code', 'name'])->map(static fn (Program $program): array => [$program->code, $program->name])->all());

        $this->submitTable('mlm-programs', 'new-program', ['code' => 'MAIN', 'name' => 'Again'])
            ->assertSessionHas('error', 'New program was refused: A program with code "MAIN" already exists.');
        $this->submitTable('mlm-programs', 'new-program', ['code' => '', 'name' => 'Blank'])->assertSessionHasErrors('code');

        $this->assertSame(1, Program::query()->count());
        $this->assertSame(2, $resolved[ProgramManager::class]);
    }

    public function test_viewing_programs_and_members_is_not_creating_them(): void
    {
        $this->grant(MlmPermission::PROGRAMS_VIEW, MlmPermission::MEMBERS_VIEW, MlmPermission::PLANS_OPERATE, MlmPermission::PAYOUTS_OPERATE);
        $program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');

        panelTableActions(ProgramResource::class)->assertHidden('new-program')->assertCanNotRun('new-program');
        panelTableActions(MemberResource::class)->assertHidden('new-member')->assertCanNotRun('new-member');

        $this->submitTable('mlm-programs', 'new-program', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->submitTable('mlm-members', 'new-member', ['program_id' => $program->id, 'member_code' => 'M-1', 'joined_at' => '2026-01-01 00:00:00'])->assertForbidden();
        $this->submitRecord('mlm-programs', 'open-system-account', $program, ['currency' => 'IDR', 'key' => 'commission.payable'])->assertForbidden();

        $this->assertSame([1, 0, 0], [Program::query()->count(), Member::query()->count(), LedgerAccount::query()->count()]);
    }

    public function test_a_member_joins_the_chosen_program_with_a_unique_code_and_identity(): void
    {
        $this->grant(MlmPermission::MEMBERS_VIEW, MlmPermission::MEMBERS_OPERATE);
        $program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');
        $other = $this->app->make(ProgramManager::class)->create('OTHER', 'Other');

        $this->submitTable('mlm-members', 'new-member', ['program_id' => $program->id, 'member_code' => 'M-1', 'external_type' => 'user', 'external_id' => '42', 'joined_at' => '2026-01-15 08:30:00'])
            ->assertSessionHas('success', 'Member added.');

        $member = Member::query()->sole();
        $this->assertSame([$program->id, 'M-1', 'user', '42', '2026-01-15 08:30:00'], [$member->program_id, $member->member_code, $member->external_type, $member->external_id, $member->joined_at->format('Y-m-d H:i:s')]);

        $this->submitTable('mlm-members', 'new-member', ['program_id' => $program->id, 'member_code' => 'M-2', 'external_type' => 'user', 'external_id' => '42', 'joined_at' => '2026-01-16 00:00:00'])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'external identity user:42'));
        $this->submitTable('mlm-members', 'new-member', ['program_id' => $program->id, 'member_code' => 'M-3', 'external_type' => 'user', 'joined_at' => '2026-01-16 00:00:00'])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'New member was refused:'));

        // The same identity in another program is another member.
        $this->submitTable('mlm-members', 'new-member', ['program_id' => $other->id, 'member_code' => 'M-1', 'external_type' => 'user', 'external_id' => '42', 'joined_at' => '2026-01-15 08:30:00'])
            ->assertSessionHas('success');

        $this->assertSame([1, 1], [$program->members()->count(), $other->members()->count()]);
    }

    public function test_programs_and_members_are_never_edited_moved_or_deleted_from_the_panel(): void
    {
        $this->grantAll();
        $program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');
        $member = $this->app->make(ProgramManager::class)->join($program, 'M-1', now());

        foreach ([ProgramResource::class => $program, MemberResource::class => $member] as $resource => $record) {
            $this->assertSame(['index', 'view'], array_keys($resource::pages()));
            $this->assertFalse($resource::canEdit($record));
            $this->assertFalse($resource::canDelete($record));

            $names = [
                ...array_keys($resource::infolist(InfolistSchema::make())->allActions()),
                ...array_map(static fn ($action): string => $action->getName(), $resource::table(TableSchema::make())->getRecordActions()),
            ];

            foreach ($names as $name) {
                $this->assertDoesNotMatchRegularExpression('/delete|edit|move|remove|reassign|change/', $name, "{$resource} offers [{$name}].");
            }
        }
    }

    public function test_a_program_opens_system_accounts_and_fixes_its_matrix_width_once(): void
    {
        $this->grant(MlmPermission::PROGRAMS_VIEW, MlmPermission::PROGRAMS_OPERATE, MlmPermission::NETWORK_OPERATE);
        $program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');

        $this->submitRecord('mlm-programs', 'open-system-account', $program, ['currency' => 'IDR', 'key' => 'payout.settlement'])->assertSessionHas('success');
        $this->submitRecord('mlm-programs', 'open-system-account', $program, ['currency' => 'IDR', 'key' => 'wallet.stolen'])->assertSessionHas('error');

        $account = LedgerAccount::query()->sole();
        $this->assertSame([$program->id, 'IDR', 'payout.settlement', null], [$account->program_id, $account->currency, $account->key, $account->wallet_id]);

        panelInfolistActions(ProgramResource::class)->assertVisible('configure-matrix', $program);
        $this->submitRecord('mlm-programs', 'configure-matrix', $program, ['width' => '0'])->assertSessionHasErrors('width');
        $this->submitRecord('mlm-programs', 'configure-matrix', $program, ['width' => '2.5'])->assertSessionHasErrors('width');
        $this->submitRecord('mlm-programs', 'configure-matrix', $program, ['width' => '3'])->assertSessionHas('success', 'Matrix network configured.');
        $this->assertSame(3, MatrixNetwork::query()->where('program_id', $program->id)->value('width'));

        // Fixed: no longer offered, and a request that asks is refused.
        panelInfolistActions(ProgramResource::class)->assertHidden('configure-matrix', $program);
        $this->submitRecord('mlm-programs', 'configure-matrix', $program, ['width' => '4'])->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Configure matrix was refused:'));
        $this->assertSame(3, MatrixNetwork::query()->where('program_id', $program->id)->value('width'));
    }

    public function test_a_program_links_to_its_records_filtered_to_it(): void
    {
        $this->grantAll();
        $program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');
        $other = $this->app->make(ProgramManager::class)->create('OTHER', 'Other');
        $this->app->make(ProgramManager::class)->join($program, 'MINE', now());
        $this->app->make(ProgramManager::class)->join($other, 'THEIRS', now());

        $actions = collect($this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/mlm-programs/{$program->id}")->assertOk()->json('props.infolist.actions'))->keyBy('name');
        $this->assertStringContainsString('filters%5Bprogram_id%5D='.$program->id, (string) $actions['open-members']['url']);
        $this->assertTrue($actions->has('open-genealogy'));

        $rows = $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-members?filters[program_id]='.$program->id)->json('props.rows');
        $this->assertSame(['MINE'], array_column(array_column($rows, 'cells'), 'member_code'));
    }
}
