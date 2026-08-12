<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Concerns\PresentsBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Queries\GiftCardQuery;
use Livewire\Component;

/**
 * **What a signed-in customer has, without anybody typing a credential.**
 *
 * Store credit is granted to a person and redeemed because the caller knows who
 * is asking; a gift card the shop knows the recipient of is the same read. So
 * this component answers "what is on my balances" the way it should be
 * answered — from `customer_id`, to somebody who has already proved they are
 * that customer — rather than from a code.
 *
 * ## This is the answer to "why is there no balance-check box"
 *
 * Every gift card page in the world has a *check your balance* field, and this
 * package deliberately does not ship one.
 *
 * The domain publishes no `byCode()` read, and says why: "lookup by code exists
 * to spend a card, not to find one". A balance box built here would have to
 * reimplement that lookup — the peppered index, the uniform-cost verification
 * that stops the clock being an oracle, its own rate limiter, its own identical
 * refusals — which is a second code path over the same credential, kept in
 * step with the first by nothing but attention. And what it would buy is a
 * feature whose entire purpose is to answer *is this code real, and what is on
 * it*, which is the sentence the whole module is built to refuse to say.
 *
 * So: a customer sees their balances here, having signed in. A bearer sees a
 * card's remaining balance at the moment they spend it, on {@see ApplyGiftCard}.
 * Nobody gets to ask a question the answer to which is only useful to somebody
 * who does not already have the card.
 *
 * ## Nothing here is writable
 *
 * There is no action on this component at all. Nothing a shopper can do to a
 * ledger is legitimate — the balance is a fold, `expires_at` is written once and
 * never edited, and disabling is terminal and staff work. A component with no
 * write path is the cheapest possible way to say that, and the reflection test
 * over public properties covers what is left.
 */
class MyBalances extends Component
{
    use PresentsBalances;

    /**
     * The customer's own accounts, folded fresh.
     *
     * Scoped by `customer_id` and, when the deployment names a team resolver, by
     * team. A viewer of nobody gets nothing rather than everything: the guard
     * fails closed and it does so silently, which is the right direction and the
     * thing to check first when a list is unexpectedly empty.
     *
     * @return Collection<int, AccountData>
     */
    public function accounts(): Collection
    {
        $viewer = $this->viewer();

        if ($viewer === null) {
            return new Collection();
        }

        return app(GiftCardQuery::class)->forCustomer($viewer, $this->team());
    }

    public function render(): View
    {
        return view(GiftCardsAndStoreCreditLivewireServiceProvider::NAMESPACE.'::livewire.balances');
    }
}
