<script setup>
/**
 * Lead detail — two-column layout with the activity/notes thread
 * on the left and pipeline + assignment + conversion cards on the
 * right.
 *
 * Conversion: opens a modal with required customer fields,
 * POSTs to /leads/{id}/convert, redirects to the new customer.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconX, IconEdit, IconUserPlus, IconMail, IconPhone, IconBuilding,
    IconBriefcase, IconCircleCheck, IconAlertTriangle, IconPlus,
    IconExternalLink, IconArrowRight,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    lead: { type: Object, required: true },
    staff: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const STATUS_LABEL = {
    new: 'New', contacted: 'Contacted', qualified: 'Qualified',
    proposal: 'Proposal', negotiation: 'Negotiation',
    won: 'Won', lost: 'Lost', unresponsive: 'Unresponsive',
};
const SOURCE_LABEL = {
    manual: 'Manual', landing_page: 'Landing page',
    facebook: 'Facebook', google: 'Google',
    referral: 'Referral', email: 'Email', phone: 'Phone',
    event: 'Event', word_of_mouth: 'Word of mouth', other: 'Other',
};

const STATUS_SEQUENCE = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
const nextStatus = computed(() => {
    const i = STATUS_SEQUENCE.indexOf(props.lead.status);
    if (i < 0 || i === STATUS_SEQUENCE.length - 1) return null;
    return STATUS_SEQUENCE[i + 1];
});

const canConvert = computed(() =>
    ! props.lead.is_converted
    && ['qualified', 'proposal', 'negotiation', 'won'].includes(props.lead.status),
);

function money(n) { return n ? `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : '—'; }
function initials(name) { return (name || '').split(/\s+/).map(s => s[0]).slice(0, 2).join('').toUpperCase(); }

/* ─── Status popover ─── */
const showStatusPopover = ref(false);
function moveStatus(status) {
    showStatusPopover.value = false;
    if (status === 'lost') {
        lostReason.value = '';
        showLostModal.value = true;
        return;
    }
    fetch(`/leads/${props.lead.id}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status }),
    }).then(() => router.reload());
}
function advance() {
    if (nextStatus.value) moveStatus(nextStatus.value);
}

/* ─── Lost reason modal ─── */
const showLostModal = ref(false);
const lostReason = ref('');
function confirmLost() {
    if (! lostReason.value.trim()) return;
    fetch(`/leads/${props.lead.id}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: 'lost', lost_reason: lostReason.value.trim() }),
    }).then(() => {
        showLostModal.value = false;
        router.reload();
    });
}

/* ─── Assignment change ─── */
const editForm = useForm({
    first_name: props.lead.first_name,
    last_name: props.lead.last_name ?? '',
    email: props.lead.email ?? '',
    phone: props.lead.phone ?? '',
    company: props.lead.company ?? '',
    job_title: props.lead.job_title ?? '',
    status: props.lead.status,
    source: props.lead.source,
    source_detail: props.lead.source_detail ?? '',
    assigned_to: props.lead.assigned_to?.id ?? null,
    estimated_value: props.lead.estimated_value ?? '',
    notes: props.lead.notes ?? '',
});
const showEdit = ref(false);
function openEdit() { showEdit.value = true; }
function submitEdit() {
    editForm.put(`/leads/${props.lead.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

function changeAssignee(userId) {
    editForm.assigned_to = userId;
    editForm.put(`/leads/${props.lead.id}`, { preserveScroll: true });
}

/* ─── New activity (Task with lead_id) ─── */
const showActivity = ref(false);
const activityForm = useForm({
    type: 'task',
    title: '',
    description: '',
    priority: 'medium',
    lead_id: props.lead.id,
    customer_id: null,
    assigned_to: props.lead.assigned_to?.id ?? null,
    due_at: '',
});
function openActivity() {
    activityForm.reset();
    activityForm.lead_id = props.lead.id;
    activityForm.assigned_to = props.lead.assigned_to?.id ?? null;
    showActivity.value = true;
}
function submitActivity() {
    activityForm.post('/tasks', {
        preserveScroll: true,
        onSuccess: () => { showActivity.value = false; },
    });
}

/* ─── Conversion ─── */
const showConvert = ref(false);
const convertForm = useForm({
    name: props.lead.company ?? props.lead.name,
    type: 'restaurant',
    address_line1: '',
    address_line2: '',
    city: '',
    postcode: '',
    country: 'GB',
    trading_name: '',
    company_number: '',
    vat_number: '',
    assigned_to: props.lead.assigned_to?.id ?? null,
});
function openConvert() {
    convertForm.name = props.lead.company ?? props.lead.name;
    convertForm.assigned_to = props.lead.assigned_to?.id ?? null;
    showConvert.value = true;
}
function submitConvert() {
    convertForm.post(`/leads/${props.lead.id}/convert`, {
        preserveScroll: false,
    });
}
</script>

<template>
    <Head :title="lead.name" />

    <InternalLayout
        :title="lead.name"
        active-nav="leads"
        :breadcrumbs="[
            { label: 'Powerhouse', href: '/' },
            { label: 'Leads', href: '/leads' },
            { label: lead.name },
        ]"
    >
        <div class="lead-show">
            <!-- Header -->
            <div class="lead-header">
                <span class="av lg" :style="{ background: lead.status_colour }">{{ lead.initials }}</span>
                <div class="lead-header-meta">
                    <h1>{{ lead.name }}</h1>
                    <div v-if="lead.company || lead.job_title" class="muted">
                        {{ lead.company }}{{ lead.company && lead.job_title ? ' · ' : '' }}{{ lead.job_title }}
                    </div>
                </div>
                <div class="lead-header-status">
                    <button type="button" class="badge lg" :style="{ background: lead.status_colour + '20', color: lead.status_colour, borderColor: lead.status_colour + '40' }" @click="showStatusPopover = !showStatusPopover">
                        {{ STATUS_LABEL[lead.status] }}
                    </button>
                    <div v-if="showStatusPopover" class="status-popover">
                        <button v-for="s in statuses" :key="s" type="button" class="status-opt" @click="moveStatus(s)">
                            {{ STATUS_LABEL[s] }}
                        </button>
                    </div>
                </div>
                <div class="lead-header-actions">
                    <button type="button" class="btn btn-ghost" @click="openEdit">
                        <IconEdit :size="14" stroke-width="2" /> Edit
                    </button>
                    <button v-if="canConvert" type="button" class="btn btn-primary" @click="openConvert">
                        <IconUserPlus :size="14" stroke-width="2" /> Convert to customer
                    </button>
                    <Link v-else-if="lead.is_converted && lead.customer" :href="`/customers/${lead.customer.id}`" class="btn btn-ghost">
                        <IconExternalLink :size="14" stroke-width="2" /> View customer
                    </Link>
                </div>
            </div>

            <div class="lead-grid">
                <!-- LEFT — Contact + Activities + Notes -->
                <div class="lead-left">
                    <!-- Contact info card -->
                    <div class="card">
                        <div class="card-head"><h3>Contact</h3></div>
                        <div class="card-body contact-grid">
                            <div v-if="lead.email" class="contact-row">
                                <IconMail :size="14" stroke-width="2" />
                                <a :href="`mailto:${lead.email}`" class="contact-link">{{ lead.email }}</a>
                            </div>
                            <div v-if="lead.phone" class="contact-row">
                                <IconPhone :size="14" stroke-width="2" />
                                <a :href="`tel:${lead.phone}`" class="contact-link">{{ lead.phone }}</a>
                            </div>
                            <div v-if="lead.company" class="contact-row">
                                <IconBuilding :size="14" stroke-width="2" />
                                <span>{{ lead.company }}</span>
                            </div>
                            <div v-if="lead.job_title" class="contact-row">
                                <IconBriefcase :size="14" stroke-width="2" />
                                <span>{{ lead.job_title }}</span>
                            </div>
                            <div class="contact-row">
                                <span class="muted small">Source:</span>
                                <span class="lead-source-chip">{{ SOURCE_LABEL[lead.source] }}</span>
                                <span v-if="lead.source_detail" class="muted small"> · {{ lead.source_detail }}</span>
                            </div>
                            <div v-if="lead.estimated_value" class="contact-row">
                                <span class="muted small">Estimated value:</span>
                                <strong>{{ money(lead.estimated_value) }}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Activities -->
                    <div class="card">
                        <div class="card-head">
                            <h3>Activities</h3>
                            <button type="button" class="ghost-link" @click="openActivity">
                                <IconPlus :size="13" stroke-width="2" /> New activity
                            </button>
                        </div>
                        <div class="card-body">
                            <div v-if="lead.tasks.length === 0" class="muted center">
                                No activities yet. <button class="ghost-link inline" @click="openActivity">Log one</button>.
                            </div>
                            <div v-for="task in lead.tasks" :key="task.id" class="activity-row">
                                <span class="status-dot" :class="task.status"></span>
                                <Link :href="`/activities/${task.id}`" class="activity-title">{{ task.title }}</Link>
                                <span v-if="task.due_at" :class="['muted', 'small', { 'text-danger': task.is_overdue }]">
                                    {{ new Date(task.due_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short' }) }}
                                </span>
                                <span v-if="task.assigned_to" class="av xs" :style="{ background: task.assigned_to.avatar_colour ?? 'var(--text-tertiary)' }" :title="task.assigned_to.name">
                                    {{ initials(task.assigned_to.name) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Notes free-form (from lead.notes) -->
                    <div v-if="lead.notes" class="card">
                        <div class="card-head"><h3>Notes</h3></div>
                        <div class="card-body">
                            <p class="muted" style="white-space: pre-line;">{{ lead.notes }}</p>
                        </div>
                    </div>
                </div>

                <!-- RIGHT — Pipeline + Assignment + Source -->
                <div class="lead-right">
                    <!-- Pipeline card -->
                    <div class="card">
                        <div class="card-head"><h3>Pipeline</h3></div>
                        <div class="card-body">
                            <div class="pipeline-summary">
                                <span class="muted small">In <strong>{{ STATUS_LABEL[lead.status] }}</strong> · {{ lead.days_in_pipeline }} days in pipeline</span>
                            </div>
                            <div class="actions-stack" style="margin-top: 10px;">
                                <button v-if="nextStatus && !lead.is_converted" type="button" class="btn btn-primary" @click="advance">
                                    <IconArrowRight :size="14" stroke-width="2" /> Move to {{ STATUS_LABEL[nextStatus] }}
                                </button>
                                <button v-if="!lead.is_converted && lead.status !== 'lost'" type="button" class="btn btn-ghost danger" @click="showLostModal = true; lostReason = ''">
                                    Mark as lost
                                </button>
                            </div>
                            <div v-if="lead.status === 'lost' && lead.lost_reason" class="muted small" style="margin-top: 12px;">
                                <strong>Lost reason:</strong> {{ lead.lost_reason }}
                            </div>
                        </div>
                    </div>

                    <!-- Assignment -->
                    <div class="card">
                        <div class="card-head"><h3>Assignment</h3></div>
                        <div class="card-body">
                            <select class="form-input" :value="lead.assigned_to?.id ?? ''" @change="changeAssignee($event.target.value ? Number($event.target.value) : null)">
                                <option value="">Unassigned</option>
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Conversion CTA card -->
                    <div v-if="canConvert" class="card convert-card">
                        <div class="convert-card-body">
                            <h3>Ready to convert?</h3>
                            <p class="muted small">This will create a customer record + primary contact, transfer activities, and mark this lead as Won.</p>
                            <button type="button" class="btn btn-primary" @click="openConvert">
                                <IconUserPlus :size="14" stroke-width="2" /> Convert to customer
                            </button>
                        </div>
                    </div>

                    <!-- Source & analytics -->
                    <div class="card">
                        <div class="card-head"><h3>Source &amp; history</h3></div>
                        <div class="card-body small-meta">
                            <div><span class="muted small">Source:</span> {{ SOURCE_LABEL[lead.source] }}</div>
                            <div v-if="lead.source_detail"><span class="muted small">Detail:</span> {{ lead.source_detail }}</div>
                            <div><span class="muted small">Created:</span> {{ lead.created_at }}</div>
                            <div v-if="lead.created_by"><span class="muted small">By:</span> {{ lead.created_by.name }}</div>
                            <div v-if="lead.converted_at"><span class="muted small">Converted:</span> {{ lead.converted_at }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit slide-over -->
        <Teleport to="body">
            <div v-if="showEdit" class="slide-over-overlay" @click.self="showEdit = false">
                <div class="slide-over" style="width: 480px;">
                    <div class="slide-over-head">
                        <h2>Edit lead</h2>
                        <button type="button" class="icon-btn" @click="showEdit = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitEdit" class="slide-over-body">
                        <div class="form-row-2">
                            <div class="form-section"><label class="form-label">First name</label><input v-model="editForm.first_name" type="text" class="form-input" required maxlength="100" /></div>
                            <div class="form-section"><label class="form-label">Last name</label><input v-model="editForm.last_name" type="text" class="form-input" maxlength="100" /></div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section"><label class="form-label">Email</label><input v-model="editForm.email" type="email" class="form-input" /></div>
                            <div class="form-section"><label class="form-label">Phone</label><input v-model="editForm.phone" type="text" class="form-input" /></div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section"><label class="form-label">Company</label><input v-model="editForm.company" type="text" class="form-input" /></div>
                            <div class="form-section"><label class="form-label">Job title</label><input v-model="editForm.job_title" type="text" class="form-input" /></div>
                        </div>
                        <div class="form-section"><label class="form-label">Estimated value (£)</label><input v-model="editForm.estimated_value" type="number" min="0" step="0.01" class="form-input" /></div>
                        <div class="form-section"><label class="form-label">Notes</label><textarea v-model="editForm.notes" class="form-input" rows="3" maxlength="5000" /></div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showEdit = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="editForm.processing" @click="submitEdit">Save</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- New activity slide-over -->
        <Teleport to="body">
            <div v-if="showActivity" class="slide-over-overlay" @click.self="showActivity = false">
                <div class="slide-over" style="width: 460px;">
                    <div class="slide-over-head">
                        <h2>New activity</h2>
                        <button type="button" class="icon-btn" @click="showActivity = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitActivity" class="slide-over-body">
                        <div class="form-row-2">
                            <div class="form-section"><label class="form-label">Type</label>
                                <select v-model="activityForm.type" class="form-input">
                                    <option value="task">Task</option>
                                    <option value="call">Call</option>
                                    <option value="email">Email</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="note">Note</option>
                                </select>
                            </div>
                            <div class="form-section"><label class="form-label">Priority</label>
                                <select v-model="activityForm.priority" class="form-input">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-section"><label class="form-label">Title</label><input v-model="activityForm.title" type="text" class="form-input" maxlength="500" required /></div>
                        <div class="form-section"><label class="form-label">Due</label><input v-model="activityForm.due_at" type="datetime-local" class="form-input" /></div>
                        <div class="form-section"><label class="form-label">Assigned to</label>
                            <select v-model="activityForm.assigned_to" class="form-input">
                                <option :value="null">Unassigned</option>
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div class="form-section"><label class="form-label">Description</label><textarea v-model="activityForm.description" class="form-input" rows="3" maxlength="5000" /></div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showActivity = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="activityForm.processing" @click="submitActivity">Log activity</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Lost modal -->
        <Teleport to="body">
            <div v-if="showLostModal" class="slide-over-overlay" @click.self="showLostModal = false">
                <div class="slide-over" style="width: 440px;">
                    <div class="slide-over-head">
                        <h2>Mark as lost</h2>
                        <button type="button" class="icon-btn" @click="showLostModal = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="confirmLost" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Why was this lead lost?</label>
                            <textarea v-model="lostReason" class="form-input" rows="3" maxlength="1000" autofocus />
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showLostModal = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="!lostReason.trim()" @click="confirmLost">Mark as lost</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Convert modal -->
        <Teleport to="body">
            <div v-if="showConvert" class="slide-over-overlay" @click.self="showConvert = false">
                <div class="slide-over" style="width: 520px;">
                    <div class="slide-over-head">
                        <h2>Convert {{ lead.name }} to customer</h2>
                        <button type="button" class="icon-btn" @click="showConvert = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitConvert" class="slide-over-body">
                        <p class="muted small">This will:</p>
                        <ul class="convert-checklist">
                            <li><IconCircleCheck :size="14" stroke-width="2" /> Create a new customer record</li>
                            <li v-if="lead.email || lead.phone"><IconCircleCheck :size="14" stroke-width="2" /> Create a contact from this lead's details</li>
                            <li><IconCircleCheck :size="14" stroke-width="2" /> Transfer activities and notes</li>
                            <li><IconCircleCheck :size="14" stroke-width="2" /> Mark this lead as Won</li>
                        </ul>

                        <div class="form-section">
                            <label class="form-label">Customer name <span class="req">*</span></label>
                            <input v-model="convertForm.name" type="text" class="form-input lg" required maxlength="255" />
                            <p v-if="convertForm.errors.name" class="form-error">{{ convertForm.errors.name }}</p>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Type</label>
                            <select v-model="convertForm.type" class="form-input">
                                <option value="restaurant">Restaurant</option>
                                <option value="bar">Bar</option>
                                <option value="bakery">Bakery</option>
                                <option value="cafe">Cafe</option>
                                <option value="venue">Venue</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Address line 1 <span class="req">*</span></label>
                            <input v-model="convertForm.address_line1" type="text" class="form-input" required maxlength="255" />
                            <p v-if="convertForm.errors.address_line1" class="form-error">{{ convertForm.errors.address_line1 }}</p>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section"><label class="form-label">City</label><input v-model="convertForm.city" type="text" class="form-input" required maxlength="120" /></div>
                            <div class="form-section"><label class="form-label">Postcode</label><input v-model="convertForm.postcode" type="text" class="form-input" required maxlength="20" /></div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showConvert = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="convertForm.processing" @click="submitConvert">
                            {{ convertForm.processing ? 'Converting…' : 'Convert' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </InternalLayout>
</template>
