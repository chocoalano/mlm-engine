<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Pages;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\GenealogyTree;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Panel\Widgets\GenealogySummaryWidget;
use PandaBear\Mlm\Panel\Widgets\GenealogyTreeWidget;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Pages\Page;
use Throwable;

/**
 * One anchor member's sponsor, placement, binary or matrix network — as it
 * stands, or as it stood at a moment — to a chosen depth. Read-only: every
 * change to a network is an explicit action on a member.
 */
final class GenealogyExplorer extends Page
{
    protected static ?string $slug = 'mlm-genealogy';

    protected static ?string $navigationIcon = 'link';

    protected static int $navigationSort = MlmNavigation::GENEALOGY;

    public static function title(): string
    {
        return __('mlm::mlm.explorer.title');
    }

    public static function subheading(): ?string
    {
        return __('mlm::mlm.explorer.subheading');
    }

    public static function navigationGroup(): string
    {
        return MlmNavigation::group();
    }

    public static function canAccess(): bool
    {
        return MlmPermission::allows(MlmPermission::NETWORK_VIEW);
    }

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('program_id')->label(Display::field('program'))->options(Options::programs()),
            TextInput::make('member_code')->label(Display::field('anchor'))->helperText(__('mlm::mlm.explorer.anchor_help')),
            Select::make('network')
                ->label(Display::field('network'))
                ->options(array_combine(GenealogyTree::NETWORKS, array_map(static fn (string $network): string => __("mlm::mlm.explorer.networks.{$network}"), GenealogyTree::NETWORKS)))
                ->default('sponsor'),
            DateTimePicker::make('as_of')->label(Display::field('as_of'))->seconds()->helperText(__('mlm::mlm.explorer.as_of_help')),
            NumberInput::make('depth')->label(Display::field('max_depth'))->integer()->min(1)->max(GenealogyTree::MAX_DEPTH)->default(3),
        ]);
    }

    /**
     * @return list<class-string>
     */
    public function widgets(): array
    {
        return [GenealogySummaryWidget::class, GenealogyTreeWidget::class];
    }

    /**
     * The explorer's reading for these filters, once per request however
     * many widgets ask. Null until a program and an anchor of it are chosen.
     *
     * @param  array<string, mixed>  $filters
     * @return array{anchor: Member, network: string, at: CarbonImmutable|null, tree: array<string, mixed>}|null
     */
    public static function reading(array $filters): ?array
    {
        $key = 'mlm.genealogy.'.md5((string) json_encode($filters));
        $attributes = request()->attributes;

        if ($attributes->has($key)) {
            return $attributes->get($key);
        }

        $program = $filters['program_id'] ?? null;
        $code = $filters['member_code'] ?? null;
        $network = in_array($filters['network'] ?? null, GenealogyTree::NETWORKS, true) ? $filters['network'] : 'sponsor';
        $depth = is_numeric($filters['depth'] ?? null) ? (int) $filters['depth'] : 3;

        try {
            $at = is_string($filters['as_of'] ?? null) && $filters['as_of'] !== '' ? CarbonImmutable::parse($filters['as_of']) : null;
        } catch (Throwable) {
            $at = null;
        }

        // An anchor is looked up inside the chosen program only: the
        // explorer never crosses a program boundary.
        $anchor = is_string($program) && is_string($code) && $code !== ''
            ? Member::query()->where('program_id', $program)->where('member_code', $code)->first()
            : null;

        $reading = $anchor === null ? null : [
            'anchor' => $anchor,
            'network' => $network,
            'at' => $at,
            'tree' => GenealogyTree::read($anchor, $network, $depth, $at),
        ];

        $attributes->set($key, $reading);

        return $reading;
    }

    /**
     * The explorer, opened on a member.
     */
    public static function urlFor(Member $member, string $network = 'sponsor'): string
    {
        return self::url().'?'.http_build_query(['filters' => [
            'program_id' => $member->program_id,
            'member_code' => $member->member_code,
            'network' => $network,
            'depth' => 3,
        ]]);
    }
}
