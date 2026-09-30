<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Members;

use Illuminate\Database\Eloquent\Builder;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * Members, read-only, with their current direct network position. No
 * subtree is ever loaded here; genealogy writes belong to their services.
 */
final class MemberResource extends MlmResource
{
    protected static string $model = Member::class;

    protected static ?string $slug = 'mlm-members';

    protected static ?string $recordTitleAttribute = 'member_code';

    protected static ?string $navigationIcon = 'user';

    protected static int $navigationSort = MlmNavigation::MEMBERS;

    protected static function viewPermission(): string
    {
        return MlmPermission::MEMBERS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'member';
    }

    /**
     * Each member with its direct sponsor's and placement parent's codes,
     * as correlated subqueries rather than a lookup per row.
     *
     * @return Builder<Member>
     */
    public static function query(): Builder
    {
        $parentCode = static fn (string $edges, string $parentColumn): Builder => Member::query()
            ->from('mlm_members as parent_member')
            ->join("{$edges} as edge", "edge.{$parentColumn}", '=', 'parent_member.id')
            ->whereColumn('edge.member_id', 'mlm_members.id')
            ->select('parent_member.member_code')
            ->limit(1);

        return parent::query()
            ->select('mlm_members.*')
            ->addSelect([
                'sponsor_code' => $parentCode('mlm_sponsor_edges', 'sponsor_id'),
                'placement_parent_code' => $parentCode('mlm_placement_edges', 'parent_id'),
            ]);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('member_code')->label(Display::field('member_code'))->searchable()->sortable(),
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('external_type')->label(Display::field('external_type'))->placeholder(Display::none()),
                TextColumn::make('external_id')->label(Display::field('external_id'))->searchable()->placeholder(Display::none()),
                DateTimeColumn::make('joined_at')->label(Display::field('joined_at'))->sortable(),
                TextColumn::make('sponsor_code')->label(Display::field('sponsor'))->placeholder(Display::none())->queryable(false),
                TextColumn::make('placement_parent_code')->label(Display::field('placement_parent'))->placeholder(Display::none())->queryable(false),
                NumberColumn::make('wallets_count')->label(Display::field('wallets_count'))->counts('wallets')->queryable(false),
            ])
            ->defaultSort('joined_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'user')
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('member_code')->label(Display::field('member_code')),
                TextEntry::make('program.name')->label(Display::field('program')),
                TextEntry::make('external_type')->label(Display::field('external_type'))->placeholder(Display::none()),
                TextEntry::make('external_id')->label(Display::field('external_id'))->placeholder(Display::none()),
                DateTimeEntry::make('joined_at')->label(Display::field('joined_at')),
                TextEntry::make('id')->label(Display::field('id')),
            ]),
            Section::make(Display::section('network'))
                ->description(Display::section('network_description'))
                ->columns(2)
                ->schema([
                    TextEntry::make('sponsor')
                        ->label(Display::field('sponsor'))
                        ->formatUsing(static fn (mixed $value, Member $member): ?string => app(SponsorGenealogy::class)->directSponsor($member)?->member_code)
                        ->placeholder(Display::none()),
                    TextEntry::make('sponsor_direct_count')
                        ->label(Display::field('sponsor_direct_count'))
                        ->formatUsing(static fn (mixed $value, Member $member): string => (string) SponsorEdge::query()->where('sponsor_id', $member->getKey())->count()),
                    TextEntry::make('placement_parent')
                        ->label(Display::field('placement_parent'))
                        ->formatUsing(static fn (mixed $value, Member $member): ?string => app(PlacementGenealogy::class)->directParent($member)?->member_code)
                        ->placeholder(Display::none()),
                    TextEntry::make('placement_direct_count')
                        ->label(Display::field('placement_direct_count'))
                        ->formatUsing(static fn (mixed $value, Member $member): string => (string) PlacementEdge::query()->where('parent_id', $member->getKey())->count()),
                    TextEntry::make('binary_position')
                        ->label(Display::field('binary_position'))
                        ->formatUsing(static fn (mixed $value, Member $member): string => self::binaryPosition($member)),
                    TextEntry::make('matrix_position')
                        ->label(Display::field('matrix_position'))
                        ->formatUsing(static fn (mixed $value, Member $member): string => self::matrixPosition($member)),
                ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListMembers::class,
            'view' => ViewMember::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [MemberWalletsRelation::class];
    }

    private static function binaryPosition(Member $member): string
    {
        $position = BinaryPlacementPosition::query()
            ->whereHas('placementEdge', static fn (Builder $edge): Builder => $edge->where('member_id', $member->getKey()))
            ->with('parent')
            ->first();

        return $position === null
            ? __('mlm::mlm.values.not_enrolled')
            : __('mlm::mlm.values.binary_side', ['side' => $position->side->value, 'parent' => $position->parent->member_code]);
    }

    private static function matrixPosition(Member $member): string
    {
        $position = MatrixPlacementPosition::query()
            ->whereHas('placementEdge', static fn (Builder $edge): Builder => $edge->where('member_id', $member->getKey()))
            ->with('parent')
            ->first();

        return $position === null
            ? __('mlm::mlm.values.not_enrolled')
            : __('mlm::mlm.values.matrix_slot', ['slot' => $position->slot, 'parent' => $position->parent->member_code]);
    }
}
