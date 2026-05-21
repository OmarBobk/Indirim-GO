<div
    x-data="{}"
    @open-create-modal.window="$wire.openCreate()"
    @open-edit-modal.window="$wire.startEdit($event.detail.userId)"
    @open-delete-modal.window="$wire.confirmDelete($event.detail.userId)"
    @open-block-modal.window="$wire.confirmBlock($event.detail.userId)"
    @open-unblock-modal.window="$wire.confirmUnblock($event.detail.userId)"
    @open-reset-password-modal.window="$wire.confirmResetPassword($event.detail.userId)"
    @open-verify-email-modal.window="$wire.confirmVerifyEmail($event.detail.userId)"
>
    {{-- Create user modal --}}
    <flux:modal wire:model.self="showCreate" class="max-w-2xl" variant="floating">
        <form wire:submit.prevent="saveCreate" class="space-y-6">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.create_user') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    @if ($referredOnly)
                        {{ __('messages.create_referred_user_hint') }}
                    @else
                        {{ __('messages.create_user_hint') }}
                    @endif
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input wire:model.defer="newName" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="newName" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>

                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input wire:model.defer="newUsername" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="newUsername" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input type="email" wire:model.defer="newEmail" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="newEmail" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.password') }}</flux:label>
                    <flux:input type="password" wire:model.defer="newPassword"
                                viewable
                                class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="newPassword" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                    <flux:input type="password" wire:model.defer="newPasswordConfirmation"
                                viewable
                                class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.phone') }}</flux:label>

                    <flux:input.group>
                        <flux:select
                            wire:model.defer="newCountryCode"
                            class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                            <flux:select.option value="+90" selected>+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input wire:model.defer="newPhone"
                                    mask="(999) 999-9999"
                                    placeholder="( ___ ) ___-____"
                                    class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="newPhone" />
                </flux:field>
                @unless ($referredOnly)
                <flux:field>
                    <flux:label>{{ __('messages.commission_rate_percent') }}</flux:label>
                    <flux:input
                        type="number"
                        step="0.01"
                        min="0.01"
                        max="100"
                        wire:model.defer="newCommissionRatePercent"
                        :placeholder="__('messages.commission_rate_percent_placeholder')"
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.commission_rate_percent_hint') }}
                    </flux:text>
                    <flux:error name="newCommissionRatePercent" />
                </flux:field>
                @endunless
            </div>

            @unless ($referredOnly)
            <div class="space-y-2">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.roles') }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->allRoles as $roleName)
                        <label
                            class="inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-all
                                has-[:checked]:border-(--color-accent)
                                border-zinc-200 bg-zinc-50 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-100
                                dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-500 dark:hover:bg-zinc-700"
                        >
                            <input
                                type="checkbox"
                                wire:model.defer="newRoles"
                                value="{{ $roleName }}"
                                class="size-4 rounded border-zinc-300 text-(--color-accent) focus:ring-(--color-accent)/40 dark:border-zinc-600"
                            />
                            <span>{{ \Illuminate\Support\Facades\Lang::has('messages.role_'.$roleName) ? __('messages.role_'.$roleName) : str_replace('_', ' ', \Illuminate\Support\Str::headline($roleName)) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-baseline justify-between gap-2">
                    <div>
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.direct_permissions') }}</flux:heading>
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.direct_permissions_hint') }}</flux:text>
                    </div>
                </div>
                <div class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-800/30" x-data="{ search: '' }">
                    <div class="p-2 sm:p-3">
                        <div class="relative">
                            <flux:icon icon="magnifying-glass" class="absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                            <flux:input
                                type="text"
                                x-model="search"
                                clearable
                                placeholder="{{ __('messages.search') }}..."
                                class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            />
                        </div>
                    </div>
                    <div class="max-h-56 overflow-y-auto p-2 pt-0 sm:p-3 sm:pt-0">
                        @foreach ($this->groupedPermissions as $groupKey => $group)
                            @php
                                $permLabels = collect($group['permissions'])->map(fn ($p) => strtolower($this->humanizePermission($p)))->implode(' ');
                            @endphp
                            <div
                                class="mb-2 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm last:mb-0 dark:border-zinc-600 dark:bg-zinc-900"
                                x-data="{ open: {{ in_array($groupKey, ['users', 'orders']) ? 'true' : 'false' }} }"
                                x-show="search === '' || ($el.dataset.permLabels || '').toLowerCase().includes(search.toLowerCase())"
                                data-perm-labels="{{ $permLabels }}"
                            >
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-start text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                >
                                    <span class="flex items-center gap-2">
                                        <flux:icon :icon="$group['icon']" class="size-4 text-zinc-500 dark:text-zinc-400" />
                                        {{ $group['label'] }}
                                        <span class="rounded-full bg-zinc-200 px-1.5 py-0.5 text-xs font-normal text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                            {{ count(array_intersect($group['permissions'], $newPermissions)) }}/{{ count($group['permissions']) }}
                                        </span>
                                    </span>
                                    <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                                </button>
                                <div x-show="open" x-collapse class="border-t border-zinc-100 dark:border-zinc-700">
                                    <div class="grid grid-cols-1 gap-1 p-2 sm:grid-cols-2">
                                        @foreach ($group['permissions'] as $permName)
                                            <label
                                                class="flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                                            >
                                                <input
                                                    type="checkbox"
                                                    wire:model.defer="newPermissions"
                                                    value="{{ $permName }}"
                                                    class="size-4 rounded border-zinc-300 text-(--color-accent) focus:ring-(--color-accent)/40 dark:border-zinc-600"
                                                />
                                                <span>{{ $this->humanizePermission($permName) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endunless

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="closeCreate">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCreate">
                    {{ __('messages.create_user') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Edit user modal --}}
    <flux:modal wire:model.self="showEdit" class="max-w-2xl" variant="floating">
        <form wire:submit.prevent="saveEdit" class="space-y-6">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.edit_user') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    @if ($referredOnly)
                        {{ __('messages.edit_referred_user_hint') }}
                    @else
                        {{ __('messages.edit_user_hint') }}
                    @endif
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input wire:model.defer="editName" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="editName" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>

                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input wire:model.defer="editUsername" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="editUsername" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input type="email" wire:model.defer="editEmail" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="editEmail" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.phone') }}</flux:label>

                    <flux:input.group>
                        <flux:select
                            wire:model.defer="editCountryCode"
                            class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                            <flux:select.option value="+90" selected>+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input wire:model.defer="editPhone"
                                    mask="(999) 999-9999"
                                    placeholder="( ___ ) ___-____"
                                    class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="editPhone" />
                </flux:field>
                @if ($referredOnly)
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('messages.new_password') }}</flux:label>
                        <flux:text class="mb-2 block text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.edit_referred_user_password_hint') }}
                        </flux:text>
                        <flux:input
                            type="password"
                            wire:model.defer="editPassword"
                            viewable
                            class="w-full"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                        <flux:error name="editPassword" />
                    </flux:field>
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                        <flux:input
                            type="password"
                            wire:model.defer="editPasswordConfirmation"
                            viewable
                            class="w-full"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                    </flux:field>
                @endif
                @unless ($referredOnly)
                <flux:field>
                    <flux:label>{{ __('messages.commission_rate_percent') }}</flux:label>
                    <flux:input
                        type="number"
                        step="0.01"
                        min="0.01"
                        max="100"
                        wire:model.defer="editCommissionRatePercent"
                        :placeholder="__('messages.commission_rate_percent_placeholder')"
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.commission_rate_percent_hint') }}
                    </flux:text>
                    <flux:error name="editCommissionRatePercent" />
                </flux:field>
                @endunless
            </div>

            @unless ($referredOnly)
            <div class="space-y-2">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.roles') }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->allRoles as $roleName)
                        <label
                            class="inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-all
                                has-[:checked]:border-(--color-accent)
                                border-zinc-200 bg-zinc-50 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-100
                                dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-500 dark:hover:bg-zinc-700"
                        >
                            <input
                                type="checkbox"
                                wire:model.defer="editRoles"
                                value="{{ $roleName }}"
                                class="size-4 rounded border-zinc-300 text-(--color-accent) focus:ring-(--color-accent)/40 dark:border-zinc-600"
                            />
                            <span>{{ \Illuminate\Support\Facades\Lang::has('messages.role_'.$roleName) ? __('messages.role_'.$roleName) : str_replace('_', ' ', \Illuminate\Support\Str::headline($roleName)) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-baseline justify-between gap-2">
                    <div>
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.direct_permissions') }}</flux:heading>
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.direct_permissions_hint') }}</flux:text>
                    </div>
                </div>
                <div class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-800/30" x-data="{ search: '' }">
                    <div class="p-2 sm:p-3">
                        <div class="relative">
                            <flux:icon icon="magnifying-glass" class="absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                            <flux:input
                                type="text"
                                x-model="search"
                                clearable
                                placeholder="{{ __('messages.search') }}..."
                                class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            />
                        </div>
                    </div>
                    <div class="max-h-56 overflow-y-auto p-2 pt-0 sm:p-3 sm:pt-0">
                        @foreach ($this->groupedPermissions as $groupKey => $group)
                            @php
                                $permLabels = collect($group['permissions'])->map(fn ($p) => strtolower($this->humanizePermission($p)))->implode(' ');
                            @endphp
                            <div
                                class="mb-2 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm last:mb-0 dark:border-zinc-600 dark:bg-zinc-900"
                                x-data="{ open: {{ in_array($groupKey, ['users', 'orders']) ? 'true' : 'false' }} }"
                                x-show="search === '' || ($el.dataset.permLabels || '').toLowerCase().includes(search.toLowerCase())"
                                data-perm-labels="{{ $permLabels }}"
                            >
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-start text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                >
                                    <span class="flex items-center gap-2">
                                        <flux:icon :icon="$group['icon']" class="size-4 text-zinc-500 dark:text-zinc-400" />
                                        {{ $group['label'] }}
                                        <span class="rounded-full bg-zinc-200 px-1.5 py-0.5 text-xs font-normal text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                            {{ count(array_intersect($group['permissions'], $editPermissions)) }}/{{ count($group['permissions']) }}
                                        </span>
                                    </span>
                                    <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                                </button>
                                <div x-show="open" x-collapse class="border-t border-zinc-100 dark:border-zinc-700">
                                    <div class="grid grid-cols-1 gap-1 p-2 sm:grid-cols-2">
                                        @foreach ($group['permissions'] as $permName)
                                            <label
                                                class="flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                                            >
                                                <input
                                                    type="checkbox"
                                                    wire:model.defer="editPermissions"
                                                    value="{{ $permName }}"
                                                    class="size-4 rounded border-zinc-300 text-(--color-accent) focus:ring-(--color-accent)/40 dark:border-zinc-600"
                                                />
                                                <span>{{ $this->humanizePermission($permName) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endunless

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="closeEdit">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEdit">
                    {{ __('messages.update_user') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete confirmation --}}
    <flux:modal wire:model.self="showDeleteModal" class="max-w-md" variant="floating" @close="cancelDelete" @cancel="cancelDelete">
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400">
                    <flux:icon icon="trash" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.delete_user_title') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.delete_user_body', ['name' => $deleteUserName]) }}
                    </flux:text>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelDelete">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button variant="danger" wire:click="deleteUser" wire:loading.attr="disabled" wire:target="deleteUser">
                    {{ __('messages.delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Block confirmation --}}
    <flux:modal wire:model.self="showBlockModal" class="max-w-md" variant="floating" @close="cancelBlock" @cancel="cancelBlock">
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                    <flux:icon icon="lock-closed" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.block_user_title') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.block_user_body', ['name' => $blockUserName]) }}
                    </flux:text>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelBlock">{{ __('messages.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="blockUser" wire:loading.attr="disabled" wire:target="blockUser">
                    {{ __('messages.block') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Unblock confirmation --}}
    <flux:modal wire:model.self="showUnblockModal" class="max-w-md" variant="floating" @close="cancelUnblock" @cancel="cancelUnblock">
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400">
                    <flux:icon icon="lock-open" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.unblock_user_title') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.unblock_user_body', ['name' => $unblockUserName]) }}
                    </flux:text>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelUnblock">{{ __('messages.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="unblockUser" wire:loading.attr="disabled" wire:target="unblockUser">
                    {{ __('messages.unblock') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Reset password modal --}}
    <flux:modal wire:model.self="showResetPasswordModal" class="max-w-md" variant="floating" @close="cancelResetPassword" @cancel="cancelResetPassword">
        <form wire:submit.prevent="resetPassword" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.reset_password') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.reset_password_hint') }}
                </flux:text>
            </div>
            <flux:field>
                <flux:label>{{ __('messages.new_password') }}</flux:label>
                <flux:input type="password" wire:model.defer="resetPasswordNew" class="w-full" />
                <flux:error name="resetPasswordNew" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                <flux:input type="password" wire:model.defer="resetPasswordNewConfirmation" class="w-full" />
            </flux:field>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="cancelResetPassword">{{ __('messages.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="resetPassword">
                    {{ __('messages.reset_password') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Verify email confirmation --}}
    <flux:modal wire:model.self="showVerifyModal" class="max-w-md" variant="floating" @close="cancelVerify" @cancel="cancelVerify">
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400">
                    <flux:icon icon="envelope" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.verify_email') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.verify_email_body', ['name' => $verifyUserName]) }}
                    </flux:text>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelVerify">{{ __('messages.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="verifyEmail" wire:loading.attr="disabled" wire:target="verifyEmail">
                    {{ __('messages.verify_email') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
