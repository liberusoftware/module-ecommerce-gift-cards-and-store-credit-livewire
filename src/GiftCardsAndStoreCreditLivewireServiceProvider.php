<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Livewire\Livewire;

/**
 * Registers this package's bounded Livewire namespace.
 *
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so
 * installing it boots nothing until a deployment names the module in
 * `MODULES_ENABLED`.
 *
 * Aliases are explicit rather than discovered. A directory scan resolves
 * whatever happens to be on disk, so moving a class or adding one would
 * silently change a public interface; this list *is* the interface, and
 * changing it is a diff somebody reviews.
 */
class GiftCardsAndStoreCreditLivewireServiceProvider extends ServiceProvider
{
    /**
     * The one namespace this package owns, for components, views and
     * translations alike. It drops the `-livewire` suffix and keeps the
     * ownership prefix: it names the bounded context, not the technology
     * presenting it.
     */
    public const NAMESPACE = 'module-ecommerce-gift-cards-and-store-credit';

    /**
     * The package's public component surface — the whole of it.
     *
     * **Two, and the shortness of the list is the design.** Issuing a card,
     * adjusting a balance, disabling a card and working a reconciliation queue
     * are staff work and belong in `-filament`. What is left for a shopper is
     * spending a card they hold, and seeing what is on the balances that are
     * already theirs.
     *
     * There is deliberately no third component looking a balance up **by code**.
     * See `docs/domain.md` §3: the domain publishes no `byCode()` read, and a
     * surface that built one would be a second lookup over the code space with
     * its own throttle, its own timing profile and its own refusal messages to
     * get identical. One box is one oracle to close; two boxes is two.
     *
     * @var array<string, class-string>
     */
    private const COMPONENTS = [
        'apply' => ApplyGiftCard::class,
        'balances' => MyBalances::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gift-cards-livewire.php', 'gift-cards-livewire');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAMESPACE);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::NAMESPACE);

        $aliases = $this->aliases();

        // Two halves of the same registration, and both are needed.
        //
        // `component()` is the name a class reports as — what a rendered
        // component calls itself, and what `Livewire::test(SomeClass::class)`
        // resolves back to.
        //
        // `resolveMissingComponent()` is the other direction, and it is the one
        // that costs an afternoon if it is missing. Livewire 4's
        // `Finder::resolveClassComponentClassName()` returns null for a
        // `namespace::name` *before* it consults the explicit registry, so
        // `component()` alone never answers one. `addNamespace()` does answer,
        // but it maps one Livewire namespace onto exactly one class namespace,
        // which forecloses a `Pages\` this package may yet want. So the alias
        // table answers instead.
        foreach ($aliases as $alias => $component) {
            Livewire::component($alias, $component);
        }

        Livewire::resolveMissingComponent(
            static fn (string $name): ?string => $aliases[$name] ?? null,
        );

        // Publishing views is how a theme overrides one without forking the
        // package. Translations publish separately, because a deployment that
        // wants its own wording rarely wants its own markup as well — and on
        // this surface the wording is load-bearing: every refusal is one
        // sentence on purpose, and an adopter who splits it into several has
        // reopened the oracle. `docs/adoption.md` says so where they will read
        // it.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-translations');

        $this->publishes([
            __DIR__.'/../config/gift-cards-livewire.php' => config_path('gift-cards-livewire.php'),
        ], self::NAMESPACE.'-config');
    }

    /**
     * The component table, keyed by the fully qualified alias.
     *
     * @return array<string, class-string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach (self::COMPONENTS as $alias => $component) {
            $aliases[self::NAMESPACE.'::'.$alias] = $component;
        }

        return $aliases;
    }
}
