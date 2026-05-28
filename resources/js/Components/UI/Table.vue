<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--neutral-bg); color: var(--text-secondary)">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, idx) in rows"
                    :key="idx"
                    class="border-b"
                    style="border-color: var(--border-soft)"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="px-3 py-2"
                        style="color: var(--text-primary)"
                    >
                        <slot :name="`cell-${col.key}`" :row="row">
                            {{ row[col.key] }}
                        </slot>
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        :colspan="columns.length"
                        class="px-3 py-6 text-center text-sm"
                        style="color: var(--text-tertiary)"
                    >
                        No records yet.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
