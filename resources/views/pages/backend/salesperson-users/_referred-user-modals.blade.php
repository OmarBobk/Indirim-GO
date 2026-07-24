{{-- Alpine-controlled overlays: open synchronously from row JSON; submit via $wire once. --}}

<div
    x-show="editOpen"
    x-cloak
    class="fixed inset-0 z-[90] flex items-center justify-center bg-black/50 p-4 sm:p-6"
>
    <div
        class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900 sm:p-7"
        @click.outside="closeEdit()"
    >
        <form class="space-y-6" @submit.prevent="submitEdit()">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.edit_user') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.edit_referred_user_hint') }}
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input x-model="editForm.name" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="editErrors.name" x-text="editErrors.name"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input x-model="editForm.username" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="editErrors.username" x-text="editErrors.username"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input type="email" x-model="editForm.email" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="editErrors.email" x-text="editErrors.email"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.phone') }}</flux:label>
                    <flux:input.group>
                        <flux:select x-model="editForm.country_code" class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                            <flux:select.option value="+90">+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input
                            x-model="editForm.phone"
                            mask="(999) 999-9999"
                            placeholder="( ___ ) ___-____"
                            class="w-full"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                    </flux:input.group>
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="editErrors.phone" x-text="editErrors.phone"></p>
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.new_password') }}</flux:label>
                    <flux:text class="mb-2 block text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.edit_referred_user_password_hint') }}
                    </flux:text>
                    <flux:input
                        type="password"
                        x-model="editForm.password"
                        viewable
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="editErrors.password" x-text="editErrors.password"></p>
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                    <flux:input
                        type="password"
                        x-model="editForm.password_confirmation"
                        viewable
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                </flux:field>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" @click="closeEdit()">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" x-bind:disabled="busyAction === 'edit'">
                    <span x-show="busyAction !== 'edit'">{{ __('messages.update_user') }}</span>
                    <span x-show="busyAction === 'edit'" x-cloak>{{ __('messages.please_wait') }}</span>
                </flux:button>
            </div>
        </form>
    </div>
</div>

<div
    x-show="resetOpen"
    x-cloak
    class="fixed inset-0 z-[90] flex items-center justify-center bg-black/50 p-4 sm:p-6"
>
    <div
        class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900 sm:p-7"
        @click.outside="closeReset()"
    >
        <form class="space-y-6" @submit.prevent="submitReset()">
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
                <flux:input type="password" x-model="resetForm.password" viewable class="w-full" />
                <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="resetErrors.password" x-text="resetErrors.password"></p>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                <flux:input type="password" x-model="resetForm.password_confirmation" viewable class="w-full" />
            </flux:field>
            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" @click="closeReset()">{{ __('messages.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" x-bind:disabled="busyAction === 'reset'">
                    <span x-show="busyAction !== 'reset'">{{ __('messages.reset_password') }}</span>
                    <span x-show="busyAction === 'reset'" x-cloak>{{ __('messages.please_wait') }}</span>
                </flux:button>
            </div>
        </form>
    </div>
</div>

<div
    x-show="createOpen"
    x-cloak
    class="fixed inset-0 z-[90] flex items-center justify-center bg-black/50 p-4 sm:p-6"
>
    <div
        class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900 sm:p-7"
        @click.outside="closeCreate()"
    >
        <form class="space-y-6" @submit.prevent="submitCreate()">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.create_user') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.create_referred_user_hint') }}
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input x-model="createForm.name" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="createErrors.name" x-text="createErrors.name"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input x-model="createForm.username" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="createErrors.username" x-text="createErrors.username"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input type="email" x-model="createForm.email" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="createErrors.email" x-text="createErrors.email"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.password') }}</flux:label>
                    <flux:input type="password" x-model="createForm.password" viewable class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="createErrors.password" x-text="createErrors.password"></p>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                    <flux:input type="password" x-model="createForm.password_confirmation" viewable class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.phone') }}</flux:label>
                    <flux:input.group>
                        <flux:select x-model="createForm.country_code" class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                            <flux:select.option value="+90">+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input
                            x-model="createForm.phone"
                            mask="(999) 999-9999"
                            placeholder="( ___ ) ___-____"
                            class="w-full"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                    </flux:input.group>
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400" x-show="createErrors.phone" x-text="createErrors.phone"></p>
                </flux:field>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" @click="closeCreate()">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="!bg-accent !text-accent-foreground hover:!bg-accent-hover" x-bind:disabled="busyAction === 'create'">
                    <span x-show="busyAction !== 'create'">{{ __('messages.create_user') }}</span>
                    <span x-show="busyAction === 'create'" x-cloak>{{ __('messages.please_wait') }}</span>
                </flux:button>
            </div>
        </form>
    </div>
</div>
