@props([
    'icon' => 'inbox',
    'message',
])

<div {{ $attributes->class(['cf-empty-state']) }}>
    <div class="flex size-11 items-center justify-center rounded-full border border-[var(--cf-border)] bg-[var(--cf-card)] text-[var(--cf-muted-foreground)]">
        <flux:icon :name="$icon" class="size-5" />
    </div>
    <flux:text class="max-w-xs text-sm text-[var(--cf-muted-foreground)]">{{ $message }}</flux:text>
</div>
