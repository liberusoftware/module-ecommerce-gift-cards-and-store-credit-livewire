<?php

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider as Provider;
use Livewire\Livewire;

/*
 * The package's public surface: two aliases, and the registration that makes a
 * namespaced name resolvable at all.
 */

it('publishes exactly two components, and their names are the interface', function () {
    // Explicit rather than discovered. A directory scan resolves whatever happens
    // to be on disk, so moving a class or adding one would silently change a
    // public interface; this list *is* the interface, and changing it is a diff
    // somebody reviews.
    expect(app()->getProvider(Provider::class)?->aliases())->toBe([
        'module-ecommerce-gift-cards-and-store-credit::apply' => ApplyGiftCard::class,
        'module-ecommerce-gift-cards-and-store-credit::balances' => MyBalances::class,
    ]);
});

it('mounts the apply control by its namespaced alias', function () {
    // The half that costs an afternoon when it is missing. Livewire 4's
    // `Finder::resolveClassComponentClassName()` returns null for a
    // `namespace::name` *before* it consults the explicit registry, so
    // `Livewire::component()` alone never answers one. `resolveMissingComponent()`
    // is what does, and this is the proof.
    priced();

    expect(Livewire::test('module-ecommerce-gift-cards-and-store-credit::apply', ['reference' => 'CHK-1'])->html())
        ->toContain('GBP 47.98');
});

it('mounts the balances list by its namespaced alias', function () {
    asCustomer();

    expect(Livewire::test('module-ecommerce-gift-cards-and-store-credit::balances')->html())
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.heading'));
});

it('registers no route of its own', function () {
    // Routes belong to the application. A package that registered
    // `/gift-cards/{code}` would have decided a host's URL structure — and put a
    // bearer credential in a URL, which is an access log, a referrer header and
    // a browser history all at once.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'gift'));

    expect($routes)->toBeEmpty();
});

it('publishes its views, translations and config under one tag prefix', function () {
    // A deployment overriding the wording rarely wants its own markup as well —
    // and on this surface the wording is load-bearing, because every refusal is
    // one sentence on purpose.
    expect(ServiceProvider::publishableGroups())
        ->toContain('module-ecommerce-gift-cards-and-store-credit-views')
        ->toContain('module-ecommerce-gift-cards-and-store-credit-translations')
        ->toContain('module-ecommerce-gift-cards-and-store-credit-config');
});

it('registers the namespace by resolver rather than by namespace map', function () {
    // `addNamespace()` maps one Livewire namespace onto exactly one class
    // namespace, which forecloses a `Pages\` this package may yet want, and does
    // not answer the case above at all.
    foreach (everySourceFile() as $file) {
        expect((string) file_get_contents($file))->not->toContain('addNamespace');
    }

    expect((string) file_get_contents(new ReflectionClass(Provider::class)->getFileName()))
        ->toContain('resolveMissingComponent');
});

it('boots nothing on install', function () {
    // The package ships no `extra.laravel.providers`, so
    // `ModuleManagerServiceProvider` is the only registrar and only when the
    // deployment names the module in `MODULES_ENABLED`.
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([]);
});
