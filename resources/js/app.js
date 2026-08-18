import './bootstrap';

import Alpine from 'alpinejs';

// Alpine drives the receipt review screen: inline field editing, confidence
// highlighting and the confirm/reject actions. The rest of the UI is plain
// server-rendered Blade.
window.Alpine = Alpine;
Alpine.start();
