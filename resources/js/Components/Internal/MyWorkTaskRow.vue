<script setup>
/**
 * A single My Work task row: quick-complete checkbox, task info
 * (title + project/type/priority meta), an inline reschedule date
 * input, and the due time. Stateless — the parent owns the
 * `completing` flag and the mutations; this just emits intent.
 */
import { computed } from 'vue';
import { IconCircle, IconLoader2, IconVideo } from '@tabler/icons-vue';

const props = defineProps({
    task: { type: Object, required: true },
    completing: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['complete', 'reschedule', 'open']);

const isOverdue = computed(() =>
    props.task.due_at_full && new Date(props.task.due_at_full) < new Date(),
);
const isCompleting = computed(() => props.completing === props.task.id);
</script>

<template>
    <div
        class="mw-task-row"
        :class="{ 'mw-task--overdue': isOverdue, 'mw-task--completing': isCompleting }"
    >
        <!-- Quick complete -->
        <button
            type="button"
            class="mw-complete-btn"
            :disabled="isCompleting"
            title="Mark complete"
            @click="emit('complete', task.id)"
        >
            <IconLoader2 v-if="isCompleting" :size="14" stroke-width="2" class="spin" />
            <IconCircle v-else :size="14" stroke-width="2" />
        </button>

        <!-- Task info -->
        <div class="mw-task-info" @click="emit('open', task)">
            <span class="mw-task-title">{{ task.title }}</span>
            <span class="mw-task-meta">
                <span
                    v-if="task.project_title"
                    class="mw-project-chip"
                    :style="{ borderColor: task.project_colour, color: task.project_colour }"
                >{{ task.project_title }}</span>
                <span v-if="task.customer_name" class="mw-type-badge">{{ task.customer_name }}</span>
                <span v-if="task.type === 'meeting'" class="mw-type-badge">
                    <IconVideo :size="12" stroke-width="2" /> Meeting
                </span>
                <span class="mw-priority" :class="'priority-' + task.priority">{{ task.priority }}</span>
            </span>
        </div>

        <!-- Quick reschedule -->
        <input
            type="date"
            class="mw-reschedule-input"
            :value="task.due_at_full ? task.due_at_full.slice(0, 10) : ''"
            title="Reschedule"
            @change="emit('reschedule', task.id, $event.target.value)"
        />

        <!-- Due time -->
        <span class="mw-due-time" :class="{ overdue: isOverdue }">{{ task.due_at }}</span>
    </div>
</template>
