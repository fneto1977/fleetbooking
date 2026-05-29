<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_admin", READ);

Html::header(
    __('Fleet Booking Configuration', 'fleetbooking'),
    '/plugins/fleetbooking/front/config.php',
    "setup",
    "plugin",
    "fleetbooking"
);

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

echo "<div class='center'>";
echo "<form method='get' action='config.php'>";
echo "<h3>" . __('Select Entity to Configure', 'fleetbooking') . "</h3>";
\Entity::dropdown([
    'name' => 'entities_id',
    'value' => $entities_id,
    'on_change' => 'this.form.submit()'
]);
echo "</form>";
echo "</div><br>";

// Show the config form for the selected entity
$config = new \GlpiPlugin\Fleetbooking\Config();
$config->showConfigForm($entities_id);

Html::footer();
