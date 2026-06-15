<div class="flex flex-col gap-4" style="height: calc(100vh - 8rem)">

    {{-- Header row --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading>{{ __('messages.assistant_page_title') }}</flux:heading>
            <flux:text>{{ __('messages.assistant_intro') }}</flux:text>
        </div>
        <flux:button wire:click="clearChat" variant="ghost" size="sm">
            {{ __('messages.assistant_clear') }}
        </flux:button>
    </div>

    {{-- Error state --}}
    @if ($error)
        <flux:callout variant="danger">{{ $error }}</flux:callout>
    @endif

    {{-- Message list — flex-1 so it fills remaining height --}}
    <div
        class="flex-1 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700/80 bg-zinc-50/50 dark:bg-zinc-900/40 p-4 flex flex-col gap-3"
        x-ref="messageList"
        x-init="$el.scrollTop = $el.scrollHeight"
        x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
    >
        {{-- Empty state: show when only system message present --}}
        @if (count(array_filter($messages, fn ($m) => $m['role'] === 'user')) === 0)
            <div class="flex items-center justify-center h-full">
                <flux:text class="text-zinc-400 dark:text-zinc-500 text-sm">
                    {{ __('messages.assistant_empty_hint') }}
                </flux:text>
            </div>
        @endif

        @foreach ($messages as $index => $msg)
            @continue($msg['role'] === 'system' || $msg['role'] === 'tool')
            <div
                wire:key="assistant-msg-{{ $index }}"
                @class([
                    'flex',
                    'justify-end' => $msg['role'] === 'user',
                    'justify-start' => $msg['role'] === 'assistant',
                ])
            >
                @if ($msg['role'] === 'user')
                    <div class="max-w-[85%] rounded-xl border border-zinc-300/80 dark:border-zinc-600/80 bg-white dark:bg-zinc-800 px-4 py-2.5 text-sm leading-relaxed text-zinc-800 dark:text-zinc-200 shadow-sm">
                        {{ $msg['content'] }}
                    </div>
                @else
                    <div class="max-w-[90%] rounded-xl border border-zinc-200 dark:border-zinc-700/90 bg-white dark:bg-zinc-800/90 px-4 py-3 text-sm shadow-sm">
                        <div @class([
                            'assistant-markdown leading-relaxed text-zinc-700 dark:text-zinc-300',
                            '[&_h1]:text-base [&_h1]:font-semibold [&_h1]:text-zinc-900 [&_h1]:dark:text-zinc-100 [&_h1]:mt-0 [&_h1]:mb-2',
                            '[&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_h2]:dark:text-zinc-100 [&_h2]:mt-3 [&_h2]:mb-1.5',
                            '[&_h3]:text-sm [&_h3]:font-semibold [&_h3]:text-zinc-900 [&_h3]:dark:text-zinc-100 [&_h3]:mt-3 [&_h3]:mb-1.5',
                            '[&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0',
                            '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1',
                            '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1',
                            '[&_li]:leading-relaxed',
                            '[&_strong]:font-semibold [&_strong]:text-zinc-900 dark:[&_strong]:text-zinc-100',
                            '[&_code]:rounded [&_code]:bg-zinc-100 [&_code]:dark:bg-zinc-900 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-xs [&_code]:font-mono',
                            '[&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-zinc-100 [&_pre]:dark:bg-zinc-900 [&_pre]:p-3 [&_pre]:text-xs',
                            '[&_hr]:my-3 [&_hr]:border-zinc-200 dark:[&_hr]:border-zinc-700',
                        ])>
                            {!! \Illuminate\Support\Str::markdown((string) ($msg['content'] ?? '')) !!}
                        </div>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Loading indicator --}}
        <div wire:loading wire:target="sendMessage" class="flex justify-start">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800/90 px-4 py-2.5 text-sm text-zinc-500 dark:text-zinc-400">
                <flux:icon name="ellipsis-horizontal" class="animate-pulse size-5" />
            </div>
        </div>
    </div>

    {{-- Input area --}}
    <form wire:submit.prevent="sendMessage" class="flex gap-2 items-end">
        <div class="flex-1">
            <flux:textarea
                wire:model.lazy="message"
                rows="3"
                placeholder="{{ __('messages.assistant_placeholder') }}"
                wire:loading.attr="disabled"
                wire:target="sendMessage"
            />
        </div>
        <flux:button
            type="submit"
            variant="primary"
            wire:loading.attr="disabled"
            wire:target="sendMessage"
        >
            <span wire:loading.remove wire:target="sendMessage">
                {{ __('messages.assistant_send') }}
            </span>
            <span wire:loading wire:target="sendMessage">...</span>
        </flux:button>
    </form>

</div>
