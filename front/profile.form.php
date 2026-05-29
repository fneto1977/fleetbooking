<?php

include ("../../../inc/includes.php");

Session::checkRight("profile", UPDATE);

if (isset($_POST["update"]) && isset($_POST["profiles_id"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    $profiles_id = intval($_POST["profiles_id"]);
    $rights      = $_POST['_rights'] ?? [];

    $all_rights = [
        'fleetbooking_read',
        'fleetbooking_request',
        'fleetbooking_approve',
        'fleetbooking_admin'
    ];

    $profileRight = new ProfileRight();

    foreach ($all_rights as $right) {
        $value = !empty($rights[$right]) ? 1 : 0;
        
        if ($profileRight->getFromDBByCrit(['profiles_id' => $profiles_id, 'name' => $right])) {
            $profileRight->update([
                'id'     => $profileRight->getID(),
                'rights' => $value
            ]);
        } else {
            $profileRight->add([
                'profiles_id' => $profiles_id,
                'name'        => $right,
                'rights'      => $value
            ]);
        }
    }

    Html::back();
}

Html::back();
