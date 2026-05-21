
# Salesperson Dashboard — Pro Redesign

A focused visual overhaul. Style: **modern dark + neon data viz** (Stripe / Linear feel) — deep slate background, glassy cards, single accent per metric (emerald, violet, cyan, amber), crisp typography, restrained motion. Goal: a salesperson understands their performance, money, and pipeline in **under 5 seconds**.

## 1. Global look & feel

- Background: deep slate `#0A0E1A` with a faint radial glow behind the header.
- Cards: `#111827/70` with 1px hairline border `white/5`, soft inner highlight, 16px radius, subtle backdrop blur.
- Typography: large tabular numerals for money (`font-variant-numeric: tabular-nums`), tight tracking on headings, muted labels in uppercase 11px with letter-spacing.
- Accent palette (one color per concept, used consistently everywhere):
  - Emerald `#10B981` → earnings / paid
  - Violet `#8B5CF6` → orders / volume
  - Cyan `#06B6D4` → referrals / customers
  - Amber `#F59E0B` → pending / payout
  - Rose `#F43F5E` → failed
- Micro-interactions: numbers count up on load, sparklines draw in, row hover reveals a chevron.

## 2. Header

A slim, premium header replaces the current title block.

```text
┌──────────────────────────────────────────────────────────────────────┐
│  ◐ Salesperson  ·  Welcome back, Omar                  [ This month ▾ ] │
│  Your performance at a glance                          [ Share link ↗ ] │
└──────────────────────────────────────────────────────────────────────┘
```

- Left: avatar, greeting, subtitle.
- Right: time-range selector (Today / 7d / This month / YTD / Custom) and a "Copy referral link" button with the link inline.
- Tabs (My orders / Earnings / Users) become a segmented pill control under the header.

## 3. KPI strip + performance chart (the hero)

A single unified panel — KPIs sit on top of one large chart so trends and totals read together.

```text
┌──────────────────────────────────────────────────────────────────────┐
│  EARNINGS (ALL TIME)   PAID THIS MONTH   ORDERS BROUGHT   PENDING     │
│  $314.83  ▲ 12%        $53.18  ▲ 4%      11   ▲ 2         $0.00      │
│  ╱╲╱‾╲ emerald spark   ╱‾╲ emerald       ▮▮▮▯▯ violet     — amber    │
│ ─────────────────────────────────────────────────────────────────────│
│                                                                      │
│   Earnings performance              [ Earnings ● Orders ○ ]  [ 30d ▾]│
│        ╱╲                                                            │
│   ╱╲╱╲╱  ╲    ╱╲      ← smooth area chart, neon emerald glow        │
│  ╱        ╲╱╲╱  ╲╱╲                                                  │
│   Mon  Tue  Wed  Thu  Fri  Sat  Sun                                  │
└──────────────────────────────────────────────────────────────────────┘
```

- Each KPI: tiny uppercase label, big tabular number, delta chip (▲/▼ vs previous period), an 8-point sparkline in its accent color.
- Chart: area chart with gradient fade to transparent, dashed average line, hoverable tooltip showing date + value + commission count. Toggle series (Earnings / Orders).
- The whole panel sits in one card so the eye reads top→bottom: "how much, and is it growing".

## 4. Two-column insights row

Right under the hero, two equal cards.

### 4a. Payout status (left)

A focused, reassuring card so the salesperson always knows when money arrives.

```text
┌────────────────────────────────────────┐
│  PAYOUT STATUS                  amber  │
│                                        │
│      $53.18                            │
│      eligible for payout               │
│                                        │
│  Next payout in   ●●●●●○○○  4 days     │
│  May 6, 2026                           │
│                                        │
│  Pending  $0.00     Threshold  $50 ✓   │
│  [ Request payout ]                    │
└────────────────────────────────────────┘
```

- Large eligible amount, circular/segmented countdown to next payout date.
- Threshold indicator with a green check when met.
- Primary CTA: "Request payout" (disabled with tooltip if not eligible).

### 4b. Top customers leaderboard (right)

```text
┌────────────────────────────────────────┐
│  TOP CUSTOMERS              This month │
│                                        │
│  🥇  El hatib   8 orders     $241.47   │
│      ▰▰▰▰▰▰▰▰▰▰  ──────────────  100%  │
│  🥈  be         2 orders      $67.37   │
│      ▰▰▰  ───────────────────────  28% │
│  🥉  El         2 orders      $53.18   │
│      ▰▰  ────────────────────────  22% │
│                                        │
│  View all customers →                  │
└────────────────────────────────────────┘
```

- Avatar, name, orders, commission, horizontal bar normalized to the top earner.
- Medal icons for top 3, subtle violet→cyan gradient bars.

## 5. Orders table — grouped by date + status pills

A refined, scannable table replacing the dense current one.

```text
┌──────────────────────────────────────────────────────────────────────┐
│  My orders            [ Search ⌕ ]  [ Status ▾ ] [ Date ▾ ] [ ⚙ ]    │
│ ─────────────────────────────────────────────────────────────────────│
│  TODAY · MAY 1                                       2 orders · $53  │
│  ORD-089  21:13  سول 50,000 ×  $132.99   $26.59  ● Credited  ✓ Done  │
│           👤 El  · @qwe                                          ›   │
│  ORD-088  20:30  سول 50,000 ×  $132.99   $26.59  ● Credited  ✓ Done  │
│                                                                      │
│  YESTERDAY · APR 30                                  3 orders · $94  │
│  ORD-087  20:56  100 الف + 50,000 …  $203.92  $40.78  ● Credited     │
│  ...                                                                 │
│                                                                      │
│  APR 29                                              7 orders · $213 │
│  ORD-085  18:11  10,000 + 50,000 …   $363.51  $72.70  ● Failed  ✕    │
└──────────────────────────────────────────────────────────────────────┘
```

- Sticky day headers with day total (orders + commission) — instant sense of daily performance.
- Status pills: filled dot + label, accent color per state (emerald Credited, amber Pending, rose Failed). Fulfillment shown as a small icon, not a second pill, to reduce noise.
- Customer cell shows avatar initials, name, @handle; phone hidden behind hover for cleanliness.
- Commission column right-aligned, tabular, with a faint emerald background tint so money pops.
- Row hover: subtle lift + chevron; click expands to show full product list and copyable order ID.
- Empty / failed rows visually de-emphasized (60% opacity) so wins stand out.
- Sticky toolbar with search, status filter chips, date range, column settings.

## 6. Earnings & commissions card (kept, refined)

Below the orders table, a slimmer version of today's "My earnings & commissions":

- Two-column key/value list with hairline dividers, money right-aligned in tabular numerals.
- The two progress bars become labeled meters: "Total paid this month — 17%" with emerald fill; "Pending balance — 0%" muted.
- Credit history table inherits the same row style as the orders table for consistency.

## 7. Responsive behavior

- ≥1280px: 4-column KPI strip, two-column insights row, full-width orders table.
- 768–1279px: KPIs become 2×2, insights stack, table keeps grouping with horizontal scroll on inner columns.
- <768px: orders render as cards (date group → stacked cards) so nothing truncates.

## 8. Empty & loading states

- Skeleton shimmer for KPIs and chart on load.
- Empty leaderboard: illustration + "Share your referral link to start earning".
- Zero pending: payout card shows a calm "You're all caught up" state in muted emerald.

## Technical notes (for implementation)

- Pure UI pass on `src/pages/Index.tsx` and new components under `src/components/dashboard/` (Header, KpiStrip, PerformanceChart, PayoutCard, Leaderboard, OrdersTable, EarningsPanel).
- Extend `src/index.css` design tokens: add semantic vars `--accent-earnings`, `--accent-orders`, `--accent-customers`, `--accent-pending`, `--accent-failed`, plus surface tokens `--surface-1/2/3` and a `--ring-glow` for focus.
- Charts via `recharts` (already available through `src/components/ui/chart.tsx`) with custom gradient defs.
- Mock data only — no backend changes. Data shapes mirror the screenshot so swapping to real data later is trivial.
- Tabular numerals via a utility class `.num` applied to all money/count cells.
- Motion via CSS transitions only (no new deps); count-up using a tiny inline hook.
