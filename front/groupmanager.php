<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_admin", READ);

$groupManager = new \GlpiPlugin\Fleetbooking\GroupManager();

if (isset($_POST["add"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $groupManager->check(-1, CREATE);
    $groupManager->add($_POST);
    Html::back();
} else if (isset($_POST["update"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $groupManager->check($_POST['id'], UPDATE);
    $groupManager->update($_POST);
    Html::back();
} else if (isset($_POST["purge"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $groupManager->check($_POST['id'], PURGE);
    $groupManager->delete($_POST, 1);
    Html::back();
}

Html::header(
    \GlpiPlugin\Fleetbooking\GroupManager::getTypeName(Session::getPluralNumber()),
    '/plugins/fleetbooking/front/groupmanager.php',
    'tools',
    \GlpiPlugin\Fleetbooking\Request::class
);

// Add form at the top
echo "<div class='center'>";
$groupManager->showForm(0);
echo "</div>";

// List using standard Search API
\Search::show(\GlpiPlugin\Fleetbooking\GroupManager::class);

Html::footer();
