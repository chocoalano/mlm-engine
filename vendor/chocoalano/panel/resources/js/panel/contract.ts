/**
 * The protocol version this frontend speaks.
 *
 * Mirrors `PandaPanel\Support\FrontendContract::VERSION`, and the pair is the
 * whole of the drift check. The panel's components are published into the
 * application rather than imported from the package, so after a
 * `composer update` the PHP is new and the components are whatever was
 * published last — and a frontend that is behind in a way that changed the
 * wire fails silently: a prop nothing reads, an endpoint nothing calls, a
 * payload the narrowing rejects. All of it reads as "the panel is broken",
 * with nothing in any log.
 *
 * This file is published with the rest, so a stale copy carries a stale
 * number and the mismatch is what gets reported.
 *
 * See `FrontendContract` for what each version added, and bump both together.
 */
export const PANEL_CONTRACT_VERSION = 2;
