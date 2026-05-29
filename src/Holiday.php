<?php

namespace GlpiPlugin\Fleetbooking;

use CommonDBTM;

/**
 * Class Holiday
 * Management of non-working days for validation purposes.
 */
class Holiday extends CommonDBTM
{

    static $rightname = 'fleetbooking_admin';

    static function getTypeName($nb = 0): string
    {
        return _n('Fleet Holiday', 'Fleet Holidays', $nb, 'fleetbooking');
    }

    public function rawSearchOptions(): array
    {
        $tab = [];
        $tab[] = [
            'id' => 'common',
            'name' => __('Characteristics')
        ];
        $tab[] = [
            'id' => '1',
            'table' => $this->getTable(),
            'field' => 'holiday_date',
            'name' => __('Date'),
            'datatype' => 'date'
        ];
        $tab[] = [
            'id' => '2',
            'table' => $this->getTable(),
            'field' => 'description',
            'name' => __('Description'),
            'datatype' => 'string'
        ];
        return $tab;
    }

    public function showForm($id, array $options = [])
    {
        $this->initForm($id, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Date') . "</td>";
        echo "<td>";
        \Html::showDateField('holiday_date', ['value' => $this->fields['holiday_date'] ?? date('Y-m-d')]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Description') . "</td>";
        echo "<td>";
        echo "<input type='text' name='description' value='" . htmlspecialchars($this->fields['description'] ?? '', ENT_QUOTES, 'UTF-8') . "' size='50'>";
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);
    }
}
