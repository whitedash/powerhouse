/*
 * Real-time notification groundwork (INACTIVE).
 *
 * laravel-echo + pusher-js are installed and ready. To go live:
 *   1. Set BROADCAST_CONNECTION=pusher and fill the PUSHER_* env vars.
 *   2. Uncomment the block below.
 *   3. Import this file from resources/js/app.js (see the note there).
 *   4. Subscribe in InternalLayout, e.g.:
 *        window.Echo.private(`App.Models.User.${userId}`)
 *          .notification((n) => { /* push n into the bell list * / });
 *
 * Left commented so a build without Pusher credentials never tries to
 * open a websocket — the in-app bell works fully without it.
 */

// import Echo from 'laravel-echo';
// import Pusher from 'pusher-js';
//
// window.Pusher = Pusher;
// window.Echo = new Echo({
//     broadcaster: 'pusher',
//     key: import.meta.env.VITE_PUSHER_APP_KEY,
//     cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'eu',
//     forceTLS: true,
// });

export {};
