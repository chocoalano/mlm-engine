<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Testing\TestResponse;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Members\MemberActions;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Infolists\InfolistSchema;

/**
 * Network writes from a member's page (ADR-031): each one service call,
 * parent and side or slot chosen explicitly — sponsor never places,
 * placing never sponsors, generic placement stays unlimited, and nothing is
 * moved, removed or placed automatically.
 */
final class NetworkPanelTest extends PanelTestCase
{
    private Program $program;

    /** @var array<string, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();

        $programs = $this->app->make(ProgramManager::class);
        $this->program = $programs->create('MAIN', 'Main');

        foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
            $this->members[$code] = $programs->join($this->program, $code, now()->subYear());
        }

        $this->members['OUT'] = $programs->join($programs->create('OTHER', 'Other'), 'OUT', now()->subYear());
        $this->grant(MlmPermission::MEMBERS_VIEW, MlmPermission::NETWORK_OPERATE);
    }

    public function test_a_sponsor_is_assigned_once_through_the_sponsor_genealogy_and_places_nothing(): void
    {
        $resolved = $this->spyOn([SponsorGenealogy::class]);

        $this->network('assign-sponsor', 'B', ['sponsor_id' => $this->members['A']->id])->assertSessionHas('success', 'Sponsor assigned.');
        $this->assertSame(1, $resolved[SponsorGenealogy::class]);
        $this->assertSame($this->members['A']->id, SponsorEdge::query()->where('member_id', $this->members['B']->id)->value('sponsor_id'));
        $this->assertSame(0, PlacementEdge::query()->count(), 'Sponsoring placed the member.');

        foreach ([
            'another sponsor' => ['B', 'C'],
            'a cycle' => ['A', 'B'],
            'another program' => ['C', 'OUT'],
            'itself' => ['C', 'C'],
        ] as $case => [$member, $sponsor]) {
            $this->network('assign-sponsor', $member, ['sponsor_id' => $this->members[$sponsor]->id])
                ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Assign sponsor was refused:'));
        }

        $this->assertSame(1, SponsorEdge::query()->count());
    }

    public function test_generic_placement_takes_any_number_of_children_and_never_sponsors(): void
    {
        foreach (['B', 'C', 'D', 'E'] as $code) {
            $this->network('place', $code, ['parent_id' => $this->members['A']->id])->assertSessionHas('success', 'Member placed.');
        }

        $this->assertSame(4, PlacementEdge::query()->where('parent_id', $this->members['A']->id)->count());
        $this->assertSame([0, 0], [SponsorEdge::query()->count(), BinaryPlacementPosition::query()->count()]);

        $this->network('place', 'B', ['parent_id' => $this->members['C']->id])->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Place was refused:'));
        $this->network('place', 'A', ['parent_id' => $this->members['OUT']->id])->assertSessionHas('error');
        $this->assertSame(4, PlacementEdge::query()->count());
    }

    public function test_binary_placement_takes_the_named_side_and_leaves_generic_placement_unlimited(): void
    {
        $resolved = $this->spyOn([BinaryPlacementManager::class]);

        $this->network('place-binary', 'B', ['parent_id' => $this->members['A']->id, 'side' => 'left'])->assertSessionHas('success', 'Member placed in binary.');
        $this->assertSame(['left', $this->members['A']->id], [BinaryPlacementPosition::query()->sole()->side->value, PlacementEdge::query()->where('member_id', $this->members['B']->id)->value('parent_id')]);

        $this->network('place-binary', 'C', ['parent_id' => $this->members['A']->id, 'side' => 'left'])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Place in binary was refused:'));
        $this->network('place-binary', 'C', ['parent_id' => $this->members['A']->id])->assertSessionHasErrors('side');
        $this->network('place-binary', 'C', ['parent_id' => $this->members['A']->id, 'side' => 'middle'])->assertSessionHasErrors('side');
        $this->network('place-binary', 'OUT', ['parent_id' => $this->members['A']->id, 'side' => 'right'])->assertSessionHas('error');

        // Binary's two sides are no limit on generic placement.
        $this->network('place', 'C', ['parent_id' => $this->members['A']->id])->assertSessionHas('success');
        $this->network('place', 'D', ['parent_id' => $this->members['A']->id])->assertSessionHas('success');
        $this->assertSame(3, PlacementEdge::query()->where('parent_id', $this->members['A']->id)->count());

        // An existing generic placement takes a side of its own parent.
        $this->network('adopt-binary', 'D', ['side' => 'right'])->assertSessionHas('success', 'Binary position added.');
        $this->assertSame(['left', 'right'], BinaryPlacementPosition::query()->orderBy('side')->get()->map(static fn (BinaryPlacementPosition $position): string => $position->side->value)->all());
        $this->assertSame(1, BinaryPlacementPosition::query()->where('parent_id', $this->members['A']->id)->where('side', 'right')->count());
        $this->assertGreaterThan(0, $resolved[BinaryPlacementManager::class]);
    }

    public function test_matrix_placement_takes_the_named_slot_within_the_fixed_width(): void
    {
        $this->network('place-matrix', 'B', ['parent_id' => $this->members['A']->id, 'slot' => '1'])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'matrix'));
        $this->app->make(MatrixNetworkManager::class)->configure($this->program, 2);
        $resolved = $this->spyOn([MatrixPlacementManager::class]);

        $this->network('place-matrix', 'B', ['parent_id' => $this->members['A']->id, 'slot' => '1'])->assertSessionHas('success', 'Member placed in matrix.');
        $this->assertSame([1, $this->members['A']->id], [MatrixPlacementPosition::query()->sole()->slot, PlacementEdge::query()->where('member_id', $this->members['B']->id)->value('parent_id')]);

        $this->network('place-matrix', 'C', ['parent_id' => $this->members['A']->id, 'slot' => '1'])->assertSessionHas('error');
        $this->network('place-matrix', 'C', ['parent_id' => $this->members['A']->id, 'slot' => '3'])->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Place in matrix was refused:'));
        $this->network('place-matrix', 'C', ['parent_id' => $this->members['A']->id, 'slot' => '1.0'])->assertSessionHasErrors('slot');
        $this->network('place-matrix', 'C', ['parent_id' => $this->members['A']->id, 'slot' => 'true'])->assertSessionHasErrors('slot');
        $this->assertSame(1, MatrixPlacementPosition::query()->count());

        // No spillover: a full parent is refused, never searched past.
        $this->network('place-matrix', 'C', ['parent_id' => $this->members['A']->id, 'slot' => '2'])->assertSessionHas('success');
        $this->network('place-matrix', 'D', ['parent_id' => $this->members['A']->id, 'slot' => '2'])->assertSessionHas('error');
        $this->assertSame(0, PlacementEdge::query()->where('member_id', $this->members['D']->id)->count());

        // An existing generic placement takes a slot of its own parent.
        $this->app->make(PlacementGenealogy::class)->place($this->members['E'], $this->members['B']);
        $this->network('adopt-matrix', 'E', ['slot' => '1'])->assertSessionHas('success', 'Matrix position added.');
        $this->assertSame(1, MatrixPlacementPosition::query()->where('parent_id', $this->members['B']->id)->value('slot'));
        $this->assertGreaterThan(0, $resolved[MatrixPlacementManager::class]);
    }

    public function test_no_network_action_moves_removes_or_places_automatically(): void
    {
        $names = array_keys(MemberResource::infolist(InfolistSchema::make())->allActions());

        $this->assertSame(['assign-sponsor', 'place', 'place-binary', 'adopt-binary', 'place-matrix', 'adopt-matrix'], array_map(static fn ($action): string => $action->getName(), MemberActions::networkActions()));

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/move|remove|delete|reassign|auto|spill|next/', $name);
        }
    }

    public function test_viewing_members_or_the_network_is_not_changing_it(): void
    {
        $this->grant(MlmPermission::MEMBERS_VIEW, MlmPermission::NETWORK_VIEW, MlmPermission::MEMBERS_OPERATE, MlmPermission::PLANS_OPERATE, MlmPermission::PAYOUTS_OPERATE);

        foreach (['assign-sponsor', 'place', 'place-binary', 'place-matrix'] as $action) {
            panelInfolistActions(MemberResource::class)->assertHidden($action, $this->members['B']);
        }

        $this->network('assign-sponsor', 'B', ['sponsor_id' => $this->members['A']->id])->assertForbidden();
        $this->network('place', 'B', ['parent_id' => $this->members['A']->id])->assertForbidden();
        $this->network('place-binary', 'B', ['parent_id' => $this->members['A']->id, 'side' => 'left'])->assertForbidden();

        $this->assertSame([0, 0, 0], [SponsorEdge::query()->count(), PlacementEdge::query()->count(), BinaryPlacementPosition::query()->count()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function network(string $action, string $member, array $data): TestResponse
    {
        return $this->submitRecord('mlm-members', $action, $this->members[$member], $data);
    }
}
