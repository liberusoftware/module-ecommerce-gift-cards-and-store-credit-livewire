{{-- The gift card box.

     Two live regions carry everything a shopper who is not looking at the
     screen needs: a `role="alert"` for a refusal, which interrupts, and a
     `role="status"` for an outcome, which waits its turn. Both are on the page
     from the first render rather than appearing with their text — a live region
     inserted at the same moment as its content is not announced by every screen
     reader.

     **The field has no `wire:model`, and that is the whole design.** A gift card
     code is a bearer credential; a `wire:model` property would put it in the
     component's state, in the dehydrated snapshot, and back into the page on
     every subsequent render. Instead the value lives in an Alpine variable in
     the browser and reaches the server exactly once, as an argument to
     `apply()`, which never assigns it to anything. It is cleared client-side the
     moment it is spent. --}}
@php($redeemable = $this->redeemable())
@php($account = $this->applied())
@php($field = 'gift-card-code-'.$this->getId())

<div data-gift-card-control>
    <h2>{{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.heading') }}</h2>

    <div role="alert" data-gift-card-alert>
        @if ($problem !== '')
            <p data-gift-card-problem>{{ $problem }}</p>

            {{-- Shown for every refusal alike. There is exactly one refusal
                 message on this surface and this is the only elaboration of it,
                 so no wording here can tell a guesser which of the eight things
                 that could have gone wrong actually did. --}}
            <p data-gift-card-help>
                {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.refused_help') }}
            </p>
        @endif
    </div>

    <p role="status" aria-live="polite">
        <span wire:loading data-gift-card-loading>{{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.loading') }}</span>
        <span data-gift-card-announcement>{{ $announcement }}</span>
    </p>

    @if ($unconfigured)
        {{-- No field at all. A box that took a credential and dropped it would
             be worse than no box. --}}
        <p data-gift-card-unconfigured>{{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.unconfigured') }}</p>
    @elseif ($applied)
        {{-- No amount here. What was spent is a fact about a movement and this
             component holds no money value to re-render it from; the live region
             said it once, on the request that spent it. --}}
        <p data-gift-card-applied>
            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.'.($replayed ? 'confirmed_replayed' : 'confirmed')) }}
        </p>

        {{-- The two things a bearer credential may ever be shown back as: the
             last four characters, and what is left. The balance is folded from
             the ledger on this render rather than carried on the component. --}}
        <p data-gift-card-card>
            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.card', ['last_four' => $lastFour]) }}
        </p>

        @if ($account)
            <p data-gift-card-remaining>
                {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.remaining', [
                    'amount' => $this->money($account->state->balance()),
                ]) }}
            </p>
        @endif
    @elseif ($redeemable)
        <p data-gift-card-due>
            {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.due', [
                'amount' => $this->money($redeemable->amount),
            ]) }}
        </p>

        <form
            x-data="{ code: '' }"
            x-on:submit.prevent="await $wire.apply(code); if ($wire.applied) code = ''"
            data-gift-card-form
        >
            <label for="{{ $field }}">
                {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.label') }}
            </label>

            {{-- No `wire:model`, no `name` a form post could carry, and not a
                 password field: a shopper is reading this off a piece of card
                 and needs to see what they typed. `autocomplete="off"` keeps it
                 out of the browser's saved form data, which is the one place a
                 code could persist that this package can do anything about. --}}
            <input
                type="text"
                id="{{ $field }}"
                x-model="code"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                aria-describedby="{{ $field }}-hint"
                data-gift-card-code
            >

            <p id="{{ $field }}-hint">
                {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.hint') }}
            </p>

            {{-- The courtesy, not the guarantee: `wire:loading.attr` covers one
                 browser mid-request and does nothing for a reload. The entry key
                 derived at mount is what makes the second submit safe. --}}
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="apply"
                data-gift-card-submit
            >
                <span wire:loading.remove wire:target="apply">
                    {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.button') }}
                </span>
                <span wire:loading wire:target="apply">
                    {{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.applying') }}
                </span>
            </button>
        </form>
    @else
        <p data-gift-card-closed>{{ __('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.closed') }}</p>
    @endif
</div>
