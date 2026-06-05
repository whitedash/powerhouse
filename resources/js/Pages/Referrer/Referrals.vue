<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    IconPlus, IconX, IconShieldCheck, IconClock, IconCircleCheck,
    IconBan, IconCopy, IconChevronLeft, IconChevronRight, IconRosetteDiscountCheck,
} from '@tabler/icons-vue';
import ReferrerLayout from '@/Layouts/ReferrerLayout.vue';

const props = defineProps({
    deals: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    self_serve: { type: Object, default: () => ({ slug: '', share_link: null }) },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const showForm = ref(false);
const form = useForm({
    product_id: '',
    company: '',
    contact_name: '',
    email: '',
    phone: '',
    notes: '',
    consent: false,
});

function openForm() {
    form.clearErrors();
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
}
function submit() {
    form.post('/referrer/referrals', {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    });
}

// Show the self-serve share link when the picked product is the self-serve one.
const pickedSelfServe = computed(() =>
    props.products.find((p) => String(p.id) === String(form.product_id))?.is_self_serve === true,
);

const copied = ref(false);
function copyShareLink() {
    if (! props.self_serve?.share_link) return;
    navigator.clipboard?.writeText(props.self_serve.share_link).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1800);
    });
}

const STATUS_META = {
    pending_review: { label: 'Pending review', icon: IconClock, cls: 'is-pending' },
    approved: { label: 'Approved', icon: IconCircleCheck, cls: 'is-approved' },
    rejected: { label: 'Rejected', icon: IconBan, cls: 'is-rejected' },
    expired: { label: 'Expired', icon: IconClock, cls: 'is-expired' },
};
function meta(status) {
    return STATUS_META[status] ?? { label: status, icon: IconClock, cls: 'is-pending' };
}
</script>

<template>
    <Head title="Referrals · Whitedash Partners" />
    <ReferrerLayout title="Referrals" active-nav="referrals">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Registered deals</h2>
                <div class="desc">Register a prospect to protect it for 90 days once approved.</div>
            </div>
            <button class="btn btn-primary" @click="openForm">
                <IconPlus :size="16" stroke-width="2" /> Register a deal
            </button>
        </div>

        <div v-if="flashSuccess" class="portal-login-flash success" style="margin-bottom:16px;">
            {{ flashSuccess }}
        </div>

        <div v-if="deals.data.length === 0" class="portal-empty">
            <IconRosetteDiscountCheck :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No registered deals yet</div>
            <div class="portal-empty-sub">
                Register a prospect you're introducing — once our team approves it, it's protected for 90 days.
            </div>
            <button class="btn btn-primary btn-sm" style="margin-top:10px;" @click="openForm">
                <IconPlus :size="14" stroke-width="2" /> Register a deal
            </button>
        </div>

        <div v-else class="deal-list">
            <article v-for="d in deals.data" :key="d.id" class="deal-card">
                <div class="deal-card-main">
                    <div class="deal-card-title">{{ d.company || d.contact_name || 'Untitled deal' }}</div>
                    <div class="deal-card-meta">
                        <template v-if="d.company && d.contact_name">{{ d.contact_name }} · </template>
                        <template v-if="d.product">{{ d.product }} · </template>
                        Registered {{ d.registered_at }}
                    </div>
                    <div v-if="d.protected_until" class="deal-protected">
                        <IconShieldCheck :size="14" stroke-width="2" />
                        Protected until {{ d.protected_until }}
                    </div>
                    <div v-else-if="d.review_notes" class="deal-rejected-note">
                        {{ d.review_notes }}
                    </div>
                </div>
                <span class="deal-badge" :class="meta(d.referral_status).cls">
                    <component :is="meta(d.referral_status).icon" :size="13" stroke-width="2" />
                    {{ d.referral_status_label || meta(d.referral_status).label }}
                </span>
            </article>
        </div>

        <!-- Pagination -->
        <div v-if="deals.last_page > 1" class="portal-pagination">
            <Link
                v-for="(link, i) in deals.links"
                :key="i"
                :href="link.url || ''"
                class="portal-page-link"
                :class="{ active: link.active, disabled: !link.url }"
            >
                <IconChevronLeft v-if="i === 0" :size="14" stroke-width="2" />
                <IconChevronRight v-else-if="i === deals.links.length - 1" :size="14" stroke-width="2" />
                <span v-else v-html="link.label" />
            </Link>
        </div>

        <!-- Register a deal — slide-over modal -->
        <Teleport to="body">
            <div v-if="showForm" class="portal-modal-backdrop" @click="closeForm" />
            <div v-if="showForm" class="portal-modal" role="dialog" aria-modal="true">
                <form @submit.prevent="submit">
                    <header class="portal-modal-header">
                        <div>
                            <div class="portal-modal-eyebrow">Deal registration</div>
                            <h2>Register a deal</h2>
                        </div>
                        <button type="button" class="icon-btn" aria-label="Close" @click="closeForm">
                            <IconX :size="18" stroke-width="1.75" />
                        </button>
                    </header>

                    <div class="portal-modal-body">
                        <div class="form-field">
                            <label for="product">Product</label>
                            <select id="product" v-model="form.product_id">
                                <option value="">Select a product…</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>

                        <div v-if="pickedSelfServe && self_serve.share_link" class="deal-sharelink">
                            <div class="deal-sharelink-label">This product is self-serve — you can also just share your link:</div>
                            <div class="deal-sharelink-row">
                                <code>{{ self_serve.share_link }}</code>
                                <button type="button" class="btn btn-secondary btn-sm" @click="copyShareLink">
                                    <IconCopy :size="14" stroke-width="2" /> {{ copied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="company">Company</label>
                            <input id="company" v-model="form.company" type="text" maxlength="255" placeholder="Prospect's business name">
                        </div>

                        <div class="form-field">
                            <label for="contact_name">Contact name <span class="req">*</span></label>
                            <input id="contact_name" v-model="form.contact_name" type="text" maxlength="255" required>
                            <div v-if="form.errors.contact_name" class="err">{{ form.errors.contact_name }}</div>
                        </div>

                        <div class="form-field">
                            <label for="email">Email <span class="req">*</span></label>
                            <input id="email" v-model="form.email" type="email" maxlength="255" required>
                            <div v-if="form.errors.email" class="err">{{ form.errors.email }}</div>
                        </div>

                        <div class="form-field">
                            <label for="phone">Phone</label>
                            <input id="phone" v-model="form.phone" type="text" maxlength="50">
                        </div>

                        <div class="form-field">
                            <label for="notes">Notes</label>
                            <textarea id="notes" v-model="form.notes" rows="3" maxlength="5000" placeholder="Anything our team should know."></textarea>
                        </div>

                        <label class="deal-consent">
                            <input v-model="form.consent" type="checkbox">
                            <span>I confirm I have this prospect's consent to share their details with Whitedash for the purpose of this introduction.</span>
                        </label>
                        <div v-if="form.errors.consent" class="err">{{ form.errors.consent }}</div>
                    </div>

                    <footer class="portal-modal-footer">
                        <button type="button" class="btn btn-secondary" @click="closeForm">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            {{ form.processing ? 'Submitting…' : 'Submit for review' }}
                        </button>
                    </footer>
                </form>
            </div>
        </Teleport>
    </ReferrerLayout>
</template>

<style scoped>
.deal-list { display: flex; flex-direction: column; gap: 12px; }
.deal-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 16px 18px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}
.deal-card-main { min-width: 0; }
.deal-card-title { font: 600 15px/1.3 'Inter', sans-serif; color: var(--text-primary); }
.deal-card-meta { font: 400 12.5px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px; }
.deal-protected {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 8px;
    font: 600 12px/1 'Inter', sans-serif;
    color: var(--success);
}
.deal-rejected-note {
    margin-top: 8px;
    font: 400 12px/1.5 'Inter', sans-serif;
    color: var(--text-tertiary);
    font-style: italic;
}
.deal-badge {
    flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font: 600 11.5px/1 'Inter', sans-serif;
    white-space: nowrap;
}
.deal-badge.is-pending { background: rgba(245, 158, 11, .14); color: #B45309; }
.deal-badge.is-approved { background: rgba(16, 185, 129, .14); color: #047857; }
.deal-badge.is-rejected { background: rgba(239, 68, 68, .12); color: #B91C1C; }
.deal-badge.is-expired { background: var(--neutral-bg); color: var(--text-secondary); }

.deal-sharelink {
    background: var(--neutral-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 10px 12px;
    margin-bottom: 4px;
}
.deal-sharelink-label { font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-bottom: 6px; }
.deal-sharelink-row { display: flex; align-items: center; gap: 8px; }
.deal-sharelink-row code {
    flex: 1; min-width: 0;
    font: 500 12px/1.3 'JetBrains Mono', monospace;
    color: var(--text-primary);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.deal-consent {
    display: flex; align-items: flex-start; gap: 8px;
    font: 400 12.5px/1.5 'Inter', sans-serif;
    color: var(--text-secondary);
    cursor: pointer;
}
.deal-consent input { margin-top: 2px; flex-shrink: 0; }

@media (max-width: 720px) {
    .deal-card { flex-direction: column; }
    .deal-badge { align-self: flex-start; }
}
</style>
