<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_request", READ);

Html::header(
    \GlpiPlugin\Fleetbooking\Request::getTypeName(Session::getPluralNumber()),
    '/plugins/fleetbooking/front/request.php',
    'tools',
    'GlpiPlugin\Fleetbooking\Request'
);

// Insert default display preferences for users_id=0 only if none exist yet.
// Never delete existing preferences — users' custom column settings must be preserved.
global $DB;
$existing = $DB->request([
    'COUNT' => 'c',
    'FROM' => 'glpi_displaypreferences',
    'WHERE' => [
        'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
        'users_id' => 0,
    ]
])->current()['c'];

if ($existing === 0) {
    $defaults = [1 => 7, 2 => 4, 3 => 5, 4 => 2, 5 => 3, 6 => 1, 7 => 6];
    foreach ($defaults as $rank => $num) {
        $DB->insert('glpi_displaypreferences', [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => 0,
            'num' => $num,
            'rank' => $rank
        ]);
    }
}

// If current user has 1 or fewer saved columns, clear them to load the defaults
$currentUserId = (int) Session::getLoginUserID();
if ($currentUserId > 0) {
    $userExists = $DB->request([
        'FROM' => 'glpi_displaypreferences',
        'WHERE' => [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => $currentUserId
        ]
    ]);
    if (count($userExists) <= 1) {
        $DB->delete(
            'glpi_displaypreferences',
            [
                'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
                'users_id' => $currentUserId
            ]
        );
    }
}

// Inject default search criteria and sorting when the user has not
// applied any manual filter yet. This ensures the list shows only recent
// requests (last 7 days) ordered by start date descending on first load.
if (!isset($_GET['criteria']) || (is_array($_GET['criteria']) && count($_GET['criteria']) === 0)) {
    $_GET['criteria'] = [
        0 => [
            'link' => 'AND',
            'field' => 2,    // start_datetime field id from rawSearchOptions
            'searchtype' => 'morethan',
            'value' => '-7DAY',
        ],
    ];
    $_GET['sort'] = [2];       // sort by start_datetime
    $_GET['order'] = ['DESC'];  // most recent first
    $_GET['itemtype'] = \GlpiPlugin\Fleetbooking\Request::class;
    $_GET['start'] = 0;
}

// List using standard Search API
\Search::show(\GlpiPlugin\Fleetbooking\Request::class);

Html::footer();
