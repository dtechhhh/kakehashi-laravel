<?php

return [
    /*
     * Permission-aware navigation items (presentation only).
     *
     * An item is rendered only when:
     * - the named route is actually registered (Route::has), and
     * - the current user passes the ability check (Gate or permission).
     *
     * Authorization is never decided by hiding menu items; server-side
     * Policy/Service checks remain authoritative.
     */
    'items' => [
        [
            'label' => 'ui.nav.home',
            'route' => 'home',
            'ability' => null,
            'icon' => 'home',
        ],
        [
            'label' => 'ui.nav.candidates',
            'route' => 'candidate.index',
            'ability' => 'candidate.view',
            'icon' => 'users',
        ],
        [
            'label' => 'ui.nav.jobs',
            'route' => 'jobs.index',
            'ability' => 'jobs.view',
            'icon' => 'briefcase',
        ],
        [
            'label' => 'ui.nav.lookup',
            'route' => 'lookup.index',
            'ability' => 'lookup.manage',
            'icon' => 'list',
        ],
        [
            'label' => 'ui.nav.requests',
            'route' => 'lookup.requests',
            'ability' => 'lookup.request.decide',
            'icon' => 'inbox',
        ],
        [
            'label' => 'ui.nav.companies',
            'route' => 'company.index',
            'ability' => 'company.manage',
            'icon' => 'building',
        ],
        [
            'label' => 'ui.nav.users',
            'route' => 'admin.users',
            'ability' => 'users.view',
            'icon' => 'users',
        ],
        [
            'label' => 'ui.nav.audit',
            'route' => 'audit.index',
            'ability' => 'audit.view',
            'icon' => 'file',
        ],
    ],
];
