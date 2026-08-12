# Adoption

What a host has to do, and what it has to decide first.

---

## 1. Install

The domain package is not on Packagist, so **your application's own
`composer.json` needs the repository entry as well as this one's**. Composer
honours `repositories` only from the root manifest; the entry in this package
works for its own CI, where it is root, and does nothing for you.

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit-livewire" }
]
```

```bash
composer require liberusoftware/ecommerce-gift-cards-and-store-credit-livewire
```

Nothing boots. The package ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only registrar and only when both modules
are named:

```dotenv
MODULES_ENABLED=…,ecommerce-gift-cards-and-store-credit,ecommerce-gift-cards-and-store-credit-livewire
```

```bash
php artisan vendor:publish --tag=module-ecommerce-gift-cards-and-store-credit-config
```

**Do the domain package's adoption first.** Its `docs/adoption.md` §1 is a
decision about the plaintext codes already in your `gift_cards` table that cannot
be undone, and §3 is a pepper without which nothing here works at all. This
package is useless until both are done.

---

## 2. The one thing you have to write: the pricing resolver

**The amount is never a client input.** The apply control is mounted with an
opaque reference and asks your application what that reference is worth, on the
server, on the request that spends the card.

```php
// config/gift-cards-livewire.php
'redeemable' => \App\Storefront\PriceTheCheckout::class,
```

```php
namespace App\Storefront;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data\Redeemable;

class PriceTheCheckout
{
    public function __invoke(string $reference): ?Redeemable
    {
        $checkout = Checkout::query()->where('reference', $reference)->first();

        // Null for anything this shopper may not price — a checkout that is not
        // theirs, one that is already paid, one that never existed. The
        // component answers all three with a 404, which is the point: the
        // difference between them is information about somebody else's basket.
        if ($checkout === null || ! $checkout->belongsToCurrentSession()) {
            return null;
        }

        return new Redeemable(
            // Integer minor units. Never `(int) ($decimal * 100)` —
            // `(int) (19.99 * 100)` is 1998.
            amount: new Money($checkout->outstanding_minor, $checkout->currency),
            sourceReference: $checkout->reference,
        );
    }
}
```

Three things about that method that are not style:

1. **It authorises.** This package cannot: it does not know what a checkout is or
   who owns one. If your resolver prices any reference it is handed, anybody can
   mount the component on anybody's basket. It still could not *spend* anything
   without holding a code — but it could read a total.
2. **It returns `null` rather than throwing.** Every null is the same 404.
3. **It is called again on the request that spends**, not only at mount. A total
   that moved between the two is the total the ledger is asked for.

Left unconfigured, the control renders **no field at all**. A box that took a
bearer credential and then dropped it would be worse than no box.

---

## 3. Put the components on a page

```blade
{{-- Checkout, next to your other tenders. --}}
<livewire:module-ecommerce-gift-cards-and-store-credit::apply :reference="$checkout->reference" />

{{-- The customer's account area. --}}
<livewire:module-ecommerce-gift-cards-and-store-credit::balances />
```

The apply control dispatches one browser event when a card is spent, carrying the
reference you mounted it with and nothing else:

```js
Livewire.on('module-ecommerce-gift-cards-and-store-credit.applied', ({ reference }) => {
    // Re-total the checkout. This event names no card and carries no amount.
})
```

Anything richer is server-side: the domain dispatches `GiftCardRedeemed` with the
account and the entry, and a replay deliberately dispatches nothing. **Record
your tender from that listener**, not from the browser event — Checkout's
wave-4 model of a gift card is *an amount and a reference*, and the reference
should name a movement that actually happened.

```php
Event::listen(GiftCardRedeemed::class, function (GiftCardRedeemed $event): void {
    (new RecordTender())->handle(
        reference: $event->entry->sourceReference,
        amountMinor: $event->entry->amount->minor,
        kind: TenderKind::GiftCard,
        status: TenderStatus::Captured,
    );
});
```

**If the order then falls over**, put the money back with a reversal — a new
entry carrying the redemption's `source_reference`, never a deletion. The domain's
`docs/adoption.md` §5 has the code.

---

## 4. The two rate limits, and what they are set to

| Bucket | Keyed by | Enforced by | Default | Setting |
| --- | --- | --- | --- | --- |
| Actor | signed-in customer, else session | the **domain** | **5 per minute** | `GIFT_CARDS_MAX_ATTEMPTS`, `GIFT_CARDS_ATTEMPT_DECAY` |
| Address | `request()->ip()` | **this package** | **20 per minute** | `GIFT_CARDS_LIVEWIRE_IP_MAX_ATTEMPTS`, `GIFT_CARDS_LIVEWIRE_IP_DECAY` |

```dotenv
GIFT_CARDS_MAX_ATTEMPTS=5
GIFT_CARDS_ATTEMPT_DECAY=60
GIFT_CARDS_LIVEWIRE_IP_MAX_ATTEMPTS=20
GIFT_CARDS_LIVEWIRE_IP_DECAY=60
```

Both are on by default and neither can be switched off from configuration —
nonsense values fall back to the defaults rather than to no limit, because a
limiter that silently does nothing is worse than none: somebody has already
ticked the box.

Things worth knowing before you tune them:

- **A success clears the actor's counter, not the address's.** A customer who
  mistypes four times and gets it right on the fifth is not locked out by their
  own card. A shared address is where a guesser sits behind other people's
  successes, so one valid card does not buy back the budget for the building.
- **A throttled attempt is not counted**, so a lockout stays one minute rather
  than becoming unbounded for anybody who keeps trying.
- **Raise the address limit before you lower the actor one.** Twenty a minute
  from one address is roughly a busy office; a corporate NAT or a school will hit
  it, and the symptom is a customer being told their perfectly good card cannot
  be used.
- Both buckets use the cache. On more than one application server, **use a shared
  cache store**. A per-node array or file cache multiplies every limit by the
  number of nodes, quietly.

---

## 5. Four rules if you customise anything

Publishing the views and the translations is supported. These four are not
stylistic.

### 5.1 Keep the refusal one sentence

```bash
php artisan vendor:publish --tag=module-ecommerce-gift-cards-and-store-credit-translations
```

`apply.refused` is answered for **ten different failures**: an unknown code, a
malformed one, a stopped card, an expired card, a card in another currency, a
card without enough on it, an empty card, a throttled actor, a throttled address,
and a different card already on the purchase.

If you split it into helpful variants you have turned the box into an oracle. A
bearer told *"that card has expired"* has learned that the code is **real**, which
is the one thing a guesser cannot work out alone. If your wording needs more
room, put it in `apply.refused_help`, which is rendered for every refusal alike.

### 5.2 Never render a reason

`RedemptionRefused::$reason` and `RedemptionFailed::$reason` exist for your log
and your alerting. They must not reach a page. A burst of `RefusalReason::Unknown`
against one presenter is what an enumeration attempt looks like and is the single
most valuable thing here to alert on — the domain's `docs/adoption.md` §5 has the
listener.

### 5.3 Never add `wire:model` to the code field

The field is deliberately bound to an Alpine variable and passed as an **action
argument**. A `wire:model` property would put a bearer credential into the
component's state, into the dehydrated snapshot, and back into the page source on
every subsequent render. `docs/domain.md` §2 is the whole argument.

### 5.4 Never put a code in a URL, a search or a filter

There is no route in this package for the same reason: a URL is an access log, a
referrer header and a browser history at once. A search term and a filter both
persist into the query string. `GiftCardQuery` has no `byCode()` and neither has
anything here.

---

## 6. Who sees what

```dotenv
GIFT_CARDS_LIVEWIRE_VIEWER=   # a class invokable as __invoke(): int|string|null
GIFT_CARDS_LIVEWIRE_TEAM=     # the same shape
```

- **Viewer** defaults to `auth()->id()`. Set it if your customers are not your
  users.
- **Team** defaults to unset, which shows a customer every balance of their own
  whichever of your storefronts sold it. That is not a leak — they are the
  customer on every row — but on a multi-brand host it is one brand showing
  another brand's card. Set it if you run more than one.

**Both fail closed and both fail silently.** A non-numeric answer resolves to
*nobody* rather than to *everybody*, so a ULID-keyed host sees empty lists rather
than somebody else's balances. `docs/runbook.md` §2 names it as the first thing
to check when a list is unexpectedly empty.

---

## 7. What this package deliberately does not do

| | Where it lives instead |
| --- | --- |
| Issue a card, and deliver the code | `-filament`, and the code exists exactly once, on the call that mints it |
| Adjust a balance | `-filament`. The only path where a human's decision enters a ledger |
| Stop a card | `-filament`. Terminal, and there is no inverse |
| The reconciliation queue | `-filament`, over `GiftCardQuery::needingReconciliation()` |
| Expiry reminders | a job of yours over `GiftCardQuery::expiringBefore()` |
| **Check a balance from a code** | **nowhere, on purpose** — `docs/domain.md` §3 |
| Split a purchase across a part-used card | nowhere yet — `docs/domain.md` §4 |
| Credit a card | nowhere here. A refund's destination is decided by the refunds module and recorded by the domain's `RecordCredit`, from a listener you write |
