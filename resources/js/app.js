import '../../vendor/masmerise/livewire-toaster/resources/js';
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;

document.addEventListener('alpine:init', () => {
    const { Alpine } = window;

    if (! Alpine) {
        return;
    }

    Alpine.data('dashboardHeroChart', ({ chartSeries, activeRange, chartPresets, initialChartFilter }) => ({
        selectedSeries: 'earnings',
        activeRange,
        chartSeries,
        chartPresets: chartPresets ?? {},
        initialChartFilter: initialChartFilter ?? 'this_month',
        localChartFilter: initialChartFilter ?? 'this_month',
        chart: null,
        init() {
            this.localChartFilter = this.initialChartFilter ?? 'this_month';

            if (!window.ApexCharts || !this.$refs.heroChart) {
                return;
            }

            this.remountHeroChart();
        },
        clonePreset(series) {
            try {
                return structuredClone(series);
            } catch {
                return JSON.parse(JSON.stringify(series));
            }
        },
        /** Preset tabs: chart from cached JSON only (no Livewire). Leaving Livewire "custom" mode syncs server once (still cached, no DB). */
        applyPreset(key) {
            if (key === 'custom') {
                return;
            }

            const preset = this.chartPresets?.[key];
            if (!preset) {
                return;
            }

            this.chartSeries = this.clonePreset(preset);
            this.localChartFilter = key;
            this.remountHeroChart();

            if (this.$wire?.chartFilter === 'custom') {
                this.$wire.setChartFilter(key);
            }
        },
        remountHeroChart() {
            if (!window.ApexCharts || !this.$refs.heroChart) {
                return;
            }

            this.chart?.destroy();
            this.chart = new ApexCharts(this.$refs.heroChart, this.chartOptions());
            this.chart.render();
        },
        rangeLabel() {
            if (this.chartSeries?.rangeLabel) {
                return this.chartSeries.rangeLabel;
            }

            return this.activeRange === '7d'
                ? 'Past 7 days'
                : (this.activeRange === 'ytd' ? 'Year to date' : 'Past 30 days');
        },
        /** Mini-spark arrays for KPI tiles — same `sparkline` payload as the hero chart. */
        kpiSparkSl(metricKey) {
            const sl = this.chartSeries?.sparkline ?? {};
            const toNums = (row) => (Array.isArray(row) ? row.map((v) => Number(v)) : [0]);

            if (metricKey === 'orders') {
                return toNums(sl.orders);
            }

            if (metricKey === 'pending') {
                return toNums(sl.pending);
            }

            return toNums(sl.earnings);
        },
        kpiSparkGeometry(metricKey) {
            const arr = this.kpiSparkSl(metricKey);
            const w = 88;
            const h = 30;
            const min = Math.min(...arr);
            const max = Math.max(...arr);
            const range = Math.max(0.0001, max - min);
            const n = arr.length;
            const step = n > 1 ? w / (n - 1) : 0;
            const pts = arr
                .map((v, i) => {
                    const x = Math.round(i * step * 100) / 100;
                    const y = Math.round((h - ((v - min) / range) * h) * 100) / 100;

                    return `${x},${y}`;
                })
                .join(' ');

            return {
                polyline: pts,
                polygon: `0,${h} ${pts} ${w},${h}`,
            };
        },
        _sumSeries(arr) {
            return (Array.isArray(arr) ? arr : []).reduce((acc, x) => acc + Number(x), 0);
        },
        kpiBucketCount() {
            return Math.max(1, (this.chartSeries?.labels ?? []).length);
        },
        /** Headline KPI numbers match chart series totals for the active range (bucketed like the chart). */
        kpiRangeNumber(metricKey) {
            const e = this._sumSeries(this.chartSeries?.earnings);
            const o = this._sumSeries(this.chartSeries?.orders);
            const p = this._sumSeries(this.chartSeries?.pending);
            const n = this.kpiBucketCount();

            if (metricKey === 'orders') {
                return Math.round(o);
            }

            if (metricKey === 'pending') {
                return p;
            }

            if (metricKey === 'paid') {
                return e / n;
            }

            return e;
        },
        kpiDisplayText(metricKey) {
            const n = this.kpiRangeNumber(metricKey);

            if (metricKey === 'orders') {
                return String(Math.round(n));
            }

            return `$${Number(n).toFixed(2)}`;
        },
        toggleSeries(series) {
            this.selectedSeries = series;
            if (!this.chart) {
                return;
            }
            this.chart.updateOptions({
                colors: [this.seriesColor()],
                fill: {
                    gradient: {
                        colorStops: [
                            [{ offset: 0, color: this.seriesColor(), opacity: 0.34 }, { offset: 100, color: this.seriesColor(), opacity: 0.02 }],
                        ],
                    },
                },
            }, false, true);
            this.chart.updateSeries([{ name: this.seriesLabel(), data: this.currentSeries() }], true);
        },
        currentSeries() {
            return this.selectedSeries === 'orders'
                ? (this.chartSeries?.orders ?? [])
                : (this.chartSeries?.earnings ?? []);
        },
        seriesColor() {
            return this.selectedSeries === 'orders'
                ? 'hsl(var(--accent-orders))'
                : 'hsl(var(--accent-earnings))';
        },
        seriesLabel() {
            return this.selectedSeries === 'orders' ? 'Orders' : 'Earnings';
        },
        labelCount() {
            return Array.isArray(this.chartSeries?.labels) ? this.chartSeries.labels.length : 0;
        },
        xAxisLabelRotate() {
            const n = this.labelCount();

            return n > 14 ? -45 : (n > 8 ? -40 : 0);
        },
        chartOptions() {
            const rotate = this.xAxisLabelRotate();

            return {
                chart: {
                    type: 'area',
                    height: 208,
                    foreColor: 'hsl(var(--foreground) / 0.78)',
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    animations: { enabled: true, speed: 480, easing: 'easeinout' },
                    fontFamily: 'inherit',
                },
                series: [{ name: this.seriesLabel(), data: this.currentSeries() }],
                colors: [this.seriesColor()],
                stroke: {
                    curve: 'smooth',
                    width: 2.4,
                    lineCap: 'round',
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 0.4,
                        colorStops: [
                            [{ offset: 0, color: this.seriesColor(), opacity: 0.4 }, { offset: 100, color: this.seriesColor(), opacity: 0.01 }],
                        ],
                    },
                },
                dataLabels: { enabled: false },
                grid: {
                    show: true,
                    borderColor: 'hsl(var(--surface-3) / 0.28)',
                    strokeDashArray: 4,
                    padding: { left: 4, right: 6, top: 6, bottom: rotate !== 0 ? 8 : 2 },
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                },
                xaxis: {
                    categories: this.chartSeries?.labels ?? [],
                    labels: {
                        show: true,
                        rotate,
                        rotateAlways: rotate !== 0,
                        style: {
                            colors: 'hsl(var(--foreground) / 0.82)',
                            fontSize: '11px',
                            fontWeight: 500,
                        },
                        hideOverlappingLabels: rotate === 0,
                        maxHeight: rotate !== 0 ? 120 : 36,
                    },
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                },
                yaxis: {
                    labels: {
                        style: {
                            colors: 'hsl(var(--foreground) / 0.76)',
                            fontSize: '11px',
                            fontWeight: 500,
                        },
                        formatter: (value) => this.selectedSeries === 'orders'
                            ? Math.round(value).toString()
                            : `$${Number(value).toFixed(2)}`,
                    },
                },
                tooltip: {
                    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                    marker: { show: false },
                    custom: ({ dataPointIndex }) => {
                        const label = this.chartSeries?.labels?.[dataPointIndex] ?? '—';
                        const raw = this.currentSeries()?.[dataPointIndex] ?? 0;
                        const value = this.selectedSeries === 'orders'
                            ? `${Math.round(Number(raw))}`
                            : `$${Number(raw).toFixed(2)}`;
                        const seriesLabel = this.seriesLabel();

                        return `<div class="rounded-xl border border-[hsl(var(--foreground)/0.12)] bg-[hsl(var(--surface-2))] px-3 py-2 shadow-lg backdrop-blur-sm">
                            <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-[hsl(var(--foreground)/0.55)]">${label}</p>
                            <p class="mt-1 text-xs text-[hsl(var(--foreground)/0.72)]">${seriesLabel}</p>
                            <p class="tabular-nums text-sm font-semibold text-[hsl(var(--foreground))]">${value}</p>
                        </div>`;
                    },
                },
                legend: { show: false },
                responsive: [{
                    breakpoint: 768,
                    options: {
                        xaxis: {
                            labels: {
                                rotate: this.labelCount() > 10 ? -45 : 0,
                                rotateAlways: this.labelCount() > 10,
                                hideOverlappingLabels: this.labelCount() <= 10,
                                maxHeight: this.labelCount() > 10 ? 120 : 36,
                            },
                        },
                        stroke: { width: 2.2 },
                    },
                }],
            };
        },
    }));

    /** Attributed orders: filter + group in-browser (no Livewire on search/status/range). */
    Alpine.data('salespersonOrdersTable', ({ orders, strings, serverToday }) => ({
        orders: Array.isArray(orders) ? orders : [],
        strings: strings ?? {},
        serverToday: serverToday ?? '',
        search: '',
        statusFilter: 'all',
        dateRange: '30d',
        rowTime(row) {
            const raw = row?.date ?? '';

            return Date.parse(String(raw).replace(' ', 'T')) || 0;
        },
        cutoffMs() {
            const now = Date.now();

            if (this.dateRange === '7d') {
                return now - 7 * 86400000;
            }

            if (this.dateRange === 'ytd') {
                const d = new Date();

                d.setHours(0, 0, 0, 0);
                d.setMonth(0, 1);

                return d.getTime();
            }

            return now - 30 * 86400000;
        },
        filteredOrders() {
            const t0 = this.cutoffMs();
            let list = this.orders.filter((row) => this.rowTime(row) >= t0);

            if (this.statusFilter !== 'all') {
                list = list.filter((row) => row.commission_status === this.statusFilter);
            }

            const q = this.search.trim().toLowerCase();

            if (q !== '') {
                list = list.filter((row) => {
                    const hay = `${row.order ?? ''} ${row.product ?? ''} ${row.customer_name ?? ''} ${row.customer_phone ?? ''} ${row.customer_username ?? ''} ${row.customer ?? ''}`.toLowerCase();

                    return hay.includes(q);
                });
            }

            return list.sort((a, b) => this.rowTime(b) - this.rowTime(a));
        },
        groupedOrders() {
            const byDay = {};

            for (const row of this.filteredOrders()) {
                const key = String(row.date ?? '').substring(0, 10);

                if (key.length !== 10) {
                    continue;
                }

                if (!byDay[key]) {
                    byDay[key] = [];
                }

                byDay[key].push(row);
            }

            return Object.keys(byDay)
                .sort((a, b) => b.localeCompare(a))
                .map((key) => {
                    const rows = byDay[key];
                    const commission = rows.reduce((sum, r) => sum + Number(r.commission ?? 0), 0);
                    const label = key === this.serverToday
                        ? (this.strings.today ?? 'Today')
                        : this.formatDayLabel(key);

                    return { key, label, count: rows.length, commission, rows };
                });
        },
        formatDayLabel(ymd) {
            const parts = ymd.split('-').map((x) => parseInt(x, 10));

            if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
                return ymd;
            }

            const [y, mo, da] = parts;
            const dt = new Date(y, mo - 1, da);
            const lang = document.documentElement.lang || 'en';

            return dt.toLocaleDateString(lang, { month: 'short', day: 'numeric' });
        },
        commissionLabel(status) {
            return this.strings.commission_status?.[status] ?? status;
        },
        fulfillmentLabel(key) {
            return this.strings.fulfillment_status?.[key] ?? key;
        },
        money(n) {
            return `$${Number(n ?? 0).toFixed(2)}`;
        },
    }));

    /**
     * Shared custom-amount pricing estimate.
     * When `pricingContext.client_pricable` + rules are present, tier math runs in Alpine (matches BuyNowClientPricingContext::previewFinalPrice).
     * Otherwise uses server `rate` × amount (overrides, legacy).
     *
     * @param {object} ctx Alpine component `this` with amountInputStr, rate, pricingContext, serverError, amountMin, amountMax, amountStep, messages
     */
    const customAmountEstimate = {
        round2(value) {
            return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
        },

        bankersRound(value, decimals) {
            const m = 10 ** decimals;
            const x = value * m;
            const f = Math.floor(x);
            const frac = x - f;
            if (Math.abs(frac - 0.5) < 1e-9) {
                return (f % 2 === 0 ? f : f + 1) / m;
            }

            return Math.round(x) / m;
        },

        multiplyAmountByEntryPrice(amount, entryPrice) {
            const ep = Number(entryPrice);
            if (! Number.isFinite(ep) || ep <= 0) {
                return NaN;
            }
            const b = ep.toFixed(6);
            const result = parseFloat(b) * parseInt(String(amount), 10);

            return Math.round(result * 1e6) / 1e6;
        },

        resolvePricingRule(entryTotal, rules) {
            if (! Array.isArray(rules)) {
                return null;
            }
            for (const rule of rules) {
                const min = Number(rule.min);
                const max = Number(rule.max);
                if (min <= entryTotal && max > entryTotal) {
                    return rule;
                }
            }

            return null;
        },

        computeBuyNowFinal(pricingContext, amount) {
            const entry = Number(pricingContext?.entry_price_per_unit);
            if (! Number.isFinite(entry) || entry <= 0) {
                return null;
            }
            const computedEntryTotal = customAmountEstimate.multiplyAmountByEntryPrice(amount, entry);
            if (Number.isNaN(computedEntryTotal)) {
                return null;
            }
            const rule = customAmountEstimate.resolvePricingRule(computedEntryTotal, pricingContext.rules);
            if (! rule) {
                return null;
            }
            const useWholesale = !! pricingContext.use_wholesale;
            const pct = useWholesale ? Number(rule.wholesale_pct) : Number(rule.retail_pct);
            const basePrice = customAmountEstimate.bankersRound(computedEntryTotal * (1 + pct / 100), 2);
            const loyaltyPct = Number(pricingContext.loyalty_discount_percent ?? 0);
            const discountAmount = customAmountEstimate.bankersRound(basePrice * loyaltyPct / 100, 2);
            let finalPrice = customAmountEstimate.bankersRound(basePrice - discountAmount, 2);
            if (finalPrice < computedEntryTotal) {
                finalPrice = customAmountEstimate.bankersRound(computedEntryTotal, 2);
            }

            return finalPrice;
        },

        digitsParsed(ctx) {
            const n = parseInt(String(ctx.amountInputStr).replace(/\D/g, ''), 10);

            return Number.isNaN(n) ? 0 : n;
        },

        amountInvalidReason(ctx) {
            const a = customAmountEstimate.digitsParsed(ctx);
            if (a < 1) {
                return 'empty';
            }
            if (ctx.amountMin !== null && ctx.amountMin !== undefined && a < ctx.amountMin) {
                return 'below_min';
            }
            if (ctx.amountMax !== null && ctx.amountMax !== undefined && a > ctx.amountMax) {
                return 'above_max';
            }
            if (ctx.amountStep > 1 && a % ctx.amountStep !== 0) {
                return 'bad_step';
            }

            return null;
        },

        amountValid(ctx) {
            return customAmountEstimate.amountInvalidReason(ctx) === null;
        },

        estimatedFinal(ctx) {
            if (ctx.serverError) {
                return null;
            }
            if (! customAmountEstimate.amountValid(ctx)) {
                return null;
            }

            const pc = ctx.pricingContext;
            if (pc && pc.client_pricable && Array.isArray(pc.rules) && pc.rules.length > 0) {
                const final = customAmountEstimate.computeBuyNowFinal(pc, customAmountEstimate.digitsParsed(ctx));

                return final === null ? null : customAmountEstimate.round2(final);
            }

            if (ctx.rate === null || ctx.rate === undefined) {
                return null;
            }

            const raw = customAmountEstimate.digitsParsed(ctx) * Number(ctx.rate);

            return customAmountEstimate.round2(raw);
        },

        estimateHintText(ctx) {
            if (ctx.serverError) {
                return '';
            }
            if (customAmountEstimate.estimatedFinal(ctx) !== null) {
                return '';
            }
            const msgs = ctx.messages ?? {};
            const reason = customAmountEstimate.amountInvalidReason(ctx);
            if (reason === 'empty') {
                return msgs.enter_amount ?? '—';
            }
            if (reason === 'below_min') {
                return msgs.below_min ?? '—';
            }
            if (reason === 'above_max') {
                return msgs.above_max ?? '—';
            }
            if (reason === 'bad_step') {
                return msgs.bad_step ?? '—';
            }
            if (customAmountEstimate.amountValid(ctx)) {
                const pc = ctx.pricingContext;
                if (pc && pc.client_pricable && Array.isArray(pc.rules) && pc.rules.length > 0) {
                    const n = customAmountEstimate.digitsParsed(ctx);
                    if (customAmountEstimate.computeBuyNowFinal(pc, n) === null) {
                        return msgs.price_unavailable ?? '—';
                    }
                }
            }
            if (customAmountEstimate.amountValid(ctx) && (ctx.rate === null || ctx.rate === undefined)) {
                return msgs.price_unavailable ?? '—';
            }

            return '—';
        },

        estimateCardEmphasis(ctx) {
            if (ctx.serverError) {
                return 'error';
            }
            if (customAmountEstimate.estimatedFinal(ctx) !== null) {
                return 'success';
            }
            const r = customAmountEstimate.amountInvalidReason(ctx);
            if (r === 'below_min' || r === 'above_max' || r === 'bad_step') {
                return 'attention';
            }
            if (customAmountEstimate.amountValid(ctx)) {
                const pc = ctx.pricingContext;
                if (pc && pc.client_pricable && Array.isArray(pc.rules) && pc.rules.length > 0) {
                    const n = customAmountEstimate.digitsParsed(ctx);
                    if (customAmountEstimate.computeBuyNowFinal(pc, n) === null) {
                        return 'attention';
                    }
                }
            }
            if (customAmountEstimate.amountValid(ctx) && (ctx.rate === null || ctx.rate === undefined)) {
                return 'attention';
            }

            return 'neutral';
        },

        formatMoney(value) {
            if (value === null || value === undefined) {
                return '—';
            }

            return displayCurrency.format(value);
        },

        formatPerUnitRate(value) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) {
                return '—';
            }

            return displayCurrency.formatPerUnit(Number(value));
        },
    };

    const displayCurrency = {
        preferred() {
            const code = String(window.Laravel?.preferredCurrency ?? 'USD').toUpperCase();

            return code === 'TRY' ? 'TRY' : 'USD';
        },

        rate() {
            const rate = Number(window.Laravel?.usdTryRate ?? null);

            return Number.isFinite(rate) && rate > 0 ? rate : null;
        },

        activeCode() {
            if (displayCurrency.preferred() === 'TRY' && displayCurrency.rate() !== null) {
                return 'TRY';
            }

            return 'USD';
        },

        convert(usdValue) {
            const n = Number(usdValue);
            if (! Number.isFinite(n)) {
                return 0;
            }
            if (displayCurrency.activeCode() === 'TRY') {
                return n * Number(displayCurrency.rate());
            }

            return n;
        },

        format(usdValue) {
            const code = displayCurrency.activeCode();
            const locale = code === 'TRY' ? 'tr-TR' : 'en-US';
            const value = displayCurrency.convert(usdValue);

            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: code,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(value);
        },

        formatPerUnit(usdValue) {
            const code = displayCurrency.activeCode();
            const locale = code === 'TRY' ? 'tr-TR' : 'en-US';
            const value = displayCurrency.convert(usdValue);

            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: code,
                minimumFractionDigits: 2,
                maximumFractionDigits: 6,
            }).format(value);
        },
    };

    Alpine.data('buyNowCustomAmountEstimate', (config) => ({
        amountInputStr: config.amountInputStr ?? '',
        rate: config.rate,
        pricingContext: config.pricingContext ?? null,
        serverError: config.serverError ?? null,
        amountMin: config.amountMin ?? null,
        amountMax: config.amountMax ?? null,
        amountStep: Math.max(1, Number(config.amountStep ?? 1)),
        maskDec: config.maskDec ?? '.',
        maskThousands: config.maskThousands ?? ',',
        messages: config.messages ?? {},

        get digitsParsed() {
            return customAmountEstimate.digitsParsed(this);
        },

        get amountInvalidReason() {
            return customAmountEstimate.amountInvalidReason(this);
        },

        get amountValid() {
            return customAmountEstimate.amountValid(this);
        },

        /**
         * Per-unit rate for Pay Now: client tier math when pricable, else server rate.
         */
        get payableRate() {
            const pc = this.pricingContext;
            if (pc && pc.client_pricable && Array.isArray(pc.rules) && pc.rules.length > 0) {
                const amt = customAmountEstimate.digitsParsed(this);
                if (amt < 1) {
                    return null;
                }
                const final = customAmountEstimate.computeBuyNowFinal(pc, amt);

                return final === null ? null : final / amt;
            }

            return this.rate;
        },

        get estimatedFinal() {
            return customAmountEstimate.estimatedFinal(this);
        },

        get estimateHintText() {
            return customAmountEstimate.estimateHintText(this);
        },

        get estimateCardEmphasis() {
            return customAmountEstimate.estimateCardEmphasis(this);
        },

        formatMoney(value) {
            return customAmountEstimate.formatMoney(value);
        },
    }));

    Alpine.data('packageOverlayCustomAmountRow', (config) => ({
        product: config.product,
        amountInputStr: config.amountInputStr ?? '',
        rate: config.rate,
        pricingContext: config.pricingContext ?? null,
        serverError: config.serverError ?? null,
        amountMin: config.amountMin ?? null,
        amountMax: config.amountMax ?? null,
        amountStep: Math.max(1, Number(config.amountStep ?? 1)),
        maskDec: config.maskDec ?? '.',
        maskThousands: config.maskThousands ?? ',',
        messages: config.messages ?? {},
        qty: 1,

        init() {
            if (config.initialAmount != null && config.initialAmount !== undefined) {
                this.amountInputStr = Alpine.store('cart').formatGroupedIntegerForAmount(Number(config.initialAmount));
            }
        },

        get digitsParsed() {
            return customAmountEstimate.digitsParsed(this);
        },

        get amountInvalidReason() {
            return customAmountEstimate.amountInvalidReason(this);
        },

        get amountValid() {
            return customAmountEstimate.amountValid(this);
        },

        get payableRate() {
            const pc = this.pricingContext;
            if (pc && pc.client_pricable && Array.isArray(pc.rules) && pc.rules.length > 0) {
                const amt = customAmountEstimate.digitsParsed(this);
                if (amt < 1) {
                    return null;
                }
                const final = customAmountEstimate.computeBuyNowFinal(pc, amt);

                return final === null ? null : final / amt;
            }

            return this.rate;
        },

        get estimatedFinal() {
            return customAmountEstimate.estimatedFinal(this);
        },

        get estimateHintText() {
            return customAmountEstimate.estimateHintText(this);
        },

        get estimateCardEmphasis() {
            return customAmountEstimate.estimateCardEmphasis(this);
        },

        formatMoney(value) {
            return customAmountEstimate.formatMoney(value);
        },

        formatPerUnitRate(value) {
            return customAmountEstimate.formatPerUnitRate(value);
        },
    }));

    Alpine.store('cart', {
        storageKey: 'karman.cart.v1',
        hydrated: false,
        items: [],
        validationMessages: {},
        serverRequirementErrors: {},
        customAmountErrors: {},
        /** When false, empty required package fields do not show inline errors until checkout is attempted. */
        showCartRequirementErrors: false,
        addToCartMessageTemplate: 'Added :name to cart',

        init() {
            if (this.hydrated) {
                return;
            }

            this.items = this.read();
            if (typeof window.__addToCartMessageTemplate !== 'undefined') {
                this.addToCartMessageTemplate = window.__addToCartMessageTemplate;
            }
            this.hydrated = true;
        },
        formatGroupedIntegerForAmount(value) {
            const thousands = window.Laravel?.amountIntegerMask?.thousands ?? ',';
            const raw = Math.max(0, parseInt(String(value ?? '').replace(/\D/g, ''), 10) || 0);
            const digits = String(raw);

            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
        },
        setValidationMessages(messages) {
            this.validationMessages = messages ?? {};
        },
        formatMessage(template, params = {}) {
            if (! template) {
                return '';
            }

            return Object.entries(params).reduce((message, [key, value]) => {
                return message.replace(`:${key}`, value);
            }, template);
        },
        read() {
            try {
                const parsed = JSON.parse(localStorage.getItem(this.storageKey) ?? '[]');

                if (! Array.isArray(parsed)) {
                    return [];
                }

                return parsed.map((item) => {
                    if (item?.amount_mode !== 'custom') {
                        return item;
                    }

                    const requested = item.requested_amount ?? (item.custom_amount_min ?? 1);
                    const linePrice = Number(item.price ?? 0);
                    let customUnitRate = item.custom_unit_rate;
                    if (
                        (customUnitRate === null || customUnitRate === undefined)
                        && linePrice > 0
                        && requested > 0
                    ) {
                        customUnitRate = Math.round((linePrice / requested) * 1e8) / 1e8;
                    }

                    return {
                        ...item,
                        quantity: 1,
                        custom_amount_step: Math.max(1, parseInt(item.custom_amount_step ?? 1, 10)),
                        requested_amount: requested,
                        requested_amount_input: this.formatGroupedIntegerForAmount(requested),
                        custom_unit_rate: customUnitRate,
                    };
                });
            } catch (error) {
                return [];
            }
        },
        persist() {
            const serializable = this.items.map((item) => {
                if (item?.requested_amount_input === undefined) {
                    return item;
                }

                const { requested_amount_input: _strip, ...rest } = item;

                return rest;
            });

            localStorage.setItem(this.storageKey, JSON.stringify(serializable));
        },
        add(product, quantity = 1) {
            if (! product?.id) {
                return;
            }

            const amountMode = product.amount_mode ?? 'fixed';
            const normalizedQuantity = Math.max(1, parseInt(quantity ?? 1, 10));
            const existing = this.items.find((item) => item.id === product.id);

            if (existing) {
                if (amountMode !== 'custom') {
                    existing.quantity += normalizedQuantity;
                }
            } else {
                const requirementsSchema = Array.isArray(product.requirements_schema)
                    ? product.requirements_schema
                    : [];
                const requirements = {};
                const normalizedStep = Math.max(1, parseInt(product.custom_amount_step ?? 1, 10));
                const normalizedMin = product.custom_amount_min !== null && product.custom_amount_min !== undefined
                    ? Math.max(1, parseInt(product.custom_amount_min, 10))
                    : null;
                const normalizedMax = product.custom_amount_max !== null && product.custom_amount_max !== undefined
                    ? Math.max(1, parseInt(product.custom_amount_max, 10))
                    : null;
                const defaultRequestedAmount = amountMode === 'custom'
                    ? (normalizedMin ?? normalizedStep)
                    : null;

                requirementsSchema.forEach((requirement) => {
                    if (requirement?.key) {
                        requirements[requirement.key] = '';
                    }
                });

                const linePrice = Number(product.price ?? 0);
                const customUnitRate =
                    amountMode === 'custom'
                    && defaultRequestedAmount !== null
                    && defaultRequestedAmount > 0
                    && linePrice > 0
                        ? Math.round((linePrice / defaultRequestedAmount) * 1e8) / 1e8
                        : undefined;

                this.items.push({
                    id: product.id,
                    name: product.name,
                    price: linePrice,
                    discount_amount: Number(product.discount_amount ?? 0),
                    tier_name: product.tier_name ?? null,
                    image: product.image,
                    href: product.href,
                    quantity: amountMode === 'custom' ? 1 : normalizedQuantity,
                    amount_mode: amountMode,
                    requested_amount: defaultRequestedAmount,
                    requested_amount_input:
                        amountMode === 'custom'
                            ? this.formatGroupedIntegerForAmount(defaultRequestedAmount)
                            : undefined,
                    custom_unit_rate: customUnitRate,
                    amount_unit_label: product.amount_unit_label ?? null,
                    custom_amount_min: normalizedMin,
                    custom_amount_max: normalizedMax,
                    custom_amount_step: normalizedStep,
                    package_id: product.package_id ?? null,
                    requirements,
                    requirements_schema: requirementsSchema,
                });
            }

            this.persist();

            if (product?.name) {
                const message = this.formatMessage(this.addToCartMessageTemplate, { name: product.name });
                window.Toaster?.success(message);
                window.dispatchEvent(new CustomEvent('cart-item-added', {
                    detail: {
                        name: product.name,
                    },
                }));
            }
        },
        setServerRequirementError(payload) {
            const productId = payload?.productId ?? payload?.product_id ?? null;
            const key = payload?.key ?? null;

            if (! productId || ! key) {
                return;
            }

            if (! this.serverRequirementErrors[productId]) {
                this.serverRequirementErrors[productId] = {};
            }

            if (! payload.message) {
                delete this.serverRequirementErrors[productId][key];
                return;
            }

            this.serverRequirementErrors[productId][key] = payload.message;
        },
        setServerRequirementErrors(errors) {
            this.serverRequirementErrors = errors ?? {};
        },
        clearServerRequirementError(productId, key) {
            if (! this.serverRequirementErrors?.[productId]?.[key]) {
                return;
            }

            delete this.serverRequirementErrors[productId][key];
        },
        updateRequirement(id, key, value) {
            const item = this.items.find((entry) => entry.id === id);

            if (! item) {
                return;
            }

            if (! item.requirements) {
                item.requirements = {};
            }

            item.requirements[key] = value;
            this.clearServerRequirementError(id, key);
            this.persist();
        },
        isEmptyValue(value) {
            if (value === null || value === undefined) {
                return true;
            }

            return String(value).trim() === '';
        },
        parseValidationRules(requirement) {
            const rules = [];

            if (requirement?.is_required) {
                rules.push('required');
            }

            if (requirement?.type === 'number') {
                rules.push('numeric');
            }

            if (typeof requirement?.validation_rules === 'string' && requirement.validation_rules.trim() !== '') {
                const extraRules = requirement.validation_rules
                    .split('|')
                    .map((rule) => rule.trim())
                    .filter((rule) => rule !== '');

                rules.push(...extraRules);
            }

            return Array.from(new Set(rules));
        },
        isRequirementMissing(item, requirement) {
            const rules = this.parseValidationRules(requirement);

            if (! rules.includes('required')) {
                return false;
            }

            const value = item?.requirements?.[requirement?.key];

            return this.isEmptyValue(value);
        },
        isRequirementInvalid(item, requirement) {
            if (this.isRequirementMissing(item, requirement)) {
                return false;
            }

            const rules = this.parseValidationRules(requirement).filter((rule) => rule !== 'required');
            const value = item?.requirements?.[requirement?.key];

            if (this.isEmptyValue(value)) {
                return false;
            }

            const stringValue = String(value);
            const numericValue = Number(value);
            const isNumeric = ! Number.isNaN(numericValue);
            const hasNumericRule = rules.includes('numeric') || requirement?.type === 'number';

            for (const rule of rules) {
                if (rule === 'string') {
                    continue;
                }

                if (rule === 'numeric' && ! isNumeric) {
                    return true;
                }

                if (rule.startsWith('min_digits:')) {
                    const limit = Number(rule.split(':')[1]);
                    const digits = stringValue.replace(/\D/g, '').length;
                    if (! Number.isNaN(limit) && digits < limit) {
                        return true;
                    }
                }

                if (rule.startsWith('max_digits:')) {
                    const limit = Number(rule.split(':')[1]);
                    const digits = stringValue.replace(/\D/g, '').length;
                    if (! Number.isNaN(limit) && digits > limit) {
                        return true;
                    }
                }

                if (rule.startsWith('min:')) {
                    const limit = Number(rule.split(':')[1]);
                    if (! Number.isNaN(limit)) {
                        const result = hasNumericRule
                            ? numericValue < limit
                            : stringValue.length < limit;
                        if (result) {
                            return true;
                        }
                    }
                }

                if (rule.startsWith('max:')) {
                    const limit = Number(rule.split(':')[1]);
                    if (! Number.isNaN(limit)) {
                        const result = hasNumericRule
                            ? numericValue > limit
                            : stringValue.length > limit;
                        if (result) {
                            return true;
                        }
                    }
                }

                if (rule.startsWith('in:')) {
                    const allowed = rule
                        .slice(3)
                        .split(',')
                        .map((value) => value.trim());
                    if (! allowed.includes(stringValue)) {
                        return true;
                    }
                }

                if (rule.startsWith('regex:') || rule.startsWith('not_regex:')) {
                    const isNot = rule.startsWith('not_regex:');
                    const raw = rule.replace(/^not_regex:|^regex:/, '');
                    let regex = null;

                    try {
                        const match = raw.match(/^\/(.+)\/([a-z]*)$/i);
                        regex = match ? new RegExp(match[1], match[2]) : new RegExp(raw);
                    } catch (error) {
                        continue;
                    }

                    const matches = regex.test(stringValue);

                    if (isNot ? matches : ! matches) {
                        return true;
                    }
                }
            }

            return false;
        },
        getRequirementError(item, requirement) {
            const fieldLabel = requirement?.label || requirement?.key || '';
            const serverError = this.serverRequirementErrors?.[item?.id]?.[requirement?.key];

            if (serverError) {
                return serverError;
            }

            if (this.isRequirementMissing(item, requirement)) {
                if (! this.showCartRequirementErrors) {
                    return null;
                }

                return this.formatMessage(
                    this.validationMessages.required ?? ':field is required.',
                    { field: fieldLabel }
                );
            }

            if (! this.isRequirementInvalid(item, requirement)) {
                return null;
            }

            const rules = this.parseValidationRules(requirement).filter((rule) => rule !== 'required');
            const value = item?.requirements?.[requirement?.key];
            const stringValue = String(value ?? '');
            const numericValue = Number(value);
            const isNumeric = ! Number.isNaN(numericValue);
            const hasNumericRule = rules.includes('numeric') || requirement?.type === 'number';

            if (rules.includes('numeric') && ! isNumeric) {
                return this.formatMessage(
                    this.validationMessages.numeric ?? ':field must be a number.',
                    { field: fieldLabel }
                );
            }

            for (const rule of rules) {
                if (rule.startsWith('min_digits:')) {
                    const limit = Number(rule.split(':')[1]);
                    const digits = stringValue.replace(/\D/g, '').length;
                    if (! Number.isNaN(limit) && digits < limit) {
                        return this.formatMessage(
                            this.validationMessages.min_digits ?? ':field must be at least :min digits.',
                            { field: fieldLabel, min: limit }
                        );
                    }
                }

                if (rule.startsWith('max_digits:')) {
                    const limit = Number(rule.split(':')[1]);
                    const digits = stringValue.replace(/\D/g, '').length;
                    if (! Number.isNaN(limit) && digits > limit) {
                        return this.formatMessage(
                            this.validationMessages.max_digits ?? ':field must be at most :max digits.',
                            { field: fieldLabel, max: limit }
                        );
                    }
                }

                if (rule.startsWith('min:')) {
                    const limit = Number(rule.split(':')[1]);
                    if (! Number.isNaN(limit)) {
                        const isInvalid = hasNumericRule ? numericValue < limit : stringValue.length < limit;
                        if (isInvalid) {
                            return hasNumericRule
                                ? this.formatMessage(
                                    this.validationMessages.min_value ?? ':field must be at least :min.',
                                    { field: fieldLabel, min: limit }
                                )
                                : this.formatMessage(
                                    this.validationMessages.min_chars ?? ':field must be at least :min characters.',
                                    { field: fieldLabel, min: limit }
                                );
                        }
                    }
                }

                if (rule.startsWith('max:')) {
                    const limit = Number(rule.split(':')[1]);
                    if (! Number.isNaN(limit)) {
                        const isInvalid = hasNumericRule ? numericValue > limit : stringValue.length > limit;
                        if (isInvalid) {
                            return hasNumericRule
                                ? this.formatMessage(
                                    this.validationMessages.max_value ?? ':field must be at most :max.',
                                    { field: fieldLabel, max: limit }
                                )
                                : this.formatMessage(
                                    this.validationMessages.max_chars ?? ':field must be at most :max characters.',
                                    { field: fieldLabel, max: limit }
                                );
                        }
                    }
                }

                if (rule.startsWith('in:')) {
                    const allowed = rule
                        .slice(3)
                        .split(',')
                        .map((value) => value.trim());
                    if (! allowed.includes(stringValue)) {
                        return this.formatMessage(
                            this.validationMessages.in ?? ':field must be one of: :values.',
                            { field: fieldLabel, values: allowed.join(', ') }
                        );
                    }
                }

                if (rule.startsWith('regex:') || rule.startsWith('not_regex:')) {
                    return this.formatMessage(
                        this.validationMessages.invalid_format ?? ':field has an invalid format.',
                        { field: fieldLabel }
                    );
                }
            }

            return this.formatMessage(
                this.validationMessages.invalid_value ?? ':field is invalid.',
                { field: fieldLabel }
            );
        },
        applyRequirementsSchema(requirementsByProduct) {
            if (! requirementsByProduct) {
                return;
            }

            this.items.forEach((item) => {
                const schema = requirementsByProduct[item.id] ?? [];

                if (! Array.isArray(schema) || schema.length === 0) {
                    return;
                }

                item.requirements_schema = schema;
                item.requirements = item.requirements ?? {};

                schema.forEach((requirement) => {
                    if (requirement?.key && item.requirements[requirement.key] === undefined) {
                        item.requirements[requirement.key] = '';
                    }
                });
            });

            this.persist();
        },
        increment(id) {
            const item = this.items.find((entry) => entry.id === id);

            if (! item) {
                return;
            }

            if (item.amount_mode === 'custom') {
                item.quantity = 1;
                return;
            }

            item.quantity += 1;
            this.persist();
        },
        decrement(id) {
            const item = this.items.find((entry) => entry.id === id);

            if (! item) {
                return;
            }

            if (item.amount_mode === 'custom') {
                item.quantity = 1;
                return;
            }

            if (item.quantity <= 1) {
                this.remove(id);
                return;
            }

            item.quantity -= 1;
            this.persist();
        },
        remove(id) {
            this.items = this.items.filter((item) => item.id !== id);
            delete this.serverRequirementErrors[id];
            delete this.customAmountErrors[id];
            this.persist();
        },
        clear() {
            this.items = [];
            this.serverRequirementErrors = {};
            this.customAmountErrors = {};
            this.showCartRequirementErrors = false;
            this.persist();
        },
        getCustomAmountError(item) {
            return this.customAmountErrors[item?.id] ?? null;
        },
        parseAmountDigitsFromInput(value) {
            const n = parseInt(String(value ?? '').replace(/\D/g, ''), 10);

            return Number.isNaN(n) ? null : n;
        },

        customAmountPreviewValid(item, amount) {
            if (amount === null || amount < 1) {
                return false;
            }

            const min = item.custom_amount_min;
            const max = item.custom_amount_max;
            const step = Math.max(1, parseInt(item.custom_amount_step ?? 1, 10));

            if (min !== null && min !== undefined && amount < min) {
                return false;
            }

            if (max !== null && max !== undefined && amount > max) {
                return false;
            }

            if (step > 1 && amount % step !== 0) {
                return false;
            }

            return true;
        },

        lineTotalForItem(item) {
            if (item?.amount_mode !== 'custom') {
                return Number(item.price ?? 0) * Number(item.quantity ?? 0);
            }

            const rate = Number(item.custom_unit_rate);
            if (Number.isNaN(rate) || rate <= 0) {
                return Number(item.price ?? 0);
            }

            const previewAmount = this.parseAmountDigitsFromInput(item.requested_amount_input ?? '');
            if (previewAmount !== null && this.customAmountPreviewValid(item, previewAmount)) {
                return Math.round(previewAmount * rate * 100) / 100;
            }

            return Number(item.price ?? 0);
        },

        /**
         * Resolved $/unit for custom-amount lines (for display). Line totals use 2dp; per-unit can be very small.
         */
        customUnitRateForDisplay(item) {
            if (item?.amount_mode !== 'custom') {
                return null;
            }

            const fromStored = Number(item.custom_unit_rate);
            if (! Number.isNaN(fromStored) && fromStored > 0) {
                return fromStored;
            }

            const line = this.lineTotalForItem(item);
            const preview = this.parseAmountDigitsFromInput(item.requested_amount_input ?? '');
            if (preview !== null && preview > 0 && this.customAmountPreviewValid(item, preview) && line > 0) {
                return line / preview;
            }

            const price = Number(item.price ?? 0);
            const ra = Number(item.requested_amount ?? 0);
            if (price > 0 && ra > 0) {
                return price / ra;
            }

            return null;
        },

        /** Currency string for small per-unit amounts (avoids $0.00 when rate is e.g. $0.002541). */
        formatPerUnitCurrency(value) {
            if (value === null || value === undefined || Number.isNaN(Number(value)) || Number(value) <= 0) {
                return '—';
            }

            return displayCurrency.formatPerUnit(Number(value));
        },

        applyCustomAmountPrice(payload) {
            const productId = payload?.productId ?? payload?.product_id ?? null;
            const price = Number(payload?.price ?? payload?.final_price);
            const requestedRaw = payload?.requestedAmount ?? payload?.requested_amount ?? null;

            if (! productId || Number.isNaN(price)) {
                return;
            }

            const item = this.items.find((entry) => entry.id === productId);

            if (! item) {
                return;
            }

            item.price = price;
            const req = Number(requestedRaw);
            if (! Number.isNaN(req) && req > 0) {
                item.custom_unit_rate = Math.round((price / req) * 1e8) / 1e8;
            }

            delete this.customAmountErrors[productId];
            this.persist();
        },
        setCustomAmountError(payload) {
            const productId = payload?.productId ?? payload?.product_id ?? null;
            const message = payload?.message ?? null;

            if (! productId) {
                return;
            }

            if (! message) {
                delete this.customAmountErrors[productId];
                return;
            }

            this.customAmountErrors[productId] = message;
        },
        updateRequestedAmount(id, value) {
            const item = this.items.find((entry) => entry.id === id);

            if (! item || item.amount_mode !== 'custom') {
                return false;
            }

            item.quantity = 1;

            if (item.requested_amount_input === undefined || item.requested_amount_input === null) {
                item.requested_amount_input = this.formatGroupedIntegerForAmount(item.requested_amount ?? 1);
            }

            const parsedValue = parseInt(String(value ?? '').replace(/\D/g, ''), 10);
            if (Number.isNaN(parsedValue) || parsedValue <= 0) {
                this.customAmountErrors[id] = 'Amount is required.';
                this.persist();
                return false;
            }

            const min = item.custom_amount_min;
            const max = item.custom_amount_max;
            const step = Math.max(1, parseInt(item.custom_amount_step ?? 1, 10));

            if (min !== null && min !== undefined && parsedValue < min) {
                this.customAmountErrors[id] = `Amount must be at least ${min}.`;
                this.persist();
                return false;
            }

            if (max !== null && max !== undefined && parsedValue > max) {
                this.customAmountErrors[id] = `Amount may not be greater than ${max}.`;
                this.persist();
                return false;
            }

            if (step > 1 && parsedValue % step !== 0) {
                this.customAmountErrors[id] = `Amount must be in increments of ${step}.`;
                this.persist();
                return false;
            }

            item.requested_amount = parsedValue;
            item.requested_amount_input = this.formatGroupedIntegerForAmount(parsedValue);
            delete this.customAmountErrors[id];
            this.persist();

            return true;
        },
        attemptCheckout($wire) {
            if (this.count === 0) {
                return;
            }

            if (this.hasCustomAmountErrors) {
                return;
            }

            if (this.hasMissingRequirements) {
                this.showCartRequirementErrors = true;

                return;
            }

            $wire.checkout(this.checkoutItems);
        },
        format(value) {
            return displayCurrency.format(Number(value ?? 0));
        },
        displayCurrencyCode() {
            return displayCurrency.activeCode();
        },
        get count() {
            return this.items.reduce((total, item) => total + (item.amount_mode === 'custom' ? 1 : item.quantity), 0);
        },
        get subtotal() {
            return this.items.reduce((total, item) => total + this.lineTotalForItem(item), 0);
        },
        get loyaltyDiscount() {
            return this.items.reduce((total, item) => total + (Number(item.discount_amount ?? 0) * item.quantity), 0);
        },
        get loyaltyTierName() {
            const withTier = this.items.find((item) => item.tier_name);
            return withTier ? withTier.tier_name : null;
        },
        get loyaltyTierLabel() {
            const name = this.loyaltyTierName;
            if (!name) return '';
            const labels = window.Laravel?.loyaltyTierLabels ?? {};
            return labels[name] ?? (name.charAt(0).toUpperCase() + name.slice(1));
        },
        get hasMissingRequirements() {
            return this.items.some((item) => {
                const schema = Array.isArray(item.requirements_schema) ? item.requirements_schema : [];

                if (schema.length === 0) {
                    return false;
                }

                return schema.some((requirement) => this.isRequirementMissing(item, requirement) || this.isRequirementInvalid(item, requirement));
            });
        },
        get hasCustomAmountErrors() {
            if (Object.keys(this.customAmountErrors).length > 0) {
                return true;
            }

            return this.items.some((item) => {
                if (item.amount_mode !== 'custom') {
                    return false;
                }

                const n = this.parseAmountDigitsFromInput(item.requested_amount_input ?? '');

                if (n === null || n < 1) {
                    return true;
                }

                return ! this.customAmountPreviewValid(item, n);
            });
        },
        get checkoutItems() {
            return this.items.map((item) => {
                let requestedAmount = item.amount_mode === 'custom' ? item.requested_amount : null;

                if (item.amount_mode === 'custom') {
                    const fromInput = this.parseAmountDigitsFromInput(item.requested_amount_input ?? '');
                    if (fromInput !== null && this.customAmountPreviewValid(item, fromInput)) {
                        requestedAmount = fromInput;
                    }
                }

                return {
                    product_id: item.id,
                    package_id: item.package_id ?? null,
                    quantity: item.amount_mode === 'custom' ? 1 : item.quantity,
                    requested_amount: requestedAmount,
                    requirements: item.requirements ?? {},
                };
            });
        },
    });

    /**
     * Salesperson referred users: edit / reset / create open instantly from row data;
     * submit calls Livewire once (Actions) and refreshes list via users-updated.
     */
    Alpine.data('salespersonReferredUsersPage', () => ({
        showFilters: false,
        editOpen: false,
        resetOpen: false,
        createOpen: false,
        busyAction: null,
        editForm: {
            id: null,
            name: '',
            username: '',
            email: '',
            phone: '',
            country_code: '+90',
            password: '',
            password_confirmation: '',
        },
        editErrors: {},
        resetForm: {
            id: null,
            password: '',
            password_confirmation: '',
        },
        resetErrors: {},
        createForm: {
            name: '',
            username: '',
            email: '',
            password: '',
            password_confirmation: '',
            phone: '',
            country_code: '+90',
        },
        createErrors: {},
        allowedCountryCodes: ['+90', '+963'],
        normalizeCountryCode(cc) {
            const s = cc == null ? '' : String(cc);

            return this.allowedCountryCodes.includes(s) ? s : '+90';
        },
        flattenFirstErrors(errors) {
            const out = {};
            if (!errors || typeof errors !== 'object') {
                return out;
            }
            for (const [key, messages] of Object.entries(errors)) {
                if (Array.isArray(messages) && messages.length > 0) {
                    out[key] = messages[0];
                } else if (typeof messages === 'string') {
                    out[key] = messages;
                }
            }

            return out;
        },
        /** Row payload from `data-referred-user` (base64 JSON from server; avoids HTML/dataset entity issues). */
        parseReferredRowDataset(el) {
            const b64 = el?.dataset?.referredUser;
            if (!b64 || typeof atob !== 'function') {
                return null;
            }
            try {
                return JSON.parse(atob(b64));
            } catch {
                return null;
            }
        },
        openEditFromDataset(el) {
            const user = this.parseReferredRowDataset(el);
            if (user) {
                this.openEditFromRow(user);
            }
        },
        openResetFromDataset(el) {
            const user = this.parseReferredRowDataset(el);
            if (user) {
                this.openResetFromRow(user);
            }
        },
        openEditFromRow(user) {
            if (!user || user.id == null) {
                return;
            }
            this.editErrors = {};
            this.editForm = {
                id: user.id,
                name: user.name ?? '',
                username: user.username ?? '',
                email: user.email ?? '',
                phone: user.phone ?? '',
                country_code: this.normalizeCountryCode(user.country_code),
                password: '',
                password_confirmation: '',
            };
            this.editOpen = true;
        },
        closeEdit() {
            this.editOpen = false;
        },
        async submitEdit() {
            if (this.busyAction) {
                return;
            }
            this.busyAction = 'edit';
            this.editErrors = {};
            try {
                const r = await this.$wire.referredSaveEdit(this.editForm);
                if (r?.ok) {
                    this.editOpen = false;
                } else if (r?.errors) {
                    this.editErrors = this.flattenFirstErrors(r.errors);
                }
            } finally {
                this.busyAction = null;
            }
        },
        openResetFromRow(user) {
            if (!user || user.id == null) {
                return;
            }
            this.resetErrors = {};
            this.resetForm = {
                id: user.id,
                password: '',
                password_confirmation: '',
            };
            this.resetOpen = true;
        },
        closeReset() {
            this.resetOpen = false;
        },
        async submitReset() {
            if (this.busyAction) {
                return;
            }
            this.busyAction = 'reset';
            this.resetErrors = {};
            try {
                const r = await this.$wire.referredResetPassword(this.resetForm);
                if (r?.ok) {
                    this.resetOpen = false;
                } else if (r?.errors) {
                    this.resetErrors = this.flattenFirstErrors(r.errors);
                }
            } finally {
                this.busyAction = null;
            }
        },
        openCreateModal() {
            this.createErrors = {};
            this.createForm = {
                name: '',
                username: '',
                email: '',
                password: '',
                password_confirmation: '',
                phone: '',
                country_code: '+90',
            };
            this.createOpen = true;
        },
        closeCreate() {
            this.createOpen = false;
        },
        async submitCreate() {
            if (this.busyAction) {
                return;
            }
            this.busyAction = 'create';
            this.createErrors = {};
            try {
                const r = await this.$wire.referredCreateUser(this.createForm);
                if (r?.ok) {
                    this.createOpen = false;
                } else if (r?.errors) {
                    this.createErrors = this.flattenFirstErrors(r.errors);
                }
            } finally {
                this.busyAction = null;
            }
        },
    }));

    Alpine.store('cart').init();
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
import './bug-reporter';

/**
 * Admin FCM: play push sound in the page (HTMLAudioElement). SW uses BroadcastChannel + postMessage
 * because clients.matchAll often returns 0 while a tab is still open.
 */
const KARMAN_PUSH_SOUND_MSG = 'KARMAN_PLAY_PUSH_SOUND';
const KARMAN_PUSH_SOUND_CHANNEL = 'karman-push-sound';

if (typeof window !== 'undefined' && window.Laravel?.isAdmin === true) {
    let lastKarmanPushSoundAt = 0;
    function playKarmanPushSound(url) {
        if (typeof url !== 'string' || url === '') {
            return;
        }
        const now = Date.now();
        if (now - lastKarmanPushSoundAt < 500) {
            return;
        }
        lastKarmanPushSoundAt = now;
        try {
            const audio = new Audio(url);
            audio.setAttribute('playsinline', '');
            const p = audio.play();
            if (p !== undefined && typeof p.then === 'function') {
                p.catch(() => {});
            }
        } catch {
            /* ignore */
        }
    }

    if (typeof BroadcastChannel !== 'undefined') {
        try {
            const pushSoundBc = new BroadcastChannel(KARMAN_PUSH_SOUND_CHANNEL);
            pushSoundBc.addEventListener('message', (event) => {
                const d = event.data;
                if (!d || d.type !== KARMAN_PUSH_SOUND_MSG) {
                    return;
                }
                playKarmanPushSound(d.url);
            });
        } catch {
            /* ignore */
        }
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            const d = event.data;
            if (!d || d.type !== KARMAN_PUSH_SOUND_MSG || typeof d.url !== 'string') {
                return;
            }
            playKarmanPushSound(d.url);
        });
    }
}

if (window.Laravel?.isAdmin === true) {
    import('./firebase-push');
}
