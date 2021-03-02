<?php
/**
 * Called when user click on Install - Needed
 */
if (!function_exists('plugin_zendesksync_install')) {
    function plugin_zendesksync_install() {
        global $DB;
        $DB->query("
            CREATE TABLE IF NOT EXISTS `glpi_plugin_zendesksync_ticket_attachments` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `ticket_id` int NOT NULL,
                `document_id` int NOT NULL,
                `zd_task_id` int NOT NULL COMMENT 'zendesk task id',
                `zd_document_id` int NOT NULL COMMENT 'zendesk task attachment id',
                `created_at` datetime NOT NULL
            );
        ");

        $DB->query("DROP TABLE `glpi_plugin_zendesksync_ticket_comments`");
        $DB->query("
            CREATE TABLE IF NOT EXISTS `glpi_plugin_zendesksync_ticket_comments` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `ticket_id` int NOT NULL,
                `comment_id` int NOT NULL,
                `zd_task_id` int NOT NULL COMMENT 'zendesk task id',
                `zd_comment_id` int NOT NULL COMMENT 'zendesk task comment id',
                `created_at` datetime NOT NULL
            );
        ");

        $DB->query("
            CREATE TABLE IF NOT EXISTS `glpi_plugin_zendesksync_tickets` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `ticket_id` int NOT NULL,
                `zd_task_id` int NOT NULL COMMENT 'zendesk task id',
                `created_at` datetime NOT NULL
            );
        ");

        $DB->query("
            CREATE TABLE IF NOT EXISTS `glpi_plugin_zendesksync_statuses` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `zd_status_id` int NOT NULL COMMENT 'zendesk status id',
                `zd_status_name` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'zendesk status name' ,
                `created_at` datetime NOT NULL
            );
        ");

        CronTask::register('PluginZendeskSync', 'SyncZendesk', 600,
            array(
            'comment'   => 'Sync tickets from zendesk',
            'mode'      => CronTask::MODE_EXTERNAL
        ));

        return true;
    }
}

/**
 * Called when user click on Uninstall - Needed
 */
if (!function_exists('plugin_zendesksync_uninstall')) {
    function plugin_zendesksync_uninstall() { return true; }
}

if (!function_exists('zendesksync_item_can')) {
    function zendesksync_item_can($param){
      $param->right=1;
      return true;
    }
}
