# The domain

What this package presents, and every decision behind it. Written for somebody
who has to change it, or who has to decide whether a bug is a bug.

The domain package it presents —
`liberusoftware/ecommerce-gift-cards-and-store-credit` — has its own
`docs/domain.md`, and it is the one that decides what a gift card *is*. Read that
first. This document is only about the surface.

---

## 1. The one sentence this package exists for

**A shopper types a bearer credential into a box on a public page.**

That is the whole job, and everything below follows from it. There is nowhere
else in this fleet where hostile input meets a credential that *is* money — a
password is checked against an account somebody owns, a card number goes to a
provider, an API token belongs to a caller who was issued it. A gift card code
belongs to whoever is holding it, and the box will accept a guess as readily as
a customer.

So the box is treated as an attack surface that happens to also be a feature.

---

## 2. The code, and how it is reconciled with `#[Locked]`

The fleet rule for a `-livewire` package is **`#[Locked]` on every public
property**, enforced by reflection over every registered component. A writable
public property is a client-controlled input, and this package holds none.

Which leaves the obvious question. The code is *unarguably* a client input — the
shopper types it — so how is it not a writable property?

### It is not a property at all

`ApplyGiftCard::apply(string $code)` takes it as a **method argument**. There is
no `code` property on the component, `wire:model` appears nowhere in the view,
and the field is bound to an Alpine-local variable which the form clears once the
card is spent. The value crosses the wire exactly once, as a call parameter, and
lives for one stack frame.

That is not a way around the rule. It is the rule taken seriously, and the
distinction is worth being precise about:

| | A `#[Locked]` property | An action argument |
| --- | --- | --- |
| Where the value comes from | the **server** set it | the **browser** sent it |
| What the attribute protects | the browser changing it back | nothing — it is hostile by definition |
| How long it lives | every render, in every snapshot | one stack frame |
| What a leak costs | it is in the page source forever | it is not written down at all |

`#[Locked]` protects a value the server put on a component from being tampered
with between renders. An argument has no such value to protect: it arrives
hostile, is used, and is gone. Locking a property is how you say *the browser may
not decide this*; not having a property is how you say *the browser may not
keep this*. On a bearer credential the second is the stronger statement, and the
first is not available anyway — a locked property is still dehydrated into the
snapshot on every single render, which is precisely the outcome to avoid.

### Why the checkout package's exceptions list would have been wrong here

`ecommerce-checkout-livewire` keeps a short list of properties a shopper may
write — their email, their address, a discount code — because those are things a
shopper legitimately types and legitimately sees echoed back.

An exceptions list on **this** component would have contained exactly one entry,
and that entry would have been a bearer credential. It would then have been the
place the *next* one was added. There is no list, and the argument mechanism is
what made one unnecessary rather than merely discouraged. `SecurityTest` asserts
the absence by reflection with no allowlist to consult.

### What the code is allowed to leave behind

Two things, and they are the two the domain publishes for exactly this purpose:

- **`last_four`**, held on a `#[Locked]` property, because a receipt saying *the
  card ending 4F2K* is how a customer knows which of their cards was spent.
- **the balance**, folded from the ledger on the render that shows it and never
  held as a property, because no money value is a property here at all.

Everything else is gone by the end of the request. `SecurityTest` proves it by
walking every public property's *value*, the rendered HTML, the session, the
error bag and the log — with the domain's telemetry deliberately switched **on** —
and asserting the code appears in none of them.

---

## 3. Why there is no *check your balance* box

Every gift card page in the world has one. This package does not ship one, and
that is a decision rather than an omission.

The domain publishes no lookup by code except redemption, and says why: *"lookup
by code exists to spend a card, not to find one"*. `GiftCardQuery` has no
`byCode()`.

A balance box here would have to reimplement that lookup — the peppered index,
the bcrypt verification that runs on a miss as well as a hit so the clock is not
an oracle, its own rate limiter, its own identical refusals — which is a second
code path over the same credential, kept in step with the first by nothing but
attention. That is the arrangement the domain refused when it declined to put a
`balance` column next to a ledger: two implementations of one answer are two
answers waiting to disagree.

And what it would buy is a feature whose entire purpose is to answer *is this
code real, and how much is on it* — the one sentence this module is built never
to say to somebody who cannot already spend the card.

So the question is answered twice, in the two places where it is safe:

- **A customer who has signed in** sees their balances on `MyBalances`, from
  `customer_id`, with no code involved.
- **A bearer spending a card** sees what is left on it at the moment they spend
  it, on the same render as the movement.

Nobody gets to ask it who does not already hold the card or the account.

**If a merchant genuinely must have one**, it belongs in the domain package
behind the same throttle, the same uniform cost and the same single refusal —
not here, where it would be a second lookup nobody could keep aligned with the
first.

---

## 4. The amount, and what this surface cannot do

**No money value is ever a client input.** The component is mounted with an
opaque reference and the host prices it, on the server, on the request that
spends. There is no amount property, locked or otherwise, and `SecurityTest`
asserts no property on any component is even *named* for money.

The consequence is worth stating plainly, because it is a real limitation:

**A card with less on it than the purchase costs is refused, not partly spent.**

The domain refuses rather than clamping — "debiting what is there and dropping
the difference turns a loud failure into a basket that is short by an amount
nobody is told about" — and this package cannot ask what is on a card before
spending it, because asking is §3.

So split tender across a part-used gift card is not something this surface does.
The upgrade path, if a merchant needs it, is a domain-published read that answers
*how much of this amount can this code cover* **and nothing else** — a yes/no
against a caller-supplied figure rather than a balance, behind the same throttle
and the same uniform cost, so that it leaks no more than an attempt to spend
already does. That is a domain decision and it is not this package's to invent.

---

## 5. The idempotency key is derived from the step

The fleet rule: mint the key **once, when the step is entered**, hold it on a
`#[Locked]` property for the component's life, and send the same key on every
retry. A key minted at click time defeats the mechanism entirely.

This package does that, and then does one thing differently: the key is
**derived from the host's reference** rather than drawn at random.

```php
'gift-card-apply:'.$reference
```

### Why

Payment Operations mints a UUID and catches the reload case separately, by
reading the ledger for the order at mount and refusing to render a pay button if
money is already against it. **This package cannot do that read.** The ledger is
indexed by card; a card is identified by a code; and there is no code until
somebody types one. At mount there is nothing to look up.

With a random key, a shopper who applies a card, reloads the page and applies it
again gets a second component holding a second key — and the domain, correctly,
records a second movement. The card is debited twice. Gift cards are exactly the
instrument people reload a checkout with.

Derivation makes the second attempt a **replay**: the domain finds the entry
under that key, compares the facts, sees the same card and the same amount, and
returns the first movement with `recorded: false`. No second debit, no second
domain event, and the shopper is told their card was already used and has not
been used twice.

It also satisfies the original rule more strictly than a UUID does. "Minted once
when the step is entered" is a statement about *not* minting one per click; a key
that is stable across reloads of the same step is that property extended, not
weakened. Nothing about it is client-controlled: it is derived from a `#[Locked]`
reference, and a browser that could set either is refused by Livewire.

### A conflict refuses and mints nothing

If the facts differ — a **different** card under the same reference, or the same
card after the basket total moved — the domain raises `LedgerConflict`.

This is the Payment Operations shape and not the Checkout one, and the
presentation brief is explicit that the choice has to be reasoned rather than
copied. In Checkout, a conflict means nothing was committed under the key, so a
fresh key is safe. **Here it means the opposite**: a movement exists under that
key against a card, so minting a fresh key would spend a *second* gift card for
one purchase. Somebody's money, gone, because a page was reloaded.

So it refuses, and it refuses in the same sentence as everything else.

---

## 6. Every refusal, one sentence and one shape

| What actually happened | What the shopper is told |
| --- | --- |
| No card has that code | *That gift card cannot be used for this purchase.* |
| The code is not even the right shape | the same sentence |
| The card has been stopped | the same sentence |
| The card has passed its date | the same sentence |
| The card is denominated in another currency | the same sentence |
| There is less on the card than this costs | the same sentence |
| There is nothing on the card | the same sentence |
| This actor has spent their five attempts | the same sentence |
| This address has spent its twenty | the same sentence |
| A different card is already on this purchase | the same sentence |

Anything more specific confirms a guess. "That card has expired" tells whoever
typed it that the code is **real**; "not enough left" tells them that *and*
roughly what is on it. Three of those answers are worse than "unknown" and one is
worse again.

`RedemptionRefused` carries `->reason` for the operator, the domain dispatches
`RedemptionFailed` with it, and its telemetry writes it. **This component never
reads it.**

### The shape is uniform too

One message is not enough on a Livewire surface, because what a browser actually
receives is the component's dehydrated state. So every refusal leaves the
component in one state — nothing applied, no last four, no reference, the same
two sentences in the same two properties — and `UniformityTest` asserts the whole
snapshot is equal across every one of the eleven ways above.

### Three things that are *not* the same sentence, and why that is safe

`apply.unconfigured`, `apply.closed` and `apply.already` say something different.
All three are facts about the **deployment or the step**, not about the card:
they answer identically for every code, including codes that do not exist, so
they confirm nothing. The test for that is that none of them is reachable from a
state where the code influenced the answer.

### Permanent and transient are deliberately *not* told apart

Elsewhere in this fleet a permanent conflict (`409`) and a transient in-flight
claim (`423`) are opposite instructions to a caller and are told apart by
`instanceof`, never by decoding a message. Here they are told apart by nobody.

Reaching either requires having presented a **real** code, so a caller that could
distinguish them would have a confirmation oracle worth far more to a guesser
than the retry advice is worth to a shopper. What protects the double click is
the key derived at mount and the button that disables itself — not a
distinguishing message. The domain still knows which is which and still records
it.

---

## 7. Two rate limits, because either alone is walked around

| Bucket | Keyed by | Enforced by | Default |
| --- | --- | --- | --- |
| Actor | signed-in customer, or the guest's session | the **domain** | 5 / minute |
| Address | `request()->ip()` | **this package** | 20 / minute |

The domain refuses an empty `throttleKey` and will not invent one, because it
cannot see a request. This package can, so this is where the question is
answered.

**The actor bucket is not reimplemented here.** `RedeemByCode` already limits on
whatever key it is handed, and a second counter over the same thing would be two
answers to one question. What this package adds is the bucket the domain
*cannot* key, because a session id and an IP address are both request-scoped
facts.

**Why both.** A per-session limit is five attempts per cookie jar, and a script
mints cookie jars faster than it guesses codes. An address is not free to rotate.
It is also not free to *share* — an office, a school and a mobile carrier are
each one address — so the address limit is deliberately the looser of the two: a
limit that locks out a building is a limit an operator switches off.

Three details that are each a decision:

- **A success clears the actor's counter and not the address's.** A customer who
  mistypes four times and then gets it right is not locked out by their own card.
  A shared address, though, is exactly where a guesser sits behind other people's
  successes, so one valid card must not buy back the budget for the building.
- **A throttled attempt is not counted.** Extending somebody's window every time
  they ask turns a one-minute lockout into an unbounded one, which is the domain's
  own rule one bucket over.
- **An empty box costs nothing.** Pressing the button before typing is not a
  guess, and a shopper who does it twice has not spent two of their five.

Both keys are hashed into the cache key. An id or an address sitting verbatim in
a shared cache is a small leak into a store usually less guarded than the
database.

---

## 8. Scope: two components, and what was left out

| Component | Alias | What it is |
| --- | --- | --- |
| `ApplyGiftCard` | `…::apply` | the box, and the only path here that moves money |
| `MyBalances` | `…::balances` | a signed-in customer's own accounts, folded |

Everything else a gift card system does is **staff work and belongs in
`-filament`**, per the presentation brief's rule that anything moving somebody
else's money, or serving a reconciliation queue, is an operator surface:

- **Issuing** a card, and delivering the code, which exists exactly once on the
  call that mints it.
- **Adjusting** a balance — the only path where a human's decision enters the
  ledger, and the ability to grant to the fewest people.
- **Disabling** a card, which is terminal and has no inverse.
- **The reconciliation queue** over `GiftCardQuery::needingReconciliation()`.
- **Expiry reminders** over `expiringBefore()`, which is a job rather than a
  page.

`ApplyTest` asserts the absence: no source file here mentions `IssueAccount`,
`RecordAdjustment`, `RecordCredit` or `DisableAccount`.

`MyBalances` has **no action at all** — two public methods, `accounts()` and
`render()`, asserted by reflection. Nothing a shopper can do to a ledger is
legitimate: the balance is a fold, `expires_at` is written once and never edited,
and stopping a card is terminal. A component with nothing to call is the cheapest
possible way to say that.

---

## 9. What is shown, and what expiry looks like to a customer

An **expired card still shows its balance**, and that is not a bug. Expiry ends
redeemability, never the money: nothing is zeroed, no entry is written, and the
number is the same one it was the day before. The balances list says the card
cannot be spent and that the balance is still theirs, because many jurisdictions
regulate or forbid expiry and a surface that quietly showed zero would be this
package deciding a law it does not know.

A **stopped card** says so plainly, with the same balance still visible, for the
same reason.

A card's **code** is nowhere on either page, in any form. There is nothing to
print: it is not in the database in any recoverable form.

---

## 10. Things that will surprise somebody

- **There is no balance-check box, and that is §3.**
- **A card short of the total is refused outright**, not partly spent. §4.
- **The idempotency key is not a UUID.** §5, and it is why a reload is safe.
- **Being rate limited looks exactly like typing a wrong code.** §6.
- **A conflict and an in-flight claim look identical too**, which is the opposite
  of what every other package in this fleet does. §6.
- **`MyBalances` has no way to do anything.** §8.
- **An expired card still shows its money.** §9.
- **The announcement clears itself on the next request.** A live region announces
  changes; carrying the sentence forward would say it again at the wrong moment.
- **The apply control renders no field when the deployment is unconfigured.** A
  box that took a credential and then dropped it would be worse than no box.
