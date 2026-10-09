import { createApp } from 'vue'

// Same reason as in main.js: without the stylesheet, toasts render as
// nothing and a failed save looks like a successful one.
import '@nextcloud/dialogs/style.css'

import NotificationSettings from './components/NotificationSettings.vue'

createApp(NotificationSettings).mount('#contacthub-settings')
