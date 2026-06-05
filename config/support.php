<?php

return [
    /*
     * The user a guest-submitted ticket's triage Task is attributed to
     * (created_by). TicketIntakeService resolves this id only if such a
     * user exists, otherwise falls back to the first super_admin — so the
     * in-transaction Task insert can never FK-fail and roll back (lose) a
     * guest's ticket. Null/absent means "use the first super_admin".
     */
    'triage_user_id' => env('SUPPORT_TRIAGE_USER_ID'),

    /*
     * Where the "new support ticket" staff alert is emailed. Falls back to
     * the resolved triage user's email when unset.
     */
    'notify_email' => env('SUPPORT_NOTIFY_EMAIL'),
];
