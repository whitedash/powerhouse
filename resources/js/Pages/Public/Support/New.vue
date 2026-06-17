<script setup>
import { onMounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { IconLifebuoy, IconAlertCircle } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({
    turnstileSiteKey: { type: String, default: null },
});

const form = useForm({
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    subject: '',
    message: '',
    _hp: '', // honeypot — must stay empty
    'cf-turnstile-response': '',
});

// Load the Turnstile widget script once, if a site key is configured.
onMounted(() => {
    if (! props.turnstileSiteKey) return;
    if (document.querySelector('script[data-turnstile]')) return;
    const s = document.createElement('script');
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    s.async = true;
    s.defer = true;
    s.setAttribute('data-turnstile', '1');
    document.head.appendChild(s);
});

// Turnstile writes the token into the named input via its callback;
// read it back at submit time.
function submit() {
    const tokenEl = document.querySelector('[name="cf-turnstile-response"]');
    if (tokenEl) form['cf-turnstile-response'] = tokenEl.value;
    form.post('/support', { preserveScroll: true });
}
</script>

<template>
    <Head title="Contact support · Whitedash" />

    <PublicLayout>
        <section class="sup-wrap">
            <div class="sup-head">
                <div class="sup-ic"><IconLifebuoy :size="24" stroke-width="1.75" /></div>
                <h1>Contact support</h1>
                <p>Tell us what's going on and we'll get back to you by email. No account needed.</p>
            </div>

            <form class="sup-form card" @submit.prevent="submit">
                <div v-if="form.errors.captcha" class="sup-error">
                    <IconAlertCircle :size="18" stroke-width="2" /> <span>{{ form.errors.captcha }}</span>
                </div>

                <div class="sup-row two">
                    <div class="sup-field">
                        <label>Your name<span class="req">*</span></label>
                        <input v-model="form.guest_name" type="text" required maxlength="255" :class="{ 'has-err': form.errors.guest_name }">
                        <div v-if="form.errors.guest_name" class="err">{{ form.errors.guest_name }}</div>
                    </div>
                    <div class="sup-field">
                        <label>Email<span class="req">*</span></label>
                        <input v-model="form.guest_email" type="email" required maxlength="255" :class="{ 'has-err': form.errors.guest_email }">
                        <div v-if="form.errors.guest_email" class="err">{{ form.errors.guest_email }}</div>
                    </div>
                </div>

                <div class="sup-field">
                    <label>Phone <span class="opt">(optional)</span></label>
                    <input v-model="form.guest_phone" type="text" maxlength="50">
                </div>

                <div class="sup-field">
                    <label>Subject<span class="req">*</span></label>
                    <input v-model="form.subject" type="text" required maxlength="255" :class="{ 'has-err': form.errors.subject }">
                    <div v-if="form.errors.subject" class="err">{{ form.errors.subject }}</div>
                </div>

                <div class="sup-field">
                    <label>How can we help?<span class="req">*</span></label>
                    <textarea v-model="form.message" rows="6" required maxlength="5000" :class="{ 'has-err': form.errors.message }" />
                    <div v-if="form.errors.message" class="err">{{ form.errors.message }}</div>
                </div>

                <!-- Honeypot: visually hidden, never shown to humans. -->
                <div class="sup-hp" aria-hidden="true">
                    <label>Leave this field empty</label>
                    <input v-model="form._hp" type="text" tabindex="-1" autocomplete="off">
                </div>

                <!-- Turnstile widget mounts here when a site key is set.
                     theme=light: the form background is fixed light, so pin the
                     widget light rather than letting "auto" follow the visitor's
                     OS dark-mode. size=flexible: fill the form column width. -->
                <div v-if="turnstileSiteKey" class="sup-turnstile">
                    <div class="cf-turnstile" :data-sitekey="turnstileSiteKey" data-theme="light" data-size="flexible" />
                </div>

                <div class="sup-actions">
                    <button type="submit" class="btn btn-primary btn-lg" :disabled="form.processing">
                        {{ form.processing ? 'Sending…' : 'Send message' }}
                    </button>
                </div>
            </form>
        </section>
    </PublicLayout>
</template>

<style scoped>
.sup-wrap { max-width: 640px; margin: 0 auto; padding: 48px 32px; }
.sup-head { text-align: center; margin-bottom: 28px; }
.sup-ic { width: 52px; height: 52px; margin: 0 auto 12px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; background: var(--info-bg); color: var(--info); }
.sup-head h1 { font-size: 30px; font-weight: 700; margin: 0 0 8px; letter-spacing: -.02em; }
.sup-head p { color: var(--text-secondary); margin: 0; }

.sup-form { padding: 28px; display: flex; flex-direction: column; gap: 16px; }
.sup-row.two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.sup-field { display: flex; flex-direction: column; gap: 6px; }
.sup-field label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
.req { color: var(--danger); margin-left: 2px; }
.opt { color: var(--text-tertiary); font-weight: 400; }
.sup-field input, .sup-field textarea {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-md);
    font-size: 14px; font-family: inherit; background: #fff;
}
.sup-field input:focus, .sup-field textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(245,158,11,.18); }
.sup-field .has-err { border-color: var(--danger); }
.err { font-size: 12px; color: var(--danger); }
.sup-error { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: var(--danger-bg); color: #b91c1c; border-radius: var(--radius-md); font-size: 13.5px; }
.sup-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
/* Turnstile wrapper. Already gap-spaced 16px as a .sup-form flex child, so no
   margin needed; this only stretches it to the field width (data-size=flexible
   fills the container) and reserves height to avoid load-time layout shift.
   Namespaced — never restyle .cf-turnstile globally. */
.sup-turnstile { width: 100%; min-height: 65px; }
.sup-actions { display: flex; justify-content: flex-end; }
.btn-lg { height: 46px; padding: 0 24px; font-size: 14px; }

@media (max-width: 560px) { .sup-row.two { grid-template-columns: 1fr; } }
</style>
