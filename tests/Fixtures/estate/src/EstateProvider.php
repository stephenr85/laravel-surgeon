<?php

namespace Vendor\Estate\Providers;

// Deliberately does NOT import Widget — fully-qualified inline so the only touch-points here are the
// three special syntactic contexts: morph-map value (Tier 2), runtime resolve (Tier 2), provider
// registration (Tier 3). No use-import noise. (Excluded from Pint — stable test data.)
class EstateProvider
{
    public function boot(): void
    {
        Relation::morphMap([
            'widget' => \Vendor\Old\Widget::class,
        ]);

        Gate::policy(\Vendor\Old\Widget::class, WidgetPolicy::class);

        $instance = app(\Vendor\Old\Widget::class);
    }
}
