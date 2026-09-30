# Public API

What an application may build on. Everything listed is resolved from the container (every service here is container-built — `PackageIntegrityTest` resolves each one) and keeps its meaning across minor versions once 1.0 is tagged. Anything not listed, and every class marked `@internal`, is an implementation detail that may change without notice.

## Package integration

| Class | Use |
| --- | --- |
| `PandaMlmServiceProvider` | discovered by Laravel; merges `config/mlm.php`, registers migrations, translations (`mlm::`) and the registries |
| `PandaMlmPlugin` | `PandaMlmPlugin::make()` on a panel's `plugins([...])`; `permissions()` lists the capabilities |
| `Panel\MlmPermission` | the capability names — `all()`, `view()`, `operate()` |
| `Support\PandaMlmConfig` | the technical configuration: connection, queue, cache |

## Programs and members

| Class | Methods |
| --- | --- |
| `Program\ProgramManager` | `create()`, `join()`, `addPlan()` |
| `Models\Program`, `Models\Member`, `Models\Plan` | read models; a member joins through `$program->members()` |

## Networks

| Class | Methods |
| --- | --- |
| `Genealogy\SponsorGenealogy` | `assignSponsor()`, `directSponsor[At]()`, `directMembers[At]()`, `ancestors[At]()`, `descendants[At]()` |
| `Genealogy\PlacementGenealogy` | `place()`, `directParent[At]()`, `directChildren[At]()`, `ancestors[At]()`, `descendants[At]()` |
| `Binary\BinaryPlacementManager` | `place()`, `adopt()` |
| `Binary\BinaryGenealogy` | `positionOf[At]()`, `directParent[At]()`, `positionUnder[At]()`, `child[At]()`, `ancestors[At]()`, `descendants[At]()` |
| `Matrix\MatrixNetworkManager` | `configure()` |
| `Matrix\MatrixPlacementManager` | `place()`, `adopt()` |
| `Matrix\MatrixGenealogy` | `network()`, `positionOf[At]()`, `directParent[At]()`, `child[At]()`, `ancestors[At]()`, `descendants[At]()` |

## Volume and metrics

| Class | Methods |
| --- | --- |
| `Volume\VolumeRecorder` | `record(RecordVolume)`, `reverse(ReverseVolume)` |
| `Volume\VolumeTotals` | `forMember()` |
| `Metrics\MetricRegistry` | `register(Metric)`, `has()`, `get()`, `keys()` — an application registers its own metrics here |
| `Metrics\MetricEngine` | `resolve(key, MetricContext)` |
| `Metrics\Metric` | the contract a custom metric implements |

## Planning, qualification and rank

| Class | Methods |
| --- | --- |
| `Planning\PlanVersionLifecycle` | `draft()`, `markValidated()`, `publish()`, `activate()`, `archive()` |
| `Planning\PlanDefinitionEditor` | `addComponent()`, `updateComponent()`, `removeComponent()`, `addRule()`, `updateRule()`, `removeRule()` |
| `Planning\PlanDefinitionValidator` | `validate()`, `definition()` |
| `Planning\PlanDefinitionCloner` | `cloneToNewDraft()` |
| `Planning\PlanComponentDriverRegistry` | `register(PlanComponentDriver)`, `has()`, `get()`, `keys()` |
| `Planning\Rules\RuleDefinition`, `Planning\Rules\RuleOperator`, `Planning\Rules\RuleCombinator` | the rule language |
| `Qualification\QualificationEngine` | `evaluate()` |
| `Rank\RankEngine` | `evaluate()` |

## Calculation and commissions

| Class | Methods |
| --- | --- |
| `Commission\CommissionStrategyRegistry` | `register(CommissionStrategy)`, `has()`, `get()`, `keys()` — an application registers its own strategies here |
| `Commission\CommissionStrategy`, `Commission\StatefulCommissionStrategy` | the contracts a custom strategy implements |
| `Calculation\CalculationEngine` | `calculate()` — one component over a range |
| `Commission\HybridCalculationEngine` | `calculate()` — every commission component of a version |
| `Period\CommissionPeriodManager` | `create()` |
| `Period\CommissionPeriodCalculator`, `Period\CommissionPeriodFinalizer`, `Period\CommissionPeriodReleaser` | `calculate()`, `finalize()`, `release()` |
| `Period\CommissionPeriodTotals` | `of()` |
| `Commission\CommissionLifecycle` | `markPending()`, `approve()`, `cancel()` |
| `Commission\CommissionPoster` | `post()`, `reverse()` |
| `Commission\CommissionAdjustmentEngine` | `processVolumeReversal()`, `processBinaryReversal()` |

## Ledger, wallets and payouts

| Class | Methods |
| --- | --- |
| `Finance\WalletManager` | `open()` |
| `Finance\LedgerAccountManager` | `openSystemAccount()` |
| `Finance\LedgerRecorder` | `post(PostLedgerTransaction)`, `reverse(ReverseLedgerTransaction)` |
| `Finance\LedgerBalanceReader` | `forAccount()`, `forWallet()`, `forWallets()` |
| `Finance\FinancialAmount`, `Finance\CurrencyCode` | exact values |
| `Payout\PayoutManager` | `request()`, `approve()`, `startProcessing()`, `settle()`, `fail()`, `cancel()` |
| `Payout\PayoutBatchManager` | `create()`, `add()`, `seal()`, `startProcessing()`, `complete()`, `cancel()` |
| `Payout\PayoutBatchTotals` | `of()` |

## Models and exceptions

Every model under `Models\` is a read model. The history and financial ones refuse Eloquent writes: each has one writer, listed above. Status enums (`CommissionStatus`, `CommissionPeriodStatus`, `PayoutRequestStatus`, `PayoutBatchStatus`, `PlanVersionStatus`) are public. Exceptions under `Exceptions\` are public: a refused business step throws a `DomainException` naming what it refused; `Corrupt*` exceptions report stored data that no supported write produces.

## Internal

Classes marked `@internal` — parameter readers, `ClosureTree`, `MatrixAncestry`, `NetworkVolumeTotals`, `PayoutLedger`, `CommissionStatusWriter`, `FinanceInput`, `EffectiveMoment` and the strategy support classes among them — are used within the package only. The Panda Panel adapter under `Panel\` (resources, pages, widgets, support) is the plugin's own surface: an application installs the plugin rather than extending these classes.
