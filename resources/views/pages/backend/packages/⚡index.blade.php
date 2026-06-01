<?php

use App\Actions\Packages\DeletePackage;
use App\Actions\Packages\DeletePackageRequirement;
use App\Actions\Packages\GetPackageCategories;
use App\Actions\Packages\GetPackageDetails;
use App\Actions\Packages\GetPackageRequirementDetails;
use App\Actions\Packages\GetPackageRequirements;
use App\Actions\Packages\GetPackages;
use App\Actions\Packages\TogglePackageStatus;
use App\Actions\Packages\UpsertPackage;
use App\Actions\Packages\UpsertPackageRequirement;
use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const REQUIREMENT_KEYS = ['id', 'username', 'phone'];
    private const REQUIREMENT_TYPES = ['string', 'number', 'select'];

    public string $search = '';
    public string $statusFilter = 'all';
    public string $sortBy = 'order';
    public string $sortDirection = 'asc';
    public int $perPage = 10;

    public ?int $selectedPackageId = null;

    public ?int $editingPackageId = null;
    public ?int $packageCategoryId = null;
    public string $packageName = '';
    public ?string $packageDescription = null;
    public ?int $packageOrder = null;
    public ?string $packageIcon = null;
    public $packageImageFile = null;
    public bool $packageIsActive = true;
    public string $packageFulfillmentProvider = '';
    public ?string $packageApi = null;

    public ?int $editingRequirementId = null;
    public string $requirementKey = 'id';
    public string $requirementLabel = '';
    public string $requirementType = 'string';
    public bool $requirementIsRequired = true;
    public ?string $requirementValidationRules = null;
    public ?int $requirementOrder = null;

    public bool $showDeletePackageModal = false;
    public ?int $deletePackageId = null;
    public string $deletePackageName = '';

    public bool $showDeleteRequirementModal = false;
    public ?int $deleteRequirementId = null;
    public string $deleteRequirementLabel = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_products'), 403);
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'sortBy', 'sortDirection', 'perPage']);
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function packageRules(): array
    {
        return [
            'packageCategoryId' => ['required', 'integer', 'exists:categories,id'],
            'packageName' => ['required', 'string', 'max:255'],
            'packageDescription' => ['nullable', 'string'],
            'packageOrder' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('packages', 'order')->ignore($this->editingPackageId),
            ],
            'packageIcon' => ['nullable', 'string', 'max:255'],
            'packageImageFile' => ['nullable', 'image', 'max:2048'],
            'packageIsActive' => ['boolean'],
            'packageFulfillmentProvider' => ['nullable', 'string', Rule::in($this->allowedFulfillmentProviderValues())],
            'packageApi' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedFulfillmentProviderValues(): array
    {
        return array_keys($this->fulfillmentProviderOptions);
    }

    /**
     * @return array<string, string>
     */
    private function fulfillmentProviderOptions(): array
    {
        $options = [
            '' => __('messages.package_fulfillment_manual'),
        ];

        foreach (config('fulfillment_automation.suppliers', []) as $supplierKey => $supplier) {
            if (! is_string($supplierKey) || $supplierKey === '') {
                continue;
            }

            $options['browser:'.$supplierKey] = __('messages.package_fulfillment_browser').' ('.$supplierKey.')';
        }

        return $options;
    }

    public function fulfillmentProviderLabel(?string $provider): string
    {
        if ($provider === null || $provider === '' || $provider === 'manual') {
            return __('messages.package_fulfillment_manual');
        }

        if (str_starts_with($provider, 'browser:')) {
            return __('messages.package_fulfillment_browser');
        }

        return $provider;
    }

    private function formValueForFulfillmentProvider(?string $provider): string
    {
        $normalized = UpsertPackage::normalizeFulfillmentProvider($provider);

        if ($normalized === null) {
            return '';
        }

        if (array_key_exists($normalized, $this->fulfillmentProviderOptions)) {
            return $normalized;
        }

        $supplierKey = array_key_first(config('fulfillment_automation.suppliers', []));

        if (is_string($supplierKey) && $supplierKey !== '' && str_starts_with($normalized, 'browser:')) {
            return 'browser:'.$supplierKey;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function requirementRules(): array
    {
        return [
            'requirementKey' => ['required', Rule::in(self::REQUIREMENT_KEYS)],
            'requirementLabel' => ['required', 'string', 'max:255'],
            'requirementType' => ['required', Rule::in(self::REQUIREMENT_TYPES)],
            'requirementIsRequired' => ['boolean'],
            'requirementValidationRules' => ['nullable', 'string', 'max:255'],
            'requirementOrder' => ['required', 'integer', 'min:0'],
        ];
    }

    public function savePackage(): void
    {
        $validated = $this->validate($this->packageRules());

        $package = app(UpsertPackage::class)->handle(
            $this->editingPackageId,
            [
                'category_id' => $validated['packageCategoryId'],
                'name' => $validated['packageName'],
                'description' => $validated['packageDescription'],
                'is_active' => $validated['packageIsActive'],
                'order' => $validated['packageOrder'],
                'icon' => $validated['packageIcon'],
                'fulfillment_provider' => $validated['packageFulfillmentProvider'] ?? null,
                'package_api' => $validated['packageApi'] ?? null,
            ],
            $this->packageImageFile
        );

        $this->selectedPackageId = $package->id;
        $this->resetPackageForm();
        $this->resetPage();
        $this->dispatch('package-saved');
    }

    public function startEditPackage(int $packageId): void
    {
        $package = app(GetPackageDetails::class)->handle($packageId);

        if ($package === null) {
            return;
        }

        $this->editingPackageId = $package->id;
        $this->packageCategoryId = $package->category_id;
        $this->packageName = $package->name;
        $this->packageDescription = $package->description;
        $this->packageOrder = $package->order;
        $this->packageIcon = $package->icon;
        $this->packageImageFile = null;
        $this->packageIsActive = $package->is_active;
        $this->packageFulfillmentProvider = $this->formValueForFulfillmentProvider($package->fulfillment_provider);
        $this->packageApi = $package->package_api;

        $this->dispatch('open-package-panel');
    }

    public function selectPackage(int $packageId): void
    {
        $this->selectedPackageId = $packageId;
        $this->resetRequirementForm();
    }

    public function toggleStatus(int $packageId): void
    {
        app(TogglePackageStatus::class)->handle($packageId);
    }

    public function confirmDeletePackage(int $packageId): void
    {
        $package = app(GetPackageDetails::class)->handle($packageId);

        if ($package === null) {
            return;
        }

        $this->deletePackageId = $package->id;
        $this->deletePackageName = $package->name;
        $this->showDeletePackageModal = true;
    }

    public function cancelDeletePackage(): void
    {
        $this->reset(['showDeletePackageModal', 'deletePackageId', 'deletePackageName']);
    }

    public function deletePackage(?int $packageId = null): void
    {
        $packageId = $packageId ?? $this->deletePackageId;

        if ($packageId === null) {
            return;
        }

        app(DeletePackage::class)->handle($packageId, auth()->id());

        if ($this->selectedPackageId === $packageId) {
            $this->selectedPackageId = null;
        }

        $this->cancelDeletePackage();
        $this->resetPage();
    }

    public function saveRequirement(): void
    {
        if ($this->selectedPackageId === null) {
            $this->addError('selectedPackageId', __('messages.select_package_first'));

            return;
        }

        $validated = $this->validate($this->requirementRules());

        app(UpsertPackageRequirement::class)->handle(
            $this->editingRequirementId,
            $this->selectedPackageId,
            [
                'key' => $validated['requirementKey'],
                'label' => $validated['requirementLabel'],
                'type' => $validated['requirementType'],
                'is_required' => $validated['requirementIsRequired'],
                'validation_rules' => $validated['requirementValidationRules'],
                'order' => $validated['requirementOrder'],
            ]
        );

        $this->resetRequirementForm();
        $this->dispatch('requirement-saved');
    }

    public function startEditRequirement(int $requirementId): void
    {
        $requirement = app(GetPackageRequirementDetails::class)->handle($requirementId);

        $this->editingRequirementId = $requirement->id;
        $this->selectedPackageId = $requirement->package_id;
        $this->requirementKey = $requirement->key;
        $this->requirementLabel = $requirement->label;
        $this->requirementType = $requirement->type;
        $this->requirementIsRequired = $requirement->is_required;
        $this->requirementValidationRules = $requirement->validation_rules;
        $this->requirementOrder = $requirement->order;

        $this->dispatch('open-requirement-panel');
    }

    public function confirmDeleteRequirement(int $requirementId): void
    {
        $requirement = app(GetPackageRequirementDetails::class)->handle($requirementId);

        $this->deleteRequirementId = $requirement->id;
        $this->deleteRequirementLabel = $requirement->label;
        $this->showDeleteRequirementModal = true;
    }

    public function cancelDeleteRequirement(): void
    {
        $this->reset(['showDeleteRequirementModal', 'deleteRequirementId', 'deleteRequirementLabel']);
    }

    public function deleteRequirement(?int $requirementId = null): void
    {
        $requirementId = $requirementId ?? $this->deleteRequirementId;

        if ($requirementId === null) {
            return;
        }

        app(DeletePackageRequirement::class)->handle($requirementId);

        if ($this->editingRequirementId === $requirementId) {
            $this->resetRequirementForm();
        }

        $this->cancelDeleteRequirement();
    }

    public function resetPackageForm(): void
    {
        $this->reset([
            'editingPackageId',
            'packageCategoryId',
            'packageName',
            'packageDescription',
            'packageOrder',
            'packageIcon',
            'packageImageFile',
            'packageIsActive',
            'packageFulfillmentProvider',
            'packageApi',
        ]);
        $this->resetValidation();
    }

    public function resetRequirementForm(): void
    {
        $this->reset([
            'editingRequirementId',
            'requirementKey',
            'requirementLabel',
            'requirementType',
            'requirementIsRequired',
            'requirementValidationRules',
            'requirementOrder',
        ]);
        $this->resetValidation();
    }

    public function getPackagesProperty(): LengthAwarePaginator
    {
        return app(GetPackages::class)->handle(
            $this->search,
            $this->statusFilter,
            $this->sortBy,
            $this->sortDirection,
            $this->perPage
        );
    }

    public function getCategoriesProperty(): Collection
    {
        return app(GetPackageCategories::class)->handle();
    }

    public function getSelectedPackageProperty(): ?Package
    {
        return app(GetPackageDetails::class)->handle($this->selectedPackageId);
    }

    public function getRequirementsProperty(): Collection
    {
        return app(GetPackageRequirements::class)->handle($this->selectedPackageId);
    }

    public function getPackageOrderPlaceholderProperty(): string
    {
        $range = Package::query()
            ->selectRaw('MIN(`order`) as min_order, MAX(`order`) as max_order')
            ->first();

        if ($range === null || $range->min_order === null || $range->max_order === null) {
            return __('messages.order_placeholder');
        }

        return __('messages.order_range_placeholder', [
            'min' => $range->min_order,
            'max' => $range->max_order,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function getRequirementKeyOptionsProperty(): array
    {
        return [
            'id' => __('messages.requirement_key_id'),
            'username' => __('messages.requirement_key_username'),
            'phone' => __('messages.requirement_key_phone'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getRequirementTypeOptionsProperty(): array
    {
        return [
            'string' => __('messages.requirement_type_string'),
            'number' => __('messages.requirement_type_number'),
            'select' => __('messages.requirement_type_select'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getFulfillmentProviderOptionsProperty(): array
    {
        return $this->fulfillmentProviderOptions();
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.packages'));
    }
};
?>

<div
    class="flex h-full w-full flex-1 flex-col gap-6"
    x-data="{
        showFilters: false,
        showPackageForm: false,
        showRequirementForm: false,
        togglePackageForm() {
            if (this.showPackageForm) {
                this.closePackageForm();
                return;
            }

            this.showPackageForm = true;
        },
        closePackageForm() {
            this.showPackageForm = false;
            $wire.resetPackageForm();
        },
        toggleRequirementForm() {
            if (this.showRequirementForm) {
                this.showRequirementForm = false;
                $wire.resetRequirementForm();
                return;
            }

            this.showRequirementForm = true;
        },
    }"
    {{-- Alpine keeps UI-only panel state off the network; moving this to Livewire adds extra requests. --}}
    x-on:package-saved.window="closePackageForm()"
    x-on:open-package-panel.window="showPackageForm = true"
    x-on:requirement-saved.window="showRequirementForm = false"
    x-on:open-requirement-panel.window="showRequirementForm = true"
    data-test="packages-page"
>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon icon="cube" class="size-5" />
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col gap-1">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.packages') }}
                        </flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.packages_intro') }}
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400" role="status" aria-live="polite">
                        <span>{{ __('messages.showing') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $this->packages->count() }}
                        </span>
                        <span>{{ __('messages.of') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $this->packages->total() }}
                        </span>
                        <span>{{ __('messages.packages') }}</span>
                        @if ($statusFilter !== 'all')
                            <flux:badge class="capitalize">
                                {{ $statusFilter === 'active' ? __('messages.active') : __('messages.inactive_status') }}
                            </flux:badge>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    type="button"
                    variant="primary"
                    icon="plus"
                    class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    x-on:click="togglePackageForm()"
                >
                    {{ __('messages.new_package') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="adjustments-horizontal"
                    x-on:click="showFilters = !showFilters"
                >
                    {{ __('messages.filters') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="arrow-path"
                    wire:click="$refresh"
                    wire:loading.attr="disabled"
                >
                    {{ __('messages.refresh') }}
                </flux:button>
            </div>
        </div>

        <form
            class="grid gap-4 pt-4"
            wire:submit.prevent="applyFilters"
            x-show="showFilters"
            x-cloak
            data-test="packages-filters"
        >
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-2">
                    <flux:input
                        name="search"
                        :label="__('messages.search')"
                        :placeholder="__('messages.search_placeholder')"
                        wire:model.defer="search"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="sortBy" label="{{ __('messages.sort_by') }}" wire:model.defer="sortBy">
                        <flux:select.option value="order">{{ __('messages.order') }}</flux:select.option>
                        <flux:select.option value="name">{{ __('messages.name') }}</flux:select.option>
                        <flux:select.option value="created_at">{{ __('messages.created') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="sortDirection" label="{{ __('messages.direction') }}" wire:model.defer="sortDirection">
                        <flux:select.option value="asc">{{ __('messages.ascending') }}</flux:select.option>
                        <flux:select.option value="desc">{{ __('messages.descending') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="perPage" label="{{ __('messages.per_page') }}" wire:model.defer="perPage">
                        <flux:select.option value="10">10</flux:select.option>
                        <flux:select.option value="25">25</flux:select.option>
                        <flux:select.option value="50">50</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <div class="flex flex-wrap justify-between sm:justify-start gap-0 sm:gap-3">
                <flux:select
                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    name="statusFilter"
                    label="{{ __('messages.status') }}"
                    wire:model.defer="statusFilter"
                >
                    <flux:select.option value="all">{{ __('messages.all') }}</flux:select.option>
                    <flux:select.option value="active">{{ __('messages.active') }}</flux:select.option>
                    <flux:select.option value="inactive">{{ __('messages.inactive_status') }}</flux:select.option>
                </flux:select>
                <div class="flex w-full sm:w-auto justify-between flex-wrap mt-6 items-end gap-2">
                    <flux:button type="submit" variant="primary" icon="magnifying-glass">
                        {{ __('messages.apply') }}
                    </flux:button>
                    <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="resetFilters">
                        {{ __('messages.reset') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </section>

    <section
        class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        x-show="showPackageForm"
        x-cloak
    >
        <form class="grid gap-5" wire:submit.prevent="savePackage">
            <div class="flex flex-col gap-1">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                    {{ $editingPackageId ? __('messages.edit_package') : __('messages.create_package') }}
                </flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ $editingPackageId ? __('messages.edit_package_hint') : __('messages.package_slug_auto') }}
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageName"
                        label="{{ __('messages.name') }}"
                        placeholder="{{ __('messages.package_name_placeholder') }}"
                        wire:model.defer="packageName"
                    />
                </div>
                <div class="grid gap-2">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageCategoryId"
                        label="{{ __('messages.category') }}"
                        wire:model.defer="packageCategoryId"
                        placeholder="{{ __('messages.select_category') }}"
                    >
                        <flux:select.option value="">{{ __('messages.select_category') }}</flux:select.option>
                        @foreach ($this->categories as $category)
                            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageOrder"
                        label="{{ __('messages.order') }}"
                        type="number"
                        min="0"
                        step="1"
                        placeholder="{{ $this->packageOrderPlaceholder }}"
                        wire:model.defer="packageOrder"
                    />
                </div>
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageIcon"
                        label="{{ __('messages.icon') }}"
                        placeholder="{{ __('messages.icon_placeholder') }}"
                        wire:model.defer="packageIcon"
                    />
                    @error('packageIcon')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <flux:textarea
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageDescription"
                        label="{{ __('messages.description') }}"
                        placeholder="{{ __('messages.package_description_placeholder') }}"
                        rows="3"
                        wire:model.defer="packageDescription"
                    />
                    @error('packageDescription')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageImageFile"
                        label="{{ __('messages.image') }}"
                        type="file"
                        accept="image/*"
                        wire:model="packageImageFile"
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.package_image_hint') }}
                    </flux:text>
                    <flux:text
                        class="text-xs text-zinc-500 dark:text-zinc-400"
                        wire:loading
                        wire:target="packageImageFile"
                    >
                        {{ __('messages.uploading_image') }}
                    </flux:text>
                    @error('packageImageFile')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                    @if ($packageImageFile)
                        <div class="mt-2 flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="size-14 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                                <img
                                    src="{{ $packageImageFile->temporaryUrl() }}"
                                    alt="{{ __('messages.package_preview_alt') }}"
                                    class="size-full object-cover"
                                />
                            </div>
                            <div class="text-xs text-zinc-600 dark:text-zinc-300">
                                {{ __('messages.preview') }}
                            </div>
                        </div>
                    @endif
                </div>
                <div class="grid gap-2">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageFulfillmentProvider"
                        label="{{ __('messages.package_fulfillment_provider') }}"
                        wire:model.defer="packageFulfillmentProvider"
                    >
                        @foreach ($this->fulfillmentProviderOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('packageFulfillmentProvider')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2" x-show="$wire.packageFulfillmentProvider.startsWith('browser:')" x-cloak>
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageApi"
                        label="{{ __('messages.package_api') }}"
                        placeholder="{{ __('messages.package_api_placeholder') }}"
                        wire:model.defer="packageApi"
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.package_api_hint') }}
                    </flux:text>
                    @error('packageApi')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="flex items-center gap-3">
                    <flux:label>{{ __('messages.active') }}:</flux:label>
                    <flux:switch
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        wire:model.defer="packageIsActive"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    type="submit" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="savePackage">
                    {{ $editingPackageId ? __('messages.update_package') : __('messages.create_package') }}
                </flux:button>
                <flux:button
                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    type="button" variant="ghost" x-on:click="closePackageForm()">
                    {{ __('messages.cancel') }}
                </flux:button>
            </div>
        </form>
    </section>

    <div class="grid gap-6 lg:grid-cols-3 overflow-x-auto">
            <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.packages') }}
                    </flux:heading>
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ __('messages.total') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->packages->total() }}</span>
                    </div>
                </div>

                <div
                    class="overflow-x-auto"
                    wire:loading.class="opacity-60"
                    wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
                >
                    <div
                        class="p-6"
                        wire:loading.delay
                        wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
                    >
                        <div class="grid gap-3">
                            <flux:skeleton class="h-4 w-36" />
                            <div class="grid gap-2">
                                <flux:skeleton class="h-10 w-full" />
                                <flux:skeleton class="h-10 w-full" />
                                <flux:skeleton class="h-10 w-full" />
                                <flux:skeleton class="h-10 w-full" />
                                <flux:skeleton class="h-10 w-full" />
                            </div>
                        </div>
                    </div>
                    <div wire:loading.delay.remove wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage">
                        @if ($this->packages->count() === 0)
                            <div class="flex flex-col items-center gap-3 p-10 text-center">
                                <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                                    <flux:icon icon="cube" class="size-5" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                                        {{ __('messages.no_packages_yet') }}
                                    </flux:heading>
                                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                                        {{ __('messages.create_first_package') }}
                                    </flux:text>
                                </div>
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    icon="plus"
                                    class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                                    x-on:click="showPackageForm = true"
                                >
                                    {{ __('messages.add_package') }}
                                </flux:button>
                            </div>
                        @else
                            <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="packages-table">
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                                <tr>
                                    <th class="px-5 py-3 text-start font-semibold">{{ __('messages.package') }}</th>
                                    <th class="px-5 py-3 text-start font-semibold">{{ __('messages.category') }}</th>
                                    <th class="px-5 py-3 text-start font-semibold">{{ __('messages.order') }}</th>
                                    <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                    <th class="px-5 py-3 text-start font-semibold">{{ __('messages.requirements') }}</th>
                                    <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($this->packages as $package)
                                    <tr
                                        @class([
                                            'transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60',
                                            'bg-zinc-50/70 dark:bg-zinc-800/40' => $selectedPackageId === $package->id,
                                        ])
                                        wire:key="package-{{ $package->id }}"
                                    >
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="size-10 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800">
                                                    @if ($package->image)
                                                        <img
                                                            src="{{ asset($package->image) }}"
                                                            alt="{{ $package->name }}"
                                                            class="size-full object-cover"
                                                            loading="lazy"
                                                        />
                                                    @else
                                                        <div class="flex size-full items-center justify-center text-zinc-500 dark:text-zinc-300">
                                                            <flux:icon icon="photo" class="size-4" />
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="truncate font-semibold text-zinc-900 dark:text-zinc-100">
                                                            {{ $package->name }}
                                                        </span>
                                                        @if ($package->icon)
                                                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                                {{ $package->icon }}
                                                            </span>
                                                        @endif
                                                        @if ($package->fulfillment_provider && $package->fulfillment_provider !== 'manual')
                                                            <flux:badge size="sm" class="shrink-0">
                                                                {{ $this->fulfillmentProviderLabel($package->fulfillment_provider) }}
                                                            </flux:badge>
                                                        @endif
                                                    </div>
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                        @if ($package->category)
                                                            /{{ $package->category->slug }}/{{ $package->slug }}
                                                        @else
                                                            /{{ $package->slug }}
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                            {{ $package->category?->name ?? __('messages.no_category') }}
                                        </td>
                                        <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                            {{ $package->order }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-start">
                                                <flux:switch
                                                    :checked="$package->is_active"
                                                    wire:click="toggleStatus({{ $package->id }})"
                                                    aria-label="{{ __('messages.toggle_status_for', ['name' => $package->name]) }}"
                                                />
                                            </div>
                                        </td>
                                        <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                            {{ $package->requirements_count }}
                                        </td>
                                        <td class="px-5 py-4 text-end">
                                            <div class="flex items-center justify-end gap-2">
                                                <flux:button
                                                    variant="ghost"
                                                    icon="list-bullet"
                                                    wire:click="selectPackage({{ $package->id }})"
                                                >
                                                    {{ __('messages.requirements') }}
                                                </flux:button>
                                                <flux:dropdown position="bottom" align="end">
                                                    <flux:button variant="ghost" icon="ellipsis-vertical" />
                                                    <flux:menu>
                                                        <flux:menu.item icon="pencil" wire:click="startEditPackage({{ $package->id }})">
                                                            {{ __('messages.edit') }}
                                                        </flux:menu.item>
                                                        <flux:menu.item icon="eye">{{ __('messages.view') }}</flux:menu.item>
                                                        <flux:menu.separator />
                                                        <flux:menu.item
                                                            variant="danger"
                                                            icon="trash"
                                                            wire:click="confirmDeletePackage({{ $package->id }})"
                                                        >
                                                            {{ __('messages.delete') }}
                                                        </flux:menu.item>
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>

                <div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
                    {{ $this->packages->links() }}
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="space-y-1">
                            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                                {{ __('messages.requirements') }}
                            </flux:heading>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                {{ __('messages.requirements_intro') }}
                            </flux:text>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ __('messages.total') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $this->requirements->count() }}
                        </span>
                        </div>
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                            x-on:click="toggleRequirementForm()"
                            :disabled="$selectedPackageId === null"
                        >
                            {{ __('messages.add_requirement') }}
                        </flux:button>
                    </div>

                    {{-- Requirements are scoped to the selected package to prevent cross-package edits. --}}
                    @if ($this->selectedPackage)
                        <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $this->selectedPackage->name }}
                            </div>
                            <div class="mt-1 text-xs">
                                @if ($this->selectedPackage->category)
                                    /{{ $this->selectedPackage->category->slug }}/{{ $this->selectedPackage->slug }}
                                @else
                                    /{{ $this->selectedPackage->slug }}
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('messages.package_fulfillment_provider') }}:
                                {{ $this->fulfillmentProviderLabel($this->selectedPackage->fulfillment_provider) }}
                            </div>
                        </div>
                    @else
                        <flux:callout variant="subtle" icon="information-circle">
                            {{ __('messages.select_package_first') }}
                        </flux:callout>
                    @endif

                    <form class="grid gap-4" wire:submit.prevent="saveRequirement" x-show="showRequirementForm" x-cloak>
                        <div class="grid gap-3">
                            <div class="grid gap-2">
                                <flux:select
                                    name="requirementKey"
                                    label="{{ __('messages.requirement_key') }}"
                                    wire:model.defer="requirementKey"
                                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                >
                                    @foreach ($this->requirementKeyOptions as $key => $label)
                                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @error('requirementKey')
                                <flux:text color="red">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            <div class="grid gap-2">
                                <flux:input
                                    name="requirementLabel"
                                    label="{{ __('messages.requirement_label') }}"
                                    placeholder="{{ __('messages.requirement_label_placeholder') }}"
                                    wire:model.defer="requirementLabel"
                                    class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                />
                                @error('requirementLabel')
                                <flux:text color="red">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            <div class="grid gap-2">
                                <flux:select
                                    name="requirementType"
                                    label="{{ __('messages.requirement_type') }}"
                                    wire:model.defer="requirementType"
                                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                >
                                    @foreach ($this->requirementTypeOptions as $type => $label)
                                        <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @error('requirementType')
                                <flux:text color="red">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            <div class="grid gap-2">
                                <flux:input
                                    name="requirementValidationRules"
                                    label="{{ __('messages.validation_rules') }}"
                                    placeholder="{{ __('messages.validation_rules_placeholder') }}"
                                    wire:model.defer="requirementValidationRules"
                                    class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                />
                                {{-- Rules are stored verbatim; changing format impacts server-side validation later. --}}
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('messages.validation_rules_hint') }}
                                </flux:text>
                                @error('requirementValidationRules')
                                <flux:text color="red">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            <div class="grid gap-2">
                                <flux:input
                                    name="requirementOrder"
                                    label="{{ __('messages.order') }}"
                                    type="number"
                                    min="0"
                                    step="1"
                                    placeholder="{{ __('messages.order_placeholder') }}"
                                    wire:model.defer="requirementOrder"
                                    class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                />
                                @error('requirementOrder')
                                <flux:text color="red">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:label>{{ __('messages.is_required') }}:</flux:label>
                                <flux:switch
                                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                    wire:model.defer="requirementIsRequired"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button
                                class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                type="submit"
                                variant="primary"
                                icon="plus"
                                wire:loading.attr="disabled"
                                wire:target="saveRequirement"
                            >
                                {{ $editingRequirementId ? __('messages.update_requirement') : __('messages.create_requirement') }}
                            </flux:button>
                            <flux:button
                                class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                type="button"
                                variant="ghost"
                                x-on:click="toggleRequirementForm()"
                            >
                                {{ __('messages.cancel') }}
                            </flux:button>
                        </div>
                    </form>

                    <div class="space-y-3">
                        <div
                            class="space-y-3"
                            wire:loading.delay
                            wire:target="selectPackage,saveRequirement,deleteRequirement"
                        >
                            <flux:skeleton class="h-4 w-32" />
                            <flux:skeleton class="h-16 w-full" />
                            <flux:skeleton class="h-16 w-full" />
                        </div>
                        <div wire:loading.delay.remove>
                            @if ($this->requirements->count() === 0)
                                <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                    {{ __('messages.no_requirements_yet') }}
                                </div>
                            @else
                                <div class="grid gap-3">
                                    @foreach ($this->requirements as $requirement)
                                        <div
                                            class="rounded-xl border border-zinc-200 p-3 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300"
                                            wire:key="requirement-{{ $requirement->id }}"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="space-y-1">
                                                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                                        {{ $requirement->label }}
                                                    </div>
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                        {{ $requirement->key }} • {{ $this->requirementTypeOptions[$requirement->type] ?? $requirement->type }}
                                                        @if ($requirement->is_required)
                                                            • {{ __('messages.required') }}
                                                        @endif
                                                    </div>
                                                    @if ($requirement->validation_rules)
                                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                            {{ $requirement->validation_rules }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <flux:button variant="ghost" icon="pencil" wire:click="startEditRequirement({{ $requirement->id }})" />
                                                    <flux:button
                                                        variant="ghost"
                                                        icon="trash"
                                                        wire:click="confirmDeleteRequirement({{ $requirement->id }})"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
    </div>


    <flux:modal
        wire:model.self="showDeletePackageModal"
        class="max-w-md"
        variant="floating"
        @close="cancelDeletePackage"
        @cancel="cancelDeletePackage"
    >
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400">
                    <flux:icon icon="trash" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.delete_package_title') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.delete_package_body', ['name' => $deletePackageName]) }}
                    </flux:text>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelDeletePackage">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePackage"
                    wire:loading.attr="disabled"
                    wire:target="deletePackage"
                >
                    {{ __('messages.delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        wire:model.self="showDeleteRequirementModal"
        class="max-w-md"
        variant="floating"
        @close="cancelDeleteRequirement"
        @cancel="cancelDeleteRequirement"
    >
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <div class="flex size-11 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400">
                    <flux:icon icon="trash" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.delete_requirement_title') }}
                    </flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.delete_requirement_body', ['label' => $deleteRequirementLabel]) }}
                    </flux:text>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="cancelDeleteRequirement">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deleteRequirement"
                    wire:loading.attr="disabled"
                    wire:target="deleteRequirement"
                >
                    {{ __('messages.delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
