<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RedeemByCode;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerConflict;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerInFlight;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\RedemptionRefused;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Concerns\PresentsBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data\Redeemable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Support\PresenterLimit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Queries\GiftCardQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * **The box a shopper types a gift card code into.**
 *
 * The only surface in this fleet where hostile input meets a bearer credential.
 * Everything below follows from that sentence.
 *
 * ## The code is an argument, not a property
 *
 * `#[Locked]` goes on every public property here with no exceptions list, which
 * leaves an obvious question: the code *is* a client input, so how is it not a
 * client-writable property?
 *
 * It is not a property at all. {@see apply()} takes it as a **method argument**,
 * where it lives for one stack frame and is then out of scope. The field in the
 * view has no `wire:model`; it is bound to an Alpine-local variable which the
 * form clears on submit, and the value reaches the server exactly once, as a
 * call parameter.
 *
 * That is not a way around the rule, it is the rule taken seriously. `#[Locked]`
 * protects a value the *server* put on the component from being changed by the
 * browser between renders. An argument has no such value to protect: it is
 * hostile on arrival, by design, and it is never round-tripped. The exceptions
 * list the checkout package keeps for the fields a shopper legitimately types
 * would, on this component, be a list containing a bearer credential — and the
 * property it named would be dehydrated straight back into the page. So there
 * is no list, and this is the mechanism that made one unnecessary.
 *
 * ## The code does not survive the request
 *
 * Not on a property, locked or otherwise. Not in a flash message. Not in a
 * validation error — there is no validation, because "that code is the wrong
 * shape" is an answer a guesser can use and a `$errors` bag is a session write.
 * Not in a log line: this component logs nothing, and the reason a refusal
 * happened is carried to the operator by the domain's own `RedemptionFailed`
 * event, which has never carried a code. Not in the payload that comes back:
 * the only things this component holds about a spent card are its **last four
 * characters** and the reference of the account, and the balance is folded
 * fresh from the ledger on render.
 *
 * `SecurityTest` walks every property of the component after a successful check
 * — public, protected and private — plus the rendered HTML, the session and the
 * log, and asserts the code is in none of them.
 *
 * ## Every refusal is the same sentence
 *
 * | What actually happened | What the shopper is told |
 * | --- | --- |
 * | No card has that code | *That gift card cannot be used for this order.* |
 * | The card is disabled | *…the same sentence…* |
 * | The card has expired | *…the same sentence…* |
 * | The card is in another currency | *…the same sentence…* |
 * | The card has less on it than this costs | *…the same sentence…* |
 * | Its balance is zero | *…the same sentence…* |
 * | This actor or this address is rate limited | *…the same sentence…* |
 * | A different card was already applied to this order | *…the same sentence…* |
 *
 * Anything else confirms a guess. "That card has expired" tells a guesser the
 * code is real; "not enough left" tells them that and roughly what is on it.
 * `RedemptionRefused` carries `->reason` for the operator and this component
 * never reads it.
 *
 * **The shape is uniform too, not just the sentence.** Every refusal leaves the
 * component in one state — nothing applied, no last four, no reference — so the
 * dehydrated payload of a wrong guess is byte-identical to the payload of a
 * disabled card. A test asserts that across every reason there is.
 *
 * ## The idempotency key is derived from the step, not drawn at random
 *
 * Minted once, in {@see mount()}, held on a `#[Locked]` property for the
 * component's life, and sent unchanged on every retry — the fleet rule. What is
 * different here is that it is **derived from the host's reference** rather than
 * being a fresh UUID, and that is deliberate.
 *
 * Payment Operations mints a random key and catches the reload case by reading
 * the ledger for the order at mount. This package cannot do that: the ledger is
 * indexed by card, the card is identified by a code, and there is no code until
 * somebody types one. A random key would therefore mean a shopper who applies a
 * card, reloads, and applies it again debits it **twice** — and gift cards are
 * exactly the instrument people reload a checkout with.
 *
 * Deriving the key from the reference makes the second attempt a replay: the
 * domain finds the entry, compares the facts, sees the same card and the same
 * amount, and returns the first movement with `recorded: false`. No second
 * debit, no second event.
 *
 * ### A conflict refuses and mints nothing
 *
 * If the facts differ — a *different* card under the same reference, or the same
 * card after the basket total moved — the domain raises `LedgerConflict`. This
 * is the Payment Operations shape and not the Checkout one: a fresh key would
 * debit a second card for the same order, which is somebody's money spent
 * because a page was reloaded. So it refuses, in the same sentence as every
 * other refusal, and the shopper starts the step again.
 *
 * ## The amount comes from the server, and the card is spent for it exactly
 *
 * There is **no amount property on this class**, locked or otherwise. The host
 * prices the reference on the request that spends. The domain refuses rather
 * than clamping, so a card with less on it than the basket costs is refused —
 * split tender across a part-used card is not something this surface can do,
 * because doing it would require reading a balance from a code, and that read is
 * an oracle. `docs/domain.md` §4 has the whole argument and the upgrade path.
 */
class ApplyGiftCard extends Component
{
    use PresentsBalances;

    /**
     * The host's handle on what is being paid for.
     *
     * Locked, because the amount and the idempotency key are both derived from
     * it. A browser that could swap it could spend this card against a cheaper
     * basket, or under another basket's key.
     */
    #[Locked]
    public string $reference = '';

    /**
     * The key this movement will be recorded under — derived once, on mount,
     * and unchanged for every retry after it, including after a reload.
     *
     * Locked twice over: a browser that could set it could debit the same card
     * twice under two keys, which is the double spend this component exists to
     * prevent.
     */
    #[Locked]
    public string $entryKey = '';

    /** True once a card has been spent against this reference. */
    #[Locked]
    public bool $applied = false;

    /**
     * True when the domain replayed the movement rather than making one — the
     * shopper's second click, or their reload.
     */
    #[Locked]
    public bool $replayed = false;

    /**
     * The last four characters of the card that was applied.
     *
     * One of exactly two things this component may show back about a bearer
     * credential, the other being the balance. It comes from the domain's own
     * `last_four` column, which is what that column is for.
     */
    #[Locked]
    public string $lastFour = '';

    /**
     * The domain's reference for the account that was applied, so the remaining
     * balance can be folded fresh on later renders.
     *
     * A reference is not a credential — nothing in this package accepts one from
     * a browser, and the domain's only reference-addressed writes are the
     * staff-gated ones this package does not expose. It is held rather than the
     * balance itself so that no money value is ever a property.
     */
    #[Locked]
    public string $appliedReference = '';

    /** True when the deployment has named no way of pricing a reference. */
    #[Locked]
    public bool $unconfigured = false;

    /** Resolved for the length of one request, and never serialised. */
    private ?Redeemable $resolved = null;

    private ?AccountData $account = null;

    public function mount(string $reference): void
    {
        $this->reference = $reference;

        $resolver = config('gift-cards-livewire.redeemable');

        if (! is_string($resolver) || $resolver === '') {
            // A deployment that has not said how a reference becomes an amount
            // cannot spend a gift card against it. Said as something the shopper
            // can act on rather than as a stack trace, because it is not their
            // fault — and the view renders no field at all in this state, so
            // there is nowhere to type a credential into a page that would only
            // drop it.
            $this->unconfigured = true;
            $this->fail($this->say('apply.unconfigured'));

            return;
        }

        // A reference that prices to nothing is answered exactly as one that was
        // never issued. The difference between the two is information about
        // somebody else's basket.
        if ($this->redeemable() === null) {
            abort(404);
        }

        // **The line the reload case turns on.** Derived, not drawn: a fresh
        // component for the same step holds the same key, so applying the same
        // card again replays the first movement instead of making a second one.
        $this->entryKey = self::keyFor($reference);
    }

    /**
     * What this step is worth, priced on the server, on this request.
     *
     * A method rather than a property, so there is nothing to dehydrate and
     * nothing to tamper with, and so a total that moved between mount and submit
     * is the total the ledger is asked for.
     */
    public function redeemable(): ?Redeemable
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolver = config('gift-cards-livewire.redeemable');

        if (! is_string($resolver) || $resolver === '') {
            return null;
        }

        $redeemable = app($resolver)($this->reference);

        return $this->resolved = $redeemable instanceof Redeemable ? $redeemable : null;
    }

    /**
     * The card that was applied, folded from the ledger, or nothing.
     *
     * Read fresh rather than held, for the same reason the domain has no balance
     * column: a number carried on a component is a second copy of an answer that
     * already exists, and the two disagree the moment anything else touches the
     * card.
     */
    public function applied(): ?AccountData
    {
        if ($this->appliedReference === '') {
            return null;
        }

        return $this->account ??= app(GiftCardQuery::class)->byReference($this->appliedReference);
    }

    /**
     * **Spend the card the shopper is holding, for what the server says this
     * step costs.**
     *
     * `$code` is a bearer credential arriving from a browser. It is an argument
     * rather than a property so that it is never component state, never
     * dehydrated and never rehydrated; it is passed to the domain and goes out
     * of scope. Nothing in this method writes it anywhere, and every exit from
     * it says the same sentence.
     */
    public function apply(string $code = ''): void
    {
        $this->problem = '';

        // Terminal once a card is on this step, and this is a security stop
        // rather than a courtesy. After a success the domain would answer a
        // *valid* second code with `LedgerConflict` and an invalid one with a
        // refusal — two different exceptions for two different truths, which is
        // an oracle even though both are rendered identically. Not calling it at
        // all closes that from in front.
        if ($this->applied) {
            $this->fail($this->say('apply.already'));

            return;
        }

        if ($this->unconfigured) {
            $this->fail($this->say('apply.unconfigured'));

            return;
        }

        $redeemable = $this->redeemable();

        if ($redeemable === null) {
            // About the step, not about the card: constant for every code, so it
            // tells a guesser nothing they did not already know from the page.
            $this->fail($this->say('apply.closed'));

            return;
        }

        $code = trim($code);
        $ip = (string) request()->ip();

        // An empty box is not a guess. Refused here without calling the domain
        // and without counting against either bucket, so a shopper who presses
        // the button before typing has not spent one of their five attempts.
        if ($code === '') {
            $this->refuse();

            return;
        }

        // The address bucket, checked before the actor bucket the domain owns.
        // Not counted against a presenter who is already being told to wait:
        // extending their window every time they ask turns a one-minute lockout
        // into an unbounded one.
        if (PresenterLimit::exceededForIp($ip)) {
            $this->refuse();

            return;
        }

        try {
            $result = app(RedeemByCode::class)->handle(new RedemptionInput(
                code: $code,
                entryKey: $this->entryKey,
                // Server-side, every time. There is no property this could have
                // come from.
                amount: $redeemable->amount,
                // Required, and refused by the domain if empty. The person, not
                // the browser tab.
                throttleKey: PresenterLimit::actorKey(),
                sourceReference: $redeemable->sourceReference ?? $this->reference,
            ));
        } catch (RedemptionRefused|LedgerConflict|LedgerInFlight) {
            // **One catch, three classes, one answer.** Elsewhere in this fleet
            // a permanent conflict and a transient in-flight claim are opposite
            // instructions to a caller and are told apart by `instanceof`. Here
            // they are told apart by nobody: reaching either requires having
            // presented a *real* code, so a caller that could distinguish them
            // would have a confirmation oracle worth more to a guesser than the
            // retry advice is worth to a shopper. The domain still knows which
            // is which, and its telemetry still records it.
            //
            // What protects the double click is not a distinguishing message, it
            // is the key derived at mount and the button that disables itself.
            PresenterLimit::hitIp($ip);

            $this->refuse();

            return;
        }

        $this->applied = true;
        $this->replayed = ! $result->recorded;
        $this->lastFour = (string) $result->account->lastFour;
        $this->appliedReference = $result->account->reference;
        $this->account = $result->account;

        $this->announce($this->say($this->replayed ? 'apply.replayed' : 'apply.applied', [
            'amount' => $this->money($result->entry->amount),
            'last_four' => $this->lastFour,
        ]));

        // The host's own reference and nothing else: enough for a checkout to
        // re-total itself, and it names no card. Anything richer is the domain's
        // `GiftCardRedeemed`, which a replay deliberately does not dispatch.
        $this->dispatch(
            GiftCardsAndStoreCreditLivewireServiceProvider::NAMESPACE.'.applied',
            reference: $this->reference,
        );
    }

    public function render(): View
    {
        return view(GiftCardsAndStoreCreditLivewireServiceProvider::NAMESPACE.'::livewire.apply');
    }

    /**
     * The key a given step's movement is recorded under.
     *
     * Public and static so a host can prove to itself that a reload reuses it,
     * and so the derivation is one line somebody can read rather than a
     * convention spread across a component.
     */
    public static function keyFor(string $reference): string
    {
        return 'gift-card-apply:'.$reference;
    }

    /**
     * The one refusal, said the one way.
     *
     * Every caller above funnels through here rather than each choosing its own
     * message, because the day somebody adds a more helpful sentence for one
     * case is the day the box becomes an oracle. There is nothing to pass in.
     */
    private function refuse(): void
    {
        $this->fail($this->say('apply.refused'));
    }
}
