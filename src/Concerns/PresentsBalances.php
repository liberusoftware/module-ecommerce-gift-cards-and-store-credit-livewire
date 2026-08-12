<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Concerns;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider;
use Livewire\Attributes\Locked;

/**
 * The four things both components here need: a live region, an amount in words,
 * an answer to "who is looking", and an answer to "whose shop is this".
 */
trait PresentsBalances
{
    /**
     * What just happened, in words, for the component's live region.
     *
     * A button that dims and a panel that swaps are both invisible to a screen
     * reader. Every interaction says what it did here, and the view renders it
     * inside `role="status"`.
     *
     * Locked because it is announced verbatim: a string the browser could set
     * is a string an attacker could put in the shopper's ear, and on this page
     * that sentence is about whether their gift card has been spent.
     */
    #[Locked]
    public string $announcement = '';

    /**
     * What went wrong, in the shopper's words. Locked for the same reason, and
     * because it is rendered into an assertive alert.
     */
    #[Locked]
    public string $problem = '';

    /**
     * Announcements last exactly one render.
     *
     * A live region announces *changes*, so carrying the previous sentence into
     * the next request would either say nothing new or say it again at the
     * wrong moment. Cleared on hydration, which is every request after the one
     * that set it.
     */
    public function hydratePresentsBalances(): void
    {
        $this->announcement = '';
    }

    /**
     * An amount in words, from integer minor units and nothing else.
     *
     * `Money::decimal()` is string arithmetic — pad, split, concatenate — so
     * `1999` is `19.99` and never `19.990000000000002`. No float touches a
     * balance in this package, which is why there is no `number_format` here.
     *
     * The currency code is shown rather than a symbol. A symbol table is a
     * per-locale problem this package would get wrong, and `GBP 19.99` is never
     * ambiguous about which of the four dollars it means — which matters more
     * here than almost anywhere, because a card denominated in one currency is
     * refused against another rather than converted.
     */
    public function money(Money $amount): string
    {
        return __(GiftCardsAndStoreCreditLivewireServiceProvider::NAMESPACE.'::gift-cards.money', [
            'currency' => $amount->currency,
            'amount' => $amount->decimal(),
        ]);
    }

    /**
     * The customer these components are scoped to, or nobody.
     *
     * Nobody sees nothing: the balances list is empty. That is deliberate and
     * it is silent, which is the safe direction and the thing to know about
     * when a host whose ids are ULIDs finds every list empty — `customer_id` is
     * an integer column in the domain, and an id that is not a whole number
     * cannot be one.
     */
    protected function viewer(): ?int
    {
        return $this->resolveId('viewer', auth()->id());
    }

    /** The team whose liabilities are on screen, or every team. */
    protected function team(): ?int
    {
        return $this->resolveId('team', null);
    }

    /** One message, said once, in the alert and in the live region alike. */
    protected function fail(string $message): void
    {
        $this->problem = $message;
        $this->announce($message);
    }

    protected function announce(string $message): void
    {
        $this->announcement = $message;
    }

    /**
     * Shorthand for this package's translation namespace.
     *
     * @param  array<string, mixed>  $replace
     */
    protected function say(string $key, array $replace = []): string
    {
        return __(GiftCardsAndStoreCreditLivewireServiceProvider::NAMESPACE.'::gift-cards.'.$key, $replace);
    }

    /**
     * A configured resolver's answer as a whole number, or nothing at all.
     *
     * Not `(int) $id`. A ULID casts to 0, and 0 is somebody's row on a database
     * that starts its sequences there — which on this surface would be one
     * customer being shown another's balances.
     */
    private function resolveId(string $key, mixed $fallback): ?int
    {
        $resolver = config('gift-cards-livewire.'.$key);

        $id = is_string($resolver) && $resolver !== '' ? app($resolver)() : $fallback;

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }
}
