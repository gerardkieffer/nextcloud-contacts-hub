import { createApp } from 'vue'

// Toasts are styled by this, and by nothing else. Without it showError() and
// showSuccess() still run, still insert their element, and are invisible --
// so every failure in this app was reported to a user who saw nothing at
// all. Found when a sync job with a stale endpoint password appeared to do
// nothing on click while the server log held a perfectly clear 401.
import '@nextcloud/dialogs/style.css'

import App from './App.vue'
import './app.css'

createApp(App).mount('#contacthub')
