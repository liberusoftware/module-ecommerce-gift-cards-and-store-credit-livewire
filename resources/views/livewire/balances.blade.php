{{-- A customer's own balances, folded from the ledger on every render.

     There is no field on this page and no action on this component. Nothing a
     shopper can do to a ledger is legitimate, and the cheapest way to say so is
     to ship nothing that could.

     Every balance here is a fold over `ecommerce_gift_card_entries`, so the
     number on the screen cannot disagree with the movements that produced it —
     there is no balance column for it to disagree with. --}}
<div data-gift-card-balances>
    <h2>{{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.heading') }}</h2>

    @php($accounts = $this->accounts())

    @if ($accounts->isEmpty())
        <p data-gift-card-balances-empty>
            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.empty') }}
        </p>
    @else
        <ul>
            @foreach ($accounts as $account)
                <li data-gift-card-account>
                    {{-- The last four characters, never the code. A gift card's
                         code is not in the database in any recoverable form, so
                         this is not a convention about not printing it — there
                         is nothing to print. --}}
                    <h3>
                        {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.kind.'.$account->kind->value) }}
                        @if ($account->lastFour)
                            <span data-gift-card-last-four>{{ $account->lastFour }}</span>
                        @endif
                    </h3>

                    <p data-gift-card-balance>
                        {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.balance') }}:
                        <span data-gift-card-balance-value>{{ $this->money($account->state->balance()) }}</span>
                    </p>

                    <p data-gift-card-status>
                        {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.status.'.$account->state->status()->value) }}
                    </p>

                    @if ($account->expiresAt)
                        <p data-gift-card-expires>
                            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.expires', [
                                'date' => $account->expiresAt,
                            ]) }}
                        </p>
                    @endif

                    {{-- Expiry ends redeemability, never the money. The balance
                         above is still shown, still correct and still theirs,
                         which is the single most important sentence in the
                         domain's expiry decision rendered as a sentence a
                         customer reads. --}}
                    @if ($account->state->expired)
                        <p data-gift-card-expired>
                            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.expired') }}
                        </p>
                    @elseif ($account->state->disabled)
                        <p data-gift-card-disabled>
                            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.disabled') }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Said where somebody would look for the box that is not here. --}}
    <p data-gift-card-no-lookup>
        {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.balances.no_lookup') }}
    </p>
</div>
