<?php

/*
 * App-level patch over Filament's bg translations. The shipped bg locale has
 * no error-notifications.php at all, yet every admin page embeds these lines
 * (window.filamentErrorNotifications, emitted while APP_DEBUG is off) and
 * shows them as a visible danger toast when a Livewire request fails — a 500
 * from an action or a network drop during a deploy or MySQL restart.
 * Remove a key once upstream Filament ships it.
 */
return [

    'title' => 'Грешка при зареждане на страницата',

    'body' => 'Възникна грешка при зареждането на страницата. Моля, опитайте отново по-късно.',

];
