<script setup>
/**
 * Optional "Link to" subject picker for the task create/edit forms.
 *
 * A task may be linked to a lead OR a customer — never both — or to
 * neither (a standalone task). The picker is a Lead/Customer toggle
 * plus a searchable list fed by /tasks/link-options; picking one side
 * nulls the other, and the selected chip is always clearable.
 *
 * Pages pre-select their own context (lead page → that lead, customer
 * page → that customer) by passing the matching id + initial-label;
 * global entry points pass nothing and the field starts empty.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { IconSearch, IconX } from '@tabler/icons-vue';

const props = defineProps({
    leadId: { type: Number, default: null },
    customerId: { type: Number, default: null },
    // Display label for a pre-selected subject (page context or the
    // task being edited). Only read while leadId/customerId is set.
    initialLabel: { type: String, default: '' },
});

const emit = defineEmits(['update:leadId', 'update:customerId', 'change']);

const mode = ref(props.customerId !== null ? 'customer' : 'lead');
const search = ref('');
const options = ref([]);
const loading = ref(false);
const selectedLabel = ref(
    props.leadId !== null || props.customerId !== null ? props.initialLabel : '',
);

const hasSelection = computed(() => props.leadId !== null || props.customerId !== null);

// Parent cleared/reset the form while we're mounted → drop the label.
watch(() => [props.leadId, props.customerId], ([lead, customer]) => {
    if (lead === null && customer === null) selectedLabel.value = '';
});

async function fetchOptions() {
    loading.value = true;
    try {
        const params = new URLSearchParams({ type: mode.value, q: search.value.trim() });
        const res = await fetch(`/tasks/link-options?${params}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        options.value = res.ok ? (await res.json()).options : [];
    } catch {
        options.value = [];
    } finally {
        loading.value = false;
    }
}

let debounce = null;
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(fetchOptions, 250);
});

function setMode(next) {
    if (mode.value === next) return;
    mode.value = next;
    search.value = '';
    options.value = [];
    fetchOptions();
}

onMounted(() => {
    if (! hasSelection.value) fetchOptions();
});

function pick(option) {
    if (mode.value === 'lead') {
        emit('update:leadId', option.id);
        emit('update:customerId', null);
    } else {
        emit('update:customerId', option.id);
        emit('update:leadId', null);
    }
    selectedLabel.value = option.label;
    emit('change');
}

function clear() {
    emit('update:leadId', null);
    emit('update:customerId', null);
    selectedLabel.value = '';
    search.value = '';
    emit('change');
    fetchOptions();
}
</script>

<template>
    <div class="task-link-picker">
        <div v-if="hasSelection" class="tlp-chip">
            <span class="tlp-type">{{ leadId !== null ? 'Lead' : 'Customer' }}</span>
            <span class="tlp-name">{{ selectedLabel || `#${leadId ?? customerId}` }}</span>
            <button type="button" class="tlp-clear" aria-label="Clear link" @click="clear">
                <IconX :size="14" stroke-width="1.75" />
            </button>
        </div>
        <template v-else>
            <div class="tlp-toggle">
                <button type="button" :class="{ active: mode === 'lead' }" @click="setMode('lead')">Lead</button>
                <button type="button" :class="{ active: mode === 'customer' }" @click="setMode('customer')">Customer</button>
            </div>
            <div class="cust-search">
                <IconSearch :size="16" stroke-width="1.75" />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="mode === 'lead' ? 'Search leads…' : 'Search customers…'"
                >
            </div>
            <div v-if="options.length" class="cust-list tlp-list">
                <button v-for="o in options" :key="o.id" type="button" class="cust-row" @click="pick(o)">
                    <div class="meta">
                        <div class="nm">{{ o.label }}</div>
                        <div v-if="o.sub" class="tlp-sub">{{ o.sub }}</div>
                    </div>
                </button>
            </div>
            <div v-else-if="! loading && search.trim()" class="tlp-empty">
                No {{ mode === 'lead' ? 'leads' : 'customers' }} match “{{ search.trim() }}”.
            </div>
        </template>
    </div>
</template>
