<?php
/**
 * Class PluginZendeskSync
 * @author iSalePro Team
 */

class PluginZendeskSync extends CommonGLPI
{
    static $config = array();
    static $response_data = array();
    static $rightname = "plugin_zendesksync";

    static function updateConfig($data)
    {
        global $DB;
        $value = serialize(array(
            'categoryId' => $data['itilcategories_id'],
            'url' => $data['url'],
            'email' => $data['email'],
            'key' => $data['key'],
            'hour' => $data['hour'],
            'zdStatusId' => $data['zdStatusId'],
        ));
        $DB->query("UPDATE glpi_configs SET value='$value' WHERE context='isalepro' AND name='zendesk_data'");
        $frequency = $data['hour'] * 60 * 60;
        $DB->query("UPDATE glpi_crontasks SET frequency='$frequency' WHERE itemtype='PluginZendeskSync' AND name='SyncZendesk'");
        return true;
    }

    static function getConfig()
    {
        if (count(self::$config)) {
            return self::$config;
        } else {
            self::initConfig();
            return self::$config;
        }
    }



    /**
     * Get zendesk statuses for configure Integration
     *
     * @return array
     */
    static function getZdStatuses()
    {
        global $DB;
        $projects = [];

        if (!empty(self::$config['url']) && !empty(self::$config['key'])) {
            self::syncStatuses();
        }

        $result = $DB->request("SELECT * FROM glpi_plugin_zendesksync_statuses ORDER BY zd_status_id");
        foreach ($result as $value) {
            $projects[] = $value;
        }
        return $projects;
    }


    static function initConfig()
    {
        global $DB;
        $result = $DB->query("SELECT * FROM glpi_configs WHERE context='isalepro' AND name='zendesk_data'");
        if ($result->num_rows == 0) {
            self::$config = array(
                'categoryId' => '',
                'url' => '',
                'email' => '',
                'key' => '',
                'hour' => 600,
                'zdStatusId' => [],
            );
            $config = serialize(self::$config);
            $DB->query("INSERT INTO glpi_configs SET context='isalepro', name='zendesk_data', value='$config'");
        } else {
            $result = $DB->request("SELECT * FROM glpi_configs WHERE context='isalepro' AND name='zendesk_data'");
            foreach ($result as $value) {
                self::$config = unserialize($value['value']);
                return;
            }
        }
    }

    /**
     * Cron command sync
     *
     * @param $task
     * @return bool
     */
    static function cronSyncZendesk($task)
    {
        self::initConfig();
        self::syncAddTasks();
        self::syncChangedTasks();
        self::syncTasksStatuses();
        return true;
    }

    /**
     * to sync statuses
     *
     * @return array|bool
     */
    static function syncStatuses()
    {
        global $DB;

        $zdStauses = [
            ['id' => 'new', 'name' => 'Новый'],
            ['id' => 'open', 'name' => 'Открыт'],
            ['id' => 'pending', 'name' => 'В ожидании'],
            ['id' => 'hold', 'name' => 'В паузе'],
            ['id' => 'solved', 'name' => 'Выполнен'],
            ['id' => 'closed', 'name' => 'Закрыт'],
        ];

        $statusData = [];

        foreach ($zdStauses as $value) {
            $statusData[] = [
                'zd_status_id' => $value['id'],
                'zd_status_name' => $value['name'],
            ];
        }

        foreach ($statusData as $statusDatum) {

            $DB->updateOrInsert(
                'glpi_plugin_zendesksync_statuses',
                $statusDatum,
                ['zd_status_id' => $statusDatum['zd_status_id']]
            );
        }
        return true;
    }

    /**
     * to add task
     *
     * @return bool
     */
    static function syncAddTasks()
    {
        global $DB;
        if (self::$config['url'] == '' || self::$config['key'] == '') {
            return false;
        }

        $lastDate = date("Y-m-d H:i:s", strtotime('-' . self::$config['hour'] . ' seconds'));
        $whereCategory = self::$config['categoryId']
            ? ' AND t.itilcategories_id = ' . self::$config['categoryId'] .' '
            : '';
        $result = $DB->request("
            SELECT 
                t.*
            FROM 
                 glpi_tickets t 
                 LEFT JOIN glpi_plugin_zendesksync_tickets zt ON zt.ticket_id = t.id
             WHERE 
                   t.date >= '" . $lastDate . "'
                   ".$whereCategory."
                   AND zt.id IS NULL");
        $tickets = [];
        foreach ($result as $item) {
            $tickets[] = $item;
        }

        if ($tickets) {
            foreach ($tickets as $ticket) {
                $ch = curl_init();
                $requestUrl = self::$config['url'] . '/api/v2/tickets';

                $postData = [
                    'ticket' => [
                        'subject' => $ticket['name'],
                        'comment' => [
                            'body' => strip_tags(htmlspecialchars_decode($ticket['content']))],
                    ]
                ];

                $payload = json_encode($postData);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt($ch, CURLOPT_URL, $requestUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                $response = curl_exec($ch);
                $result = json_decode($response);
                if (NULL == $result || empty($result['ticket'])) {
                    return true;
                }
                $now = date('Y-m-d H:i:s');
                $zdIssueId = $result['ticket']['id'];
                $addHistorySql = "INSERT INTO glpi_plugin_zendesksync_tickets SET ticket_id='" . $ticket['id'] . "', zd_task_id='$zdIssueId', created_at='$now'";
                $DB->query($addHistorySql);
            }
        }
        return true;
    }

    /**
     * to sync tasks statuses
     *
     * @return array|bool
     */
    static function syncTasksStatuses()
    {
        global $DB;
        if (self::$config['url'] == '' || self::$config['key'] == '' || empty(self::$config['zdStatusId'])) {
            return false;
        }

        $needSynchTickts = $DB->request("
            SELECT 
                zt.*
            FROM 
                glpi_plugin_zendesksync_tickets zt
                JOIN glpi_tickets t  ON zt.ticket_id = t.id
            WHERE 
                 t.status NOT IN (" . Ticket::SOLVED . ", " . Ticket::CLOSED . ")");
        $tickets = [];
        foreach ($needSynchTickts as $item) {
            $tickets[$item['zd_task_id']] = $item;
        }

        $ticketIds = array_map(function ($ticket) {
            return $ticket['zd_task_id'];
        }, $tickets);

        if ($ticketIds) {
            foreach ($ticketIds as $ticketId) {
                $ch = curl_init();
                $requestUrl = self::$config['url'] . '/api/v2/tickets/' . $ticketId . '.json';
                curl_setopt($ch, CURLOPT_URL, $requestUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                $response = curl_exec($ch);
                $result = json_decode($response);

                // no ticket
                if (NULL == $result || empty($result['ticket'])) {
                    continue;
                }

                $zdTicket = $result['ticket'];

                // обновляем статус заявки
                $newTicketStatusId = self::$config['zdStatusId'][$zdTicket['status']];
                $ticket = $tickets[$zdTicket['id']];
                $updateStatusSql = "UPDATE glpi_tickets SET status=" . $newTicketStatusId . " WHERE id = " . $ticket['ticket_id'];
                $DB->query($updateStatusSql);


                // забираем список комментариев и аттачментов
                $ch = curl_init();
                $requestUrl = self::$config['url'] . '/api/v2/tickets/' . $ticketId . '/comments.json';

                curl_setopt($ch, CURLOPT_URL, $requestUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                $response = curl_exec($ch);
                $result = json_decode($response);


                // разбираемся с комментариями
                if(!empty($result['comments'])){
                    $existComments = $DB->request("
                    SELECT 
                        *
                    FROM 
                        glpi_plugin_zendesksync_ticket_comments 
                    WHERE 
                         zd_task_id = ". $zdTicket['id']);
                    $comments = [];
                    foreach ($existComments as $item) {
                        $comments[$item['zd_comment_id']] = $item;
                    }


                    foreach ($result['comments'] as $journal) {
                        // нет такого коммента в БД
                        if(empty($comments[$journal['id']])) {
                            // сохраняем только комменты  с текстом и которых еще нет в БД
                            if(!empty($journal['body']) && !self::checkExistTextComment($ticket['ticket_id'], $journal['body'])) {

                                // обычный комментарий
                                $fup = new ITILFollowup();
                                $fupData = [
                                    'content' => $journal['body'],
                                    'itemtype' => 'Ticket',
                                    'items_id' => $ticket['ticket_id'],
                                    'requesttypes_id' => 4,
                                    'is_private' => 0,
                                    'add' => 'Добавить',
                                ];

                                $commentId = $fup->add($fupData);

                                $now = date('Y-m-d H:i:s');
                                $addHistorySql = "INSERT INTO glpi_plugin_zendesksync_ticket_comments SET ticket_id='" . $ticket['ticket_id'] . "', comment_id='" . $commentId . "', zd_task_id='".$zdTicket['id']."', zd_comment_id='".$journal['id']."', created_at='$now'";
                                $DB->query($addHistorySql);

                            }

                            if(!empty($journal['attachments'])) {
                                // в GLPI встроена проверка от дубликатов, поэтому не заморачиваемся этим
                                foreach ($journal['attachments'] as $attachment) {
                                    $fileId = $attachment['id'];
                                    $fileName = time() . '.' . $attachment['file_name'];

                                    // $requestUrl = self::$config['url'] . '/attachments/download/' . $fileId . '?key=' . self::$config['key'];
                                    $requestUrl = $attachment['content_url'];

                                    curl_setopt($ch, CURLOPT_URL, $requestUrl);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    $fileContent = curl_exec($ch);

                                    $tmpFileDir = GLPI_TMP_DIR . '/';
                                    if (file_put_contents($tmpFileDir . $fileName, $fileContent)) {
                                        $tag = Rule::getUuid();
                                        $info = pathinfo($fileName);
                                        $prefixFilename =  basename($fileName,'.'.$info['extension']);

                                        $fileData = [
                                            '_filename' => [$fileName],
                                            '_prefix_filename' => [$prefixFilename],
                                            '_tag_filename' => [$tag],
                                        ];

                                        $ticketObj = new Ticket();
                                        $ticketObj->getFromDB($ticket['ticket_id']);
                                        $ticketObj->addFiles($fileData, ['force_update' => true]);

                                        $documents = $DB->request("SELECT * FROM glpi_documents WHERE tag = '" . $tag . "'");
                                        foreach ($documents as $document) {
                                            $now = date('Y-m-d H:i:s');
                                            $addHistorySql = "INSERT INTO glpi_plugin_zendesksync_ticket_attachments SET ticket_id='" . $ticket['ticket_id'] . "', document_id='" . $document['id'] . "', zd_task_id='".$zdTicket['id']."', zd_document_id='$fileId', created_at='$now'";
                                            $DB->query($addHistorySql);
                                        }

                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return  true;
    }


    /**
     * to put task
     *
     * @return bool
     */
    static function syncChangedTasks()
    {
        global $DB;
        if (self::$config['url'] == '' || self::$config['key'] == '') {
            return false;
        }

        $lastDate = date("Y-m-d H:i:s", strtotime('-' . self::$config['hour'] . ' seconds'));
        $whereCategory = self::$config['categoryId']
            ? ' AND t.itilcategories_id = ' . self::$config['categoryId'] .' '
            : '';
        $result = $DB->request("
            SELECT 
                t.*,
                zt.zd_task_id
            FROM 
                 glpi_tickets t 
                 JOIN glpi_plugin_zendesksync_tickets zt ON zt.ticket_id = t.id
             WHERE 
                   t.date_mod >= '" . $lastDate . "'
                   ".$whereCategory."
                   AND zt.id IS NOT NULL");
        $tickets = [];
        foreach ($result as $item) {
            $tickets[] = $item;
        }

        if ($tickets) {
            foreach ($tickets as $ticket) {
                // извлекаем недобалвеленные за последний интервал синхронизации документы
                $documents = $DB->request("
                    SELECT 
                           d.* 
                    FROM 
                         glpi_documents d
                        LEFT JOIN glpi_documents_items di ON di.documents_id = d.id
                        LEFT JOIN  glpi_plugin_zendesksync_ticket_attachments zta on zta.document_id = d.id
                    WHERE 
                          ((di.itemtype = 'Ticket' AND
                          di.items_id = ".$ticket['id'].") OR (
                          di.id is null AND d.tickets_id = ".$ticket['id'].")) 
                          AND
                          d.date_mod >= '" . $lastDate . "' AND
                          zta.id IS NULL");

                foreach ($documents as $document) {
                    if (file_exists(GLPI_DOC_DIR."/".$document['filepath'])) {
                        $file = fopen(GLPI_DOC_DIR."/".$document['filepath'], 'r');
                        $size = filesize(GLPI_DOC_DIR."/".$document['filepath']);
                        $filedata = fread($file, $size);

                        $ch = curl_init();

                        $requestUrl = self::$config['url'] . '/api/v2/uploads?filename='.urlencode($document['filename']);

                        curl_setopt($ch, CURLOPT_POSTFIELDS, $filedata);
                        curl_setopt($ch, CURLOPT_INFILE, $file);
                        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/octet-stream'));

                        curl_setopt($ch, CURLOPT_URL, $requestUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                        $response = curl_exec($ch);
                        $result = json_decode($response);

                        if (!empty($result['upload'])) {

                            $ch = curl_init();
                            $requestUrl = self::$config['url'] . '/api/v2/tickets/'.$ticket['zd_task_id'].'.json';

                            $postData = [
                                'ticket' => [
                                    'comment' => [[
                                        'body' => 'Файл из СКИТ',
                                        'uploads' => $result['upload']['token'],
                                    ]]
                                ]
                            ];

                            $payload = json_encode($postData);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                            curl_setopt($ch, CURLOPT_URL, $requestUrl);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                            $response = curl_exec($ch);
                            var_dump($response);
                            // exit;
                        }
                    }
                }

                // извлекаем недобалвеленные за последний интервал синхронизации комментарии
                $comments = $DB->request("
                    SELECT 
                           f.* 
                    FROM 
                         glpi_itilfollowups f
                        LEFT JOIN  glpi_plugin_zendesksync_ticket_comments ztc on ztc.comment_id = f.id
                    WHERE 
                          f.itemtype = 'Ticket' AND
                          f.requesttypes_id = 4 AND
                          f.is_private = 0 AND
                          f.items_id = ".$ticket['id']." AND
                          f.date_mod >= '" . $lastDate . "' AND
                          ztc.id IS NULL");
                $noteData = [];
                foreach ($comments as $comment) {
                    $noteData[] = $comment['content'];

                    $ch = curl_init();
                    $requestUrl = self::$config['url'] . '/api/v2/tickets/'.$ticket['zd_task_id'].'.json';

                    $postData = [
                        'ticket' => [
                            'comment' => [[
                                'body' => strip_tags(htmlspecialchars_decode($comment['content'])),
                                'uploads' => $result['upload']['token'],
                            ]]
                        ]
                    ];

                    $payload = json_encode($postData);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                    curl_setopt($ch, CURLOPT_URL, $requestUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERPWD, self::$config['email'] . "/token:" . self::$config['key']);
                    $response = curl_exec($ch);
                    var_dump($response);
                    // exit;
                }
            }
        }
        return true;
    }

    static function checkExistTextComment($ticketId, $text)
    {
        global $DB;

        $result = $DB->request("
            SELECT 
                id
            FROM 
                 glpi_itilfollowups f
             WHERE 
                   f.itemtype = 'Ticket' AND
                    f.requesttypes_id = 4 AND
                    f.content = '".$text."' AND
                    f.items_id = ".$ticketId);

        if($result->count()) {
            return true;
        } else {
            return false;
        }

    }

}
