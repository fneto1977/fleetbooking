<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_admin", READ);

$holiday = new \GlpiPlugin\Fleetbooking\Holiday();

if (isset($_POST["add"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $holiday->check(-1, CREATE);
    $holiday->add($_POST);
    Html::back();
} else if (isset($_POST["update"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $holiday->check($_POST['id'], UPDATE);
    $holiday->update($_POST);
    Html::back();
} else if (isset($_POST["purge"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $holiday->check($_POST['id'], PURGE);
    $holiday->delete($_POST, 1);
    Html::back();
}

Html::header(
    \GlpiPlugin\Fleetbooking\Holiday::getTypeName(Session::getPluralNumber()),
    '/plugins/fleetbooking/front/holiday.php',
    'tools',
    \GlpiPlugin\Fleetbooking\Request::class
);

// Add form at the top (optional, but convenient for simple types)
echo "<div class='center'>";
$holiday->showForm(0);
echo "</div>";

// List using standard Search API
\Search::show(\GlpiPlugin\Fleetbooking\Holiday::class);

Html::footer();
