<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Members;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\ConflictingProgramRecord;
use PandaBear\Mlm\Exceptions\InvalidBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidExternalIdentity;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\GenealogyExplorer;
use PandaBear\Mlm\Panel\Resources\Commissions\CommissionResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Panel\Resources\Wallets\WalletResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Resources\Resource;

/**
 * A member joining a program, and each network write the domain supports on
 * a member — one service call each, with its parent (and side or slot)
 * chosen explicitly. Sponsoring never places, placing never sponsors, and
 * nothing here moves, removes or searches for a position.
 */
final class MemberActions
{
    public static function creation(): Action
    {
        return DomainAction::withForm('new-member', MlmPermission::MEMBERS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                Select::make('program_id')
                    ->label(Display::field('program'))
                    ->searchable()
                    ->existsIn(...Options::exists(Program::class))
                    ->optionsUsing(static fn (FormState $state, ?string $search = null): array => Options::programs($search))
                    ->required(),
                TextInput::make('member_code')->label(Display::field('member_code'))->required()->maxLength(64),
                TextInput::make('external_type')->label(Display::field('external_type'))->helperText(__('mlm::mlm.helpers.external_identity'))->maxLength(64),
                TextInput::make('external_id')->label(Display::field('external_id'))->maxLength(128),
                DateTimePicker::make('joined_at')->label(Display::field('joined_at'))->seconds()->required()->rules(['date'])->default(CarbonImmutable::now()->format('Y-m-d H:i:s')),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('new-member', MlmPermission::MEMBERS_OPERATE, [ConflictingProgramRecord::class, InvalidExternalIdentity::class], static fn (): Member => app(ProgramManager::class)->join(
                    Program::query()->findOrFail($data['program_id'] ?? null),
                    (string) ($data['member_code'] ?? ''),
                    CarbonImmutable::parse((string) ($data['joined_at'] ?? 'now')),
                    self::nullable($data['external_type'] ?? null),
                    self::nullable($data['external_id'] ?? null),
                ));
            });
    }

    /**
     * Once: a sponsor is never replaced. It says nothing about placement.
     */
    public static function assignSponsor(): Action
    {
        return self::network('assign-sponsor', [self::parent('sponsor_id', 'sponsor')], static fn (Member $member, array $data): mixed => app(SponsorGenealogy::class)
            ->assignSponsor($member, self::member($data['sponsor_id'] ?? null)), [InvalidSponsorAssignment::class]);
    }

    /**
     * A generic placement under any parent: unlimited children, no side and
     * no slot.
     */
    public static function place(): Action
    {
        return self::network('place', [self::parent('parent_id', 'placement_parent')], static fn (Member $member, array $data): mixed => app(PlacementGenealogy::class)
            ->place($member, self::member($data['parent_id'] ?? null)), [InvalidPlacementAssignment::class]);
    }

    /**
     * A new generic placement and its binary position at once, on the side
     * the operator names.
     */
    public static function placeBinary(): Action
    {
        return self::network('place-binary', [self::parent('parent_id', 'placement_parent'), self::side()], static fn (Member $member, array $data): mixed => app(BinaryPlacementManager::class)
            ->place($member, self::member($data['parent_id'] ?? null), self::sideOf($data)), [InvalidPlacementAssignment::class, InvalidBinaryPlacement::class]);
    }

    /**
     * A binary position for the generic placement the member already has.
     */
    public static function adoptBinary(): Action
    {
        return self::network('adopt-binary', [self::side()], static fn (Member $member, array $data): mixed => app(BinaryPlacementManager::class)
            ->adopt(self::edgeOf($member), self::sideOf($data)), [InvalidBinaryPlacement::class])
            ->visible(static fn (?Model $member = null): bool => self::hasPlacement($member));
    }

    public static function placeMatrix(): Action
    {
        return self::network('place-matrix', [self::parent('parent_id', 'placement_parent'), self::slot()], static fn (Member $member, array $data): mixed => app(MatrixPlacementManager::class)
            ->place($member, self::member($data['parent_id'] ?? null), DomainAction::integer($data['slot'] ?? null)), [InvalidPlacementAssignment::class, InvalidMatrixPlacement::class]);
    }

    public static function adoptMatrix(): Action
    {
        return self::network('adopt-matrix', [self::slot()], static fn (Member $member, array $data): mixed => app(MatrixPlacementManager::class)
            ->adopt(self::edgeOf($member), DomainAction::integer($data['slot'] ?? null)), [InvalidMatrixPlacement::class])
            ->visible(static fn (?Model $member = null): bool => self::hasPlacement($member));
    }

    /**
     * @return list<Action>
     */
    public static function networkActions(): array
    {
        return [self::assignSponsor(), self::place(), self::placeBinary(), self::adoptBinary(), self::placeMatrix(), self::adoptMatrix()];
    }

    /**
     * @return list<Action>
     */
    public static function links(): array
    {
        $searched = static fn (string $resource): \Closure => static fn (Member $member): string => $resource::url().'?'.http_build_query(['search' => $member->member_code]);
        $open = static fn (string $resource): \Closure => static fn (): bool => $resource::canViewAny();

        /** @var array<string, class-string<resource>> $screens */
        $screens = [
            'wallets' => WalletResource::class,
            'commissions' => CommissionResource::class,
            'payouts' => PayoutRequestResource::class,
        ];

        $links = [DomainAction::link('open-genealogy', __('mlm::mlm.links.genealogy'), static fn (Member $member): string => GenealogyExplorer::urlFor($member), static fn (): bool => GenealogyExplorer::canAccess())];

        foreach ($screens as $key => $resource) {
            $links[] = DomainAction::link("open-{$key}", __("mlm::mlm.links.{$key}"), $searched($resource), $open($resource));
        }

        return $links;
    }

    /**
     * @param  list<Field>  $fields
     * @param  \Closure(Member, array<string, mixed>): mixed  $write
     * @param  list<class-string<\Throwable>>  $refusals
     */
    private static function network(string $name, array $fields, \Closure $write, array $refusals): Action
    {
        return DomainAction::withForm($name, MlmPermission::NETWORK_OPERATE)
            ->icon('link')
            ->schema(static fn (?Model $member = null): FormSchema => FormSchema::make()->schema(array_map(
                static fn (\Closure|Field $field): Field => $field instanceof \Closure ? $field($member) : $field,
                $fields,
            )))
            ->action(static function (Member $member, array $data = []) use ($name, $write, $refusals): void {
                DomainAction::attempt($name, MlmPermission::NETWORK_OPERATE, $refusals, static fn (): mixed => $write($member, $data));
            });
    }

    /**
     * A member of the same program to relate to. Offered from the member's
     * program; whoever is submitted, the service decides whether it may.
     *
     * @return \Closure(?Model): Select
     */
    private static function parent(string $name, string $label): \Closure
    {
        return static fn (?Model $member): Select => Select::make($name)
            ->label(Display::field($label))
            ->searchable()
            ->existsIn(...Options::exists(Member::class))
            ->optionsUsing(static fn (FormState $state, ?string $search = null): array => $member instanceof Member
                ? Options::members((string) $member->program_id, $search, (string) $member->getKey())
                : [])
            ->required();
    }

    private static function side(): Select
    {
        return Select::make('side')->label(Display::field('side'))->options(Options::binarySides())->required();
    }

    private static function slot(): NumberInput
    {
        return NumberInput::make('slot')->label(Display::field('slot'))->helperText(__('mlm::mlm.helpers.slot'))->integer()->min(1)->required()->rules(['integer', 'min:1']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function sideOf(array $data): BinarySide
    {
        return BinarySide::from((string) ($data['side'] ?? ''));
    }

    private static function member(mixed $id): Member
    {
        return Member::query()->findOrFail($id);
    }

    private static function edgeOf(Member $member): PlacementEdge
    {
        return PlacementEdge::query()->where('member_id', $member->getKey())->firstOrFail();
    }

    private static function hasPlacement(?Model $member): bool
    {
        return $member instanceof Member && PlacementEdge::query()->where('member_id', $member->getKey())->exists();
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
