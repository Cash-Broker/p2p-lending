<?php

/*
 * App-level patch over Filament's bg translations (filament/support registers
 * the «filament» namespace; nested groups map to subdirectories here). The
 * shipped bg locale has no components/breadcrumbs.php at all, so the
 * breadcrumb <nav> aria-label rendered as a raw key on most admin pages
 * (admin crawl, Filament 5.10.1, 2026-10-08).
 * Remove a key once upstream Filament ships it.
 */
return [

    'label' => 'Навигационна пътека',

];
