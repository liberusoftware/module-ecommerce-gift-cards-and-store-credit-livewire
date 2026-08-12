# Gift Cards and Store Credit — Livewire

Livewire 4 storefront surface for
[`liberusoftware/ecommerce-gift-cards-and-store-credit`](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit).

**One box a shopper types a gift card code into, and one list of the balances
they already own.** That is the whole package, and the shortness of the list is
the design.

A gift card code is a bearer credential: whoever holds it holds the money. This
is the only surface in the fleet where hostile input meets one, so it is built as
an attack surface that happens to also be a feature.

---

## What it does

| Component | Alias | |
| --- | --- | --- |
| `ApplyGiftCard` | `module-ecommerce-gift-cards-and-store-credit::apply` | Spend a card against a purchase your application priced |
| `MyBalances` | `module-ecommerce-gift-cards-and-store-credit::balances` | A signed-in customer's own cards and store credit, folded from the ledger |

```blade
<livewire:module-ecommerce-gift-cards-and-store-credit::apply :reference="$checkout->reference" />
<livewire:module-ecommerce-gift-cards-and-store-credit::balances />
```

---

## The five decisions worth knowing before you read the code

**The code is a method argument, not a property.** Every public property carries
`#[Locked]` with no exceptions list, and the one thing a shopper types is not on
the list because it is not a property at all. It arrives as a call parameter,
lives for one stack frame, and is never dehydrated back into the page. A test
walks every property's value, the rendered HTML, the session, the error bag and
the log — with the domain's telemetry switched on — and asserts the code is in
none of them. [`docs/domain.md` §2](docs/domain.md).

**Every refusal is one sentence and one shape.** An unknown code, a stopped card,
an expired card, a foreign currency, an empty card, a short card, a throttled
presenter and a conflicting second card all answer identically — and leave the
component in an identical state, because what a browser receives is the state and
not only the sentence. Anything more specific tells a guesser their code is real.

**There is no *check your balance* box, on purpose.** The domain publishes no
lookup by code except spending one, and a second one built here would be an
oracle with its own throttle and its own timing profile to keep aligned. A
customer sees their balances signed in; a bearer sees what is left the moment they
spend. [`docs/domain.md` §3](docs/domain.md).

**No money value is ever a client input.** The component is mounted with an
opaque reference and your application prices it, server-side, on the request that
spends. No component here has a property even *named* for money.

**The idempotency key is derived from the step, not drawn at random.** A reload
therefore replays the first movement instead of debiting the card a second time —
which matters here more than elsewhere, because this package cannot read the
ledger for a card nobody has typed a code for yet. A conflict refuses and mints
nothing. [`docs/domain.md` §5](docs/domain.md).

---

## Rate limits

| Bucket | Keyed by | Enforced by | Default |
| --- | --- | --- | --- |
| Actor | signed-in customer, else session | the domain | 5 / minute |
| Address | `request()->ip()` | this package | 20 / minute |

Either alone is walked around: a session limit is five attempts per cookie jar,
and an address limit alone locks out a building. Neither can be switched off from
configuration. [`docs/adoption.md` §4](docs/adoption.md).

---

## Installing

Nothing boots on install. The package ships no `extra.laravel.providers`; the
module manager registers it only when the deployment names it.

```dotenv
MODULES_ENABLED=…,ecommerce-gift-cards-and-store-credit,ecommerce-gift-cards-and-store-credit-livewire
```

The domain package is not on Packagist, so your application needs its VCS
`repositories` entry, and its adoption guide has a decision about your existing
plaintext codes that has to be made first.
[`docs/adoption.md`](docs/adoption.md).

---

## Docs

- [`docs/domain.md`](docs/domain.md) — every decision, and the reasoning
- [`docs/adoption.md`](docs/adoption.md) — what a host has to write
- [`docs/runbook.md`](docs/runbook.md) — what breaks and what to do

## Licence

MIT.
