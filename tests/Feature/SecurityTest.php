<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Livewire\Attributes\Locked;

/*
 * A writable public property on a shopper-facing component is a client-
 * controlled input. On this component the worst possible client-controlled
 * input is an amount, and the second worst is the thing a client legitimately
 * types — a bearer credential, which is why it is not a property at all.
 *
 * These assert by reflection over every registered component rather than per
 * class, so a component added later is covered before anybody remembers to
 * cover it.
 */

it('locks every public property, with no exceptions list at all', function (string $component) {
    // The checkout package keeps a short list of properties a shopper may write:
    // their email, their address, a discount code. This package keeps none — and
    // the one thing a shopper types here is precisely the thing that must never
    // be on the list, because a property is dehydrated back into the page on
    // every render.
    //
    // The reconciliation is in `docs/domain.md` §2: the code arrives as an
    // argument to `apply()`, which is hostile on arrival by design and is never
    // round-tripped, so there is no server-set value for `#[Locked]` to protect
    // and nothing to except.
    $properties = new ReflectionClass($component)->getProperties(ReflectionProperty::IS_PUBLIC);

    expect($properties)->not->toBeEmpty();

    foreach ($properties as $property) {
        if ($property->isStatic()) {
            continue;
        }

        expect($property->getAttributes(Locked::class))
            ->not->toBeEmpty("{$component}::\${$property->getName()} is writable from the browser.");
    }
})->with(everyComponent());

it('has no property a code could be hydrated into', function (string $component) {
    // Not a naming convention. A property is hydrated from a browser payload and
    // dehydrated back into the page, so a property named for a code is a bearer
    // credential making a round trip on every render — in the snapshot, in the
    // page source, and in anything that caches either.
    foreach (new ReflectionClass($component)->getProperties() as $property) {
        expect($property->getName())->not->toMatch('/(^|_)(code|pin|voucher|secret|token)/i');
    }
})->with(everyComponent());

it('holds no money on any component at all, so there is nothing to lock', function (string $component) {
    // The amount is read from the host's resolver on the request that spends. A
    // `#[Locked]` amount would still be one dehydration bug away from being a
    // number a shopper set, and on this surface the number decides how much of
    // somebody's gift card is spent.
    foreach (new ReflectionClass($component)->getProperties() as $property) {
        expect($property->getName())->not->toMatch('/(minor|price|total|amount|currency|balance)/i');
    }
})->with(everyComponent());

it('keeps the code out of everything that survives the request', function () {
    // Telemetry deliberately **on**. The domain logs every redemption and every
    // refusal, and this asserts that going through this surface does not put a
    // credential into that channel.
    config()->set('gift-cards.telemetry.enabled', true);

    $logged = [];

    // A long closure with a reference, not an arrow function: an arrow function
    // captures by value at definition and would report the empty array it
    // started with.
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    $card = issueCard(5000);
    $code = (string) $card->code;
    $normalised = str_replace('-', '', $code);

    priced(4798);

    $applied = applyTo()->call('apply', $code);

    // 1. Not in the component's dehydrated state, which is what the browser gets
    //    back. Every public property, checked by value rather than by name.
    // `toContain` is variadic, so it takes one needle and never a message.
    foreach (snapshotOf($applied) as $value) {
        expect(json_encode($value))
            ->not->toContain($code)
            ->not->toContain($normalised);
    }

    // 2. Not in the rendered page.
    expect($applied->html())->not->toContain($code)->not->toContain($normalised);

    // 3. Not in the session — no flash, and no validation error either. There is
    //    no validation on this field at all: "that is not the right shape" is an
    //    answer a guesser can use, and an error bag is a session write.
    $applied->assertHasNoErrors();
    expect(json_encode(session()->all()))->not->toContain($code)->not->toContain($normalised);

    // 4. Not in a log line, with the domain's own telemetry turned up.
    expect(implode("\n", $logged))->not->toContain($code)->not->toContain($normalised)
        ->and($logged)->not->toBeEmpty();

    // 5. Not in the database, which is the domain's guarantee rather than this
    //    package's — asserted here because this is the path that would have
    //    written it.
    expect(json_encode(GiftCardAccount::query()->firstOrFail()->getAttributes()))
        ->not->toContain($code)
        ->not->toContain($normalised);

    // And the two things that may be shown back are.
    expect($applied->html())->toContain(substr($normalised, -4))->toContain('GBP 2.02');
});

it('keeps a refused code out of everything too', function () {
    config()->set('gift-cards.telemetry.enabled', true);

    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    priced(4798);

    // The guess. This is the path an attacker drives thousands of times, and it
    // is the path where a code most plausibly ends up written down — a helpful
    // "we could not find :code" in a message, a `Log::debug($input)`, a
    // validation error naming the field's value.
    $refused = applyTo()->call('apply', A_CODE_NOBODY_HAS);

    $refused->assertHasNoErrors();

    expect(json_encode(snapshotOf($refused)))->not->toContain(A_CODE_NOBODY_HAS)
        ->and($refused->html())->not->toContain(A_CODE_NOBODY_HAS)
        ->and(json_encode(session()->all()))->not->toContain(A_CODE_NOBODY_HAS)
        ->and(implode("\n", $logged))->not->toContain(A_CODE_NOBODY_HAS);
});

it('refuses a reference the browser tried to swap', function () {
    priced(4798, 'CHK-1');
    priced(100, 'CHK-CHEAP');

    // The reference is what both the amount and the idempotency key are derived
    // from, so a swapped reference is a swapped amount under a swapped key.
    expect(fn () => applyTo('CHK-1')->set('reference', 'CHK-CHEAP'))
        ->toThrow(Exception::class, 'Cannot update locked property: [reference]');
});

it('refuses an idempotency key the browser tried to choose', function () {
    priced(4798);

    // A browser that could set this could spend the same card twice under two
    // keys, which is the double debit the derivation exists to prevent.
    //
    // Asserted on the message rather than the class: Livewire has moved that
    // exception between namespaces across majors, and what this is about is the
    // refusal, not where the class lives.
    expect(fn () => applyTo()->set('entryKey', 'a-key-i-picked'))
        ->toThrow(Exception::class, 'Cannot update locked property: [entryKey]');
});

it('refuses a browser that tried to declare itself already applied', function () {
    priced(4798);

    expect(fn () => applyTo()->set('applied', true))
        ->toThrow(Exception::class, 'Cannot update locked property: [applied]');
});

it('has no property a browser could name a card in', function () {
    priced(4798);

    // Livewire refuses to hydrate a property the component does not declare, so
    // an attempted write is a failed request rather than somebody else's card on
    // the page.
    expect(property_exists(ApplyGiftCard::class, 'code'))->toBeFalse()
        ->and(fn () => applyTo()->set('code', A_CODE_NOBODY_HAS))->toThrow(Exception::class);
});

it('renders exactly one field, and it is not bound to anything', function () {
    priced(4798);

    $html = applyTo()->html();

    preg_match_all('/<input\b[^>]*>/i', $html, $fields);

    expect($fields[0])->toHaveCount(1);

    // The whole mechanism in one assertion. `wire:model` on this field would put
    // a bearer credential into the component's state and back into the page on
    // every render; instead the value lives in an Alpine variable and reaches
    // the server once, as an argument.
    expect($fields[0][0])
        ->not->toContain('wire:model')
        ->toContain('x-model')
        // Out of the browser's saved form data, which is the one place a code
        // could persist that this package can do anything about.
        ->toContain('autocomplete="off"');

    expect($html)->toContain('$wire.apply(code)');
});

it('never reaches for an application class', function () {
    foreach (everySourceFile() as $file) {
        expect((string) file_get_contents($file))
            ->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
    }
});

it('imports no sibling commerce module but the one it presents', function () {
    foreach (everySourceFile() as $file) {
        preg_match_all('/^use (Liberu\\\\Ecommerce\\\\[A-Za-z]+)\\\\/m', (string) file_get_contents($file), $imports);

        foreach (array_unique($imports[1]) as $import) {
            expect($import)->toBe('Liberu\Ecommerce\GiftCardsAndStoreCredit');
        }
    }
});

it('does no float arithmetic on anybody\'s balance', function () {
    foreach (everySourceFile() as $file) {
        // Comments stripped first: the docblocks here quote the `decimal(10, 2)`
        // and `float $amount` of the host code this fleet replaced, and that
        // quotation is the point.
        $source = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                $source .= $token;

                continue;
            }

            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= $token[1];
            }
        }

        expect($source)
            ->not->toMatch('/\b(float|double)\b/i')
            ->not->toContain('number_format');
    }
});

it('reads no domain model directly on the balances list', function () {
    // A presentation package goes through the domain's published reads. The
    // query eager-loads the ledger and folds it; a model here would be a second
    // place that fold could be got wrong.
    expect((string) file_get_contents(new ReflectionClass(MyBalances::class)->getFileName()))
        ->not->toMatch('/use Liberu\\\\Ecommerce\\\\GiftCardsAndStoreCredit\\\\Models\\\\/');
});
