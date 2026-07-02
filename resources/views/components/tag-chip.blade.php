@props(['color' => 'zinc', 'small' => false])

@php
    // T-1: paleta fechada (classes LITERAIS pro scanner do Tailwind).
    $classes = match ($color) {
        'red' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        'sky' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
        'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
        'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
        'pink' => 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300',
        default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full font-medium', $classes, 'px-1.5 py-0 text-[10px]' => $small, 'px-2 py-0.5 text-xs' => ! $small]) }}>{{ $slot }}</span>
