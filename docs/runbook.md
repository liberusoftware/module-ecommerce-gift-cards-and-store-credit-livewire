# Runbook

What breaks, what it looks like, and what to do about it.

---

## 1. "This shop cannot take gift cards yet"

**Symptom.** The apply control renders that sentence and no field.

**Cause.** `gift-cards-livewire.redeemable` is unset, or names a class the
container cannot build.

**Why it says that to a shopper.** It is not their fault and there is nothing for
them to fix, so they are told plainly rather than shown a stack trace. And the
field is not rendered at all: a box that accepted a bearer credential and then
dropped it would be worse than no box.

**Fix.** `docs/adoption.md` §2. Set the resolver, and check it is invokable as
`__invoke(string $reference): ?Redeemable`.

---

## 2. A customer's balances list is empty and should not be

Three causes, in the order to check them. All three fail **closed and silently**,
which is the right direction and the reason this section exists.

1. **The viewer resolved to nobody.** `customer_id` is an integer column in the
   domain. If your users are keyed by ULID or UUID, `auth()->id()` is not a whole
   number, and a non-numeric id resolves to *nobody* rather than being cast — a
   ULID casts to `0`, and `0` is somebody's row on a database that starts its
   sequences there. Set `GIFT_CARDS_LIVEWIRE_VIEWER` to a resolver that returns
   the integer customer id.
2. **The team filter is on and wrong.** `GIFT_CARDS_LIVEWIRE_TEAM` filters the
   list. A resolver answering the wrong team, or answering with a non-numeric id,
   shows nothing.
3. **The accounts have no team.** An account with a null `team_id` is invisible to
   everybody, including whoever created it — the domain's `scopeOfTeam()` refuses
   a null rather than translating it to `is null`, which would list exactly the
   orphans the policy denies. The domain's runbook has the query that finds them.

---

## 3. "That gift card cannot be used for this purchase" and the customer insists it is fine

**This is the expected cost of the design, and the design is right.** The shopper
is told one sentence for ten different failures, on purpose: any more specific
answer confirms to a guesser that a code is real.

**So support has to look it up, and support has the tools the shopper does not.**

The truth is in your logs, not on the page. With the domain's telemetry on
(`GIFT_CARDS_TELEMETRY=true`), every refusal writes a `RefusalReason`:

| Reason | What to tell the customer |
| --- | --- |
| `unknown` | The code was not recognised at all. Check for a transcription error, or whether the card predates your migration off the old table |
| `hash_mismatch` | **Escalate.** The index matched and the hash did not: a rotated pepper without a re-index, or a row written by hand. The domain's runbook §5 |
| `disabled` | The card was stopped. Find out who stopped it and why before reissuing |
| `expired` | Past its date. **The balance is still theirs.** Issue a replacement and move the balance — there is no path that edits an expiry |
| `currency_mismatch` | The card is denominated in another currency. It is refused rather than converted, deliberately. Convert outside the module or issue a card in the right currency |
| `insufficient_balance` | Less on the card than the purchase costs. This surface cannot part-pay — see `docs/domain.md` §4 |
| `invalid_amount` | The purchase priced to zero or less. A pricing bug on your side |
| `throttled` | They have hit one of the two limits. Wait a minute. §4 below |

The most valuable of these is a burst of `unknown` against one presenter, which
is what somebody working through the code space looks like. Alert on it — the
domain's `docs/adoption.md` §5 has the listener.

---

## 4. Legitimate customers are being throttled

**Symptom.** Refusals from customers whose cards are fine, often from one place —
an office, a school, a campus, a mobile network.

**Cause.** The address bucket. Twenty attempts a minute is roughly a busy office,
and everybody behind one NAT shares it.

**Check.** The limits are in `docs/adoption.md` §4. The address counter is
`ecommerce-gift-cards-livewire:apply:ip:<sha256 of the address>` in your cache;
the actor counter is the domain's, keyed on the same shape.

**Fix, in order of preference.**

1. Raise `GIFT_CARDS_LIVEWIRE_IP_MAX_ATTEMPTS`. This is the intended knob.
2. Check you are behind a proxy that sets `X-Forwarded-For` and that
   `TrustProxies` is configured. If it is not, **every customer shares one
   address** — your load balancer's — and the limit is effectively global. This
   is the most common cause of this ticket and it is invisible until somebody
   asks.
3. Do **not** lower the actor limit to compensate. It is the tighter of the two
   already.

**Never switch either off.** Without a limit the box is a brute-force endpoint.
Nonsense configuration falls back to the defaults rather than to no limit, for
this reason.

---

## 5. More than one application server, and the limits feel wrong

Both buckets use the cache. A per-node `array` or `file` cache multiplies every
limit by the number of nodes, quietly and in the wrong direction: four nodes
means eighty attempts a minute per address, not twenty.

Use a shared store — Redis, Memcached, DynamoDB — for `cache.default`, and check
after any autoscaling change.

---

## 6. A card was debited twice

**It should not be reachable through this package**, and the first job is to find
out how it happened.

What protects it: the entry key is derived from the host's reference
(`gift-card-apply:<reference>`), so a reload of the same step reuses it and the
domain replays rather than recording. `ecommerce_gift_card_entries.entry_key` is
unique, so two workers racing both insert and the database picks a winner.

So a genuine double debit means one of:

1. **Two different references for one purchase.** Your checkout minted a new
   reference — a restarted checkout, a re-created basket — and the two steps are
   genuinely two steps as far as this package can tell. Look at
   `source_reference` on the two entries. This is the most likely cause by a
   distance.
2. **Something other than this component spent the card**, with its own key: a
   till, an API, an import, a job.
3. **A raw `DB::table()` write.** The domain names this as the one hole none of
   its three append-only layers closes.

The remedy is never a deletion. `RecordCredit` with `CreditOrigin::Reversal`,
carrying the `source_reference` of the redemption it undoes — a new entry, which
is what an append-only ledger means.

---

## 7. A shopper says their card was spent but the order did not go through

The debit happened when the movement was recorded; there is no reservation to
release, deliberately (the domain's `docs/domain.md` §4.4 has the argument — a
hold needs a sweeper, and a sweeper that stops running locks a customer's balance
with nobody to complain to).

So the money is on the ledger and the remedy is a reversal, exactly as above.
Find the entry by `source_reference` — it is the reference the component was
mounted with, or whatever your resolver returned as `sourceReference`.

---

## 8. A code turned up somewhere it should not have

**Treat it as a compromised credential**: the card is spendable by whoever read
it.

This package is built so that cannot happen through it, and `SecurityTest` proves
it every run — the code is never a property, never in the snapshot, never in the
session, never in an error bag, never in a log line even with telemetry on, and
never in the rendered page. So look outside it:

1. **A published view with `wire:model` added to the field.** This is the way it
   happens. `docs/adoption.md` §5.3.
2. **A host resolver that logs its input**, or a global request logger capturing
   Livewire's update payload — the code *is* in that one request body, because
   that is how it arrives.
3. **The old `gift_cards.code` column**, if the domain's migration was not
   finished. Its adoption doc §1.
4. **A browser's saved form data.** The field is `autocomplete="off"`, which is
   the one place this package can do anything about.

Stop the card (`DisableAccount` — terminal, no inverse), issue a replacement, and
move the balance. There is no way to change a code: the card *is* its code.
