@php
    $heroImage = collect($options['images'] ?? [])->first();

    $heroImageUrls = bagisto_theme_storage()->imageUrls($heroImage['image'] ?? null);
@endphp

<section class="organic-hero" aria-labelledby="organic-hero-title">
    <div class="organic-hero-copy">
        <p class="organic-eyebrow">@lang('shop::organic.eyebrow')</p>

        <h1 id="organic-hero-title">@lang('shop::organic.hero-title')</h1>

        <p class="organic-hero-description">@lang('shop::organic.hero-description')</p>

        <a class="primary-button organic-hero-action" href="{{ route('shop.search.index') }}">
            @lang('shop::organic.explore')

            <span class="icon-arrow-right" aria-hidden="true"></span>
        </a>

        <div class="organic-hero-note">
            <span class="organic-note-line" aria-hidden="true"></span>
            <p>@lang('shop::organic.hero-note')</p>
        </div>
    </div>

    @if ($heroImageUrls)
        <a class="organic-hero-visual" href="{{ filled($heroImage['link'] ?? null) ? url($heroImage['link']) : route('shop.search.index') }}">
            <img
                src="{{ $heroImageUrls['url'] }}"
                alt="{{ $heroImage['title'] ?? trans('shop::organic.hero-image') }}"
                width="960"
                height="960"
                fetchpriority="high"
            >

            <span class="organic-hero-caption">@lang('shop::organic.discover')</span>
        </a>
    @endif
</section>
