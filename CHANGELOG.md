# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-12

### Added

- `ApplyGiftCard`, the box a shopper types a gift card code into, mounted on a
  reference the host prices server-side.
- `MyBalances`, a signed-in customer's own gift cards and store credit, folded
  from the append-only ledger.
- Registration by `Livewire::resolveMissingComponent()` under one namespace,
  with an explicit alias table as the public interface.
- Publishable views, translations and config, each under its own tag.

### Security

- The gift card code is a **method argument and never a property**: no
  `wire:model`, nothing dehydrated, nothing rehydrated. Proved by a test that
  walks every public property's value, the rendered HTML, the session, the error
  bag and the log with the domain's telemetry switched on.
- **One refusal message and one component shape** across all ten ways a
  redemption can fail, including being rate limited and including a conflicting
  second card. Asserted over a dataset of every one of them.
- `#[Locked]` on every public property of every registered component, enforced by
  reflection, with no exceptions list.
- A second rate limit keyed by IP address, alongside the domain's per-actor one.
  Neither can be switched off from configuration; nonsense values fall back to
  the defaults.
- No money value is a client input, and no component holds a property named for
  money.
- The idempotency key is derived from the host's reference, so a reload replays
  the first movement rather than debiting the card again. A conflict refuses and
  mints nothing.
