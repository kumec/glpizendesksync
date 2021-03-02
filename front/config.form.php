<?php

include('../../../inc/includes.php');

$plugin = new Plugin();
if ($plugin->isActivated("zendesksync")) {
    if (!empty($_POST["update"])) {
        PluginZendeskSync::updateConfig($_POST);
        Session::addMessageAfterRedirect(__('Config updated successfully'), false, INFO);
        Html::back();
    } else {
        $config = PluginZendeskSync::getConfig();
        $statuses = PluginZendeskSync::getZdStatuses();
        $glpiTicketStatuses = Ticket::getAllStatusArray(false);
        Html::header('ZendescConfig', '', "admin", "pluginzendesksync");
        echo '<form method="post" name="helpdeskform" action="">'; ?>
        <table class="tab_cadre_fixehov">
            <tr>
            <td colspan="2">
                <h2>Настройка интеграции с Zendesk</h2>
            </td>
            </tr>
            <tr class='tab_bg_2'>
            <td>Категория</td>
            <td>
                <?php ITILCategory::dropdown(['value' => $config['categoryId']]); ?>
            </td>
            </tr>

            <tr class='tab_bg_2'>
            <td>URL</td>
            <td>
                <input type="text" name="url" value="<?php echo $config['url']; ?>">
            </td>
            </tr>

            <tr class='tab_bg_2'>
            <td>Email</td>
            <td>
                <input type="text" name="email" value="<?php echo $config['email']; ?>">
            </td>
            </tr>
            <tr class='tab_bg_2'>
            <td>Api key</td>
            <td>
                <input type="text" name="key" value="<?php echo $config['key']; ?>">
            </td>
            </tr>
            <tr class='tab_bg_2'>
            <td>Интервал запуска синхронизации</td>
            <td>
                <select name="hour" class="form-control">
                    <?php
                    $default=$config['hour'];

                    $minutes = [5, 10, 15, 30];
                    foreach ($minutes as $minute) {
                        $selected = ($minute*60)==$default?' selected="selected" ':'';
                        echo "<option $selected value='" . ($minute*60) ."'>$minute Minute</option>";
                    }
                    for ($i=1; $i <=24 ; $i++) { 
                        $selected = ($i*60*60)==$default?' selected="selected" ':'';
                        echo "<option $selected value='".($i*60*60)."'>$i Hour</option>";
                    }
                    ?>
                </select>
            </td>
            </tr><tr>
            <td colspan="2">Статусы</td>
            </tr>
            <?php
            foreach ($statuses as $status){
                echo "<tr>";
                    echo "<td>";
                        echo $status['zd_status_name'];
                    echo "</td>";
                    echo "<td>";
                        echo "<select name=\"zdStatusId[".$status['zd_status_id']."]\" class=\"form-control\">";
                            $default=$config['zdStatusId'][$status['zd_status_id']];
                            foreach ($glpiTicketStatuses as $glpiTicketStatusId => $glpiTicketStatusName) {
                                $selected = $glpiTicketStatusId == $default ? ' selected="selected" ' : '';
                                echo "<option $selected value='".$glpiTicketStatusId."'>".$glpiTicketStatusName."</option>";
                            }
                        echo "</select>";
                    echo "</td>";
                echo "</tr>";
            }
            ?>
            <tr>
                <td colspan="2">
                    <center><input type="submit" value="Сохранить" name="update" class="submit"></center>
                </td>
            </tr>
        </table>
        
        <?php Html::closeForm();
        Html::footer();
    }
} else {
    Html::header(__('Setup'), '', "config", "plugins");
    echo "<div align='center'><br><br>";
    echo "<img src=\"" . $CFG_GLPI["root_doc"] . "/pics/warning.png\" alt='warning'><br><br>";
    echo "<b>" . __('Please activate the plugin', 'zendesksync') . "</b></div>";
    Html::footer();
}
