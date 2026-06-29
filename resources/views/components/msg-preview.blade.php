@props(['preview'])
@php $p = $preview; @endphp
@if (($p['plain'] ?? null) !== null){{ $p['plain'] }}@else<span class="inline-flex items-center gap-1 align-middle">@if (! empty($p['emoji']))<span>{{ $p['emoji'] }}</span>@elseif (! empty($p['icon']))<flux:icon :icon="$p['icon']" variant="micro" class="size-3.5 shrink-0 opacity-70" />@endif<span>{{ $p['label'] }}</span></span>@if (! empty($p['caption']))<span class="text-zinc-500"> · {{ $p['caption'] }}</span>@endif @endif
