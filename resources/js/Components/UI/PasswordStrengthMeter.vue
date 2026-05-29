<script setup>
import { computed } from 'vue';

const props = defineProps({
    password: { type: String, default: '' },
});

/*
 * Client-side strength heuristic — mirrors the server-side
 * Password::min(10)->mixedCase()->numbers()->symbols() validator,
 * plus a bonus tier for very long passwords. This is presentational
 * only; the server is the source of truth.
 *
 * Score from 0–4:
 *   0  empty
 *   1  too short / missing categories
 *   2  meets the floor (10 + mixed + numbers + symbols)
 *   3  16+ chars + every category
 *   4  20+ chars + every category
 */
const score = computed(() => {
    const p = props.password ?? '';
    if (! p) return 0;

    const hasLower = /[a-z]/.test(p);
    const hasUpper = /[A-Z]/.test(p);
    const hasNumber = /\d/.test(p);
    const hasSymbol = /[^A-Za-z0-9]/.test(p);
    const categories = [hasLower, hasUpper, hasNumber, hasSymbol].filter(Boolean).length;

    if (p.length < 10 || categories < 4) return 1;
    if (p.length >= 20) return 4;
    if (p.length >= 16) return 3;
    return 2;
});

const label = computed(() => ['', 'Weak', 'Fair', 'Strong', 'Very strong'][score.value]);

const meterClass = computed(() => ['', 'weak', 'fair', 'strong', 'very-strong'][score.value]);

const requirements = computed(() => {
    const p = props.password ?? '';
    return [
        { ok: p.length >= 10, label: 'At least 10 characters' },
        { ok: /[A-Z]/.test(p) && /[a-z]/.test(p), label: 'Upper and lower case letters' },
        { ok: /\d/.test(p), label: 'At least one number' },
        { ok: /[^A-Za-z0-9]/.test(p), label: 'At least one symbol' },
    ];
});
</script>

<template>
    <div v-if="password" class="pw-strength">
        <div class="pw-strength-bar">
            <div class="pw-strength-fill" :class="meterClass" />
        </div>
        <div class="pw-strength-label">
            <span class="pw-strength-state" :class="meterClass">{{ label }}</span>
        </div>
        <ul class="pw-strength-reqs">
            <li v-for="(r, i) in requirements" :key="i" :class="{ ok: r.ok }">
                <span class="pw-strength-tick">{{ r.ok ? '✓' : '·' }}</span>
                <span>{{ r.label }}</span>
            </li>
        </ul>
    </div>
</template>
