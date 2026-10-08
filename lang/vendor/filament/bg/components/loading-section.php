<?php

/*
 * App-level patch over Filament's bg translations (filament/support registers
 * the «filament» namespace; nested groups map to subdirectories here). The
 * shipped bg locale has no components/loading-section.php at all, so the
 * lazy-loaded schema placeholder (dashboard, users, loan edit) announced a
 * raw key (admin crawl, Filament 5.10.1, 2026-10-08).
 * Remove a key once upstream Filament ships it.
 */
return [

    'label' => 'Зареждане...',

];
