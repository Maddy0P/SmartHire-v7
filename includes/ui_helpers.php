<?php
// ─────────────────────────────────────────────────────────────────────────────
//  ui_helpers.php — shared v8 presentation helpers (Design Bible v1.2).
//  Pure functions, no side effects, no DB. Single source: do not redefine
//  these in page files (charter: no duplicate components).
// ─────────────────────────────────────────────────────────────────────────────

/** Candidate status → Bible badge. */
function sh_status_badge(string $status): string {
    $map = ['hired' => 'success', 'pending' => 'warning', 'rejected' => 'danger',
            'scheduled' => 'info', 'interviewed' => 'neutral'];
    $tone = $map[$status] ?? 'neutral';
    return '<span class="sh-badge sh-badge-' . $tone . '">' . htmlspecialchars($status) . '</span>';
}

/** Job status → Bible badge. */
function sh_job_status_badge(string $status): string {
    $map = ['open' => 'success', 'paused' => 'warning', 'closed' => 'neutral', 'draft' => 'info'];
    $tone = $map[$status] ?? 'neutral';
    return '<span class="sh-badge sh-badge-' . $tone . '">' . htmlspecialchars($status) . '</span>';
}

/** Trend chip: current vs previous period. */
function sh_delta_chip(int $cur, int $prev): string {
    if ($prev === 0 && $cur === 0) return '<span class="sh-kpi-delta flat">— no change</span>';
    if ($prev === 0) return '<span class="sh-kpi-delta up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i>new</span>';
    $pct = round((($cur - $prev) / $prev) * 100);
    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    $icon = $pct > 0 ? 'fa-arrow-up' : ($pct < 0 ? 'fa-arrow-down' : 'fa-minus');
    return '<span class="sh-kpi-delta ' . $dir . '"><i class="fa-solid ' . $icon . '" aria-hidden="true"></i>' . abs($pct) . '%</span>';
}

/** Inline SVG sparkline from an integer series. */
function sh_sparkline(array $pts): string {
    if (count($pts) < 2) return '';
    $max = max(1, max($pts));
    $w = 96; $h = 28; $step = $w / (count($pts) - 1);
    $coords = [];
    foreach ($pts as $i => $v) $coords[] = round($i * $step, 1) . ',' . round($h - 2 - ($v / $max) * ($h - 4), 1);
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true" focusable="false">'
         . '<polyline fill="none" stroke="var(--sh-accent)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $coords) . '"/></svg>';
}

/** Windowed pagination (Prev · 1 … n-1 [n] n+1 … last · Next), state-preserving. */
function sh_pagination(int $page, int $pages, callable $url): string {
    if ($pages <= 1) return '';
    $out = '<nav class="sh-flex sh-items-center sh-gap-2 sh-mt-4" aria-label="Pagination">';
    $btn = function (int $p, string $label, bool $current = false, bool $disabled = false) use ($url) {
        if ($disabled) return '<span class="sh-btn sh-btn-ghost sh-btn-sm" aria-disabled="true">' . $label . '</span>';
        $cur = $current ? ' aria-current="page"' : '';
        $cls = $current ? 'sh-btn sh-btn-secondary sh-btn-sm active' : 'sh-btn sh-btn-ghost sh-btn-sm';
        return '<a class="' . $cls . '"' . $cur . ' href="' . htmlspecialchars($url($p)) . '">' . $label . '</a>';
    };
    $out .= $btn(max(1, $page - 1), '‹ Prev', false, $page <= 1);
    $win = [];
    foreach ([1, $pages, $page - 1, $page, $page + 1] as $p) if ($p >= 1 && $p <= $pages) $win[$p] = true;
    ksort($win);
    $prev = 0;
    foreach (array_keys($win) as $p) {
        if ($prev && $p > $prev + 1) $out .= '<span class="sh-text-muted" aria-hidden="true">…</span>';
        $out .= $btn($p, (string)$p, $p === $page);
        $prev = $p;
    }
    $out .= $btn(min($pages, $page + 1), 'Next ›', false, $page >= $pages);
    return $out . '</nav>';
}
