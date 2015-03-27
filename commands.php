<?php
use \pulyavin\WebMoney\WMXml;

class commands
{
    // Dependency Injection
    private $wmxml;
    private $pdo;
    // массив кошельков по идентификаторам
    private $purses = [];
    // массив кошельков по именам
    private $wallets = [];
    // массив системных данных
    private $system = [];
    private $userinfo = [];
    // типы событий
    const EVENT_TRANSFER = 1; # обычный перевод
    const EVENT_PROTECTION = 2; # перевод с протекцией
    const EVENT_INVOICE = 3; # счёт на оплату
    // типы поисквых объектов
    const SEARCH_TRANSACTIONS = 1; # поиск по транзакциям
    const SEARCH_INVOICES = 2; # поиск по входящим счетам
    const SEARCH_OUTVOICES = 3; # поиск по исходящим счетам

    /**
     * Инициализация библиотеки
     * @param WMXml $wmxml $wmxml инстанс объекта wmxml
     * @param PDO $pdo инстанс объекта PDO
     */
    public function __construct(WMXml $wmxml, PDO $pdo)
    {
        $this->wmxml = $wmxml;
        $this->pdo = $pdo;

        // вытаскиваем системные данные
        $sql = $this->pdo->query("SELECT * FROM `system`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $this->system[$row['name']] = $row['value'];
        }

        // вытаскиваем слепок кошельков
        $sql = $this->pdo->query("SELECT * FROM `purses`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $this->purses[$row['id']] = $row;
            $this->wallets[$row['pursename']] = $row;
        }

        // вытаскиваем данные пользователя
        $sql = $this->pdo->query("SELECT * FROM `userinfo`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $this->userinfo[$row['name']] = $row['value'];
        }

        // вытаскиваем последний BL
        $row = $this->pdo->query("SELECT `rank` FROM `bl` ORDER BY `time` DESC LIMIT 0, 1")->fetch(PDO::FETCH_ASSOC);
        $this->userinfo['bl'] = $row['rank'];
        // вытаскиваем последний TL
        $row = $this->pdo->query("SELECT `rank` FROM `tl` ORDER BY `time` DESC LIMIT 0, 1")->fetch(PDO::FETCH_ASSOC);
        $this->userinfo['tl'] = $row['rank'];

        // получаем BL:бизнес уровень
        try {
            $bl = $this->wmxml->getBl();
            if ($bl['is_error'] == 0 && $this->userinfo['bl'] != $bl['data']['value']) {
                $this->userinfo['bl'] = $bl['data']['value'];
                $sql = $this->pdo->prepare("
                INSERT INTO `bl` (
                    `time`,
                    `rank`
                )
                VALUES (
                    :time,
                    :rank
                )
            ");
                $sql->bindValue(":time", time());
                $sql->bindValue(":rank", $bl['data']['value']);
                $sql->execute();
            }
        }
        catch (Exception $e) {
            $this->userinfo['bl'] = "-//-";
        }

        // получаем TL:уровень доверия
        try {
            $tl = $this->wmxml->getTl();
            if ($tl['is_error'] == 0 && $this->userinfo['tl'] != $tl['data']['value']) {
                $this->userinfo['tl'] = $tl['data']['value'];
                $sql = $this->pdo->prepare("
                    INSERT INTO `tl` (
                        `time`,
                        `rank`
                    )
                    VALUES (
                        :time,
                        :rank
                    )
                ");
                $sql->bindValue(":time", time());
                $sql->bindValue(":rank", $tl['data']['value']);
                $sql->execute();
            }
        }
        catch (Exception $e) {
            $this->userinfo['tl'] = "-//-";
        }
    }

    /**
     * pursesGet(): возвращаем список кошельков и балансы по ним
     * @return array
     */
    public function pursesGet()
    {
        return $this->purses;
    }

    /**
     * userinfoGet(): получение персональной информации пользователя
     * @return array
     */
    public function userinfoGet()
    {
        return $this->userinfo;
    }

    /**
     * refreshCommand(): обновление всех данных
     */
    public function refreshCommand()
    {
        // это первый запуск и сохранение списка кошельков
        if (empty($this->system['is_syncpurses'])) {
            $this->initCommand();
        }

        // обновляем информацию о состоянии кошельков
        try {
            $wmxml = $this->wmxml->xml9();
            if ($wmxml['is_error']) {
                throw new Exception($wmxml['error_message'], $wmxml['error_code']);
            }
            $purses = $wmxml['data'];
        }
        catch (Exception $e) {
            throw new Exception("WMXml exception: " . $e->getMessage());
        }

        foreach ($purses as $purse) {
            $sql = $this->pdo->prepare("
                UPDATE `purses` SET
                    `description`   =   :desc,
                    `amount_last`   =   `amount`,
                    `amount`        =   :amount
                WHERE
                    `pursename` = :pursename
            ");
            $sql->bindValue(":desc", $purse['desc']);
            $sql->bindValue(":amount", $purse['amount']);
            $sql->bindValue(":pursename", $purse['pursename']);
            $sql->execute();
        }

        // строим кэши для синхронизации
        $cache = [];
        $sql = $this->pdo->query("SELECT * FROM `transactions`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $cache['transactions'][$row['purse']][$row['id']] = $row;
        }
        $sql = $this->pdo->query("SELECT * FROM `outvoices`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $cache['outvoices'][$row['storepurse']][$row['id']] = $row;
        }
        $sql = $this->pdo->query("SELECT * FROM `invoices`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $cache['invoices'][$row['id']] = $row;
        }
        // вытаскиваем временные слепки
        $times = [];
        $sql = $this->pdo->query("SELECT * FROM `purses_times`");
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $times[$row['pursename']][$row['xml_id']] = $row['time'];
        }

        // для каждого кошелька нужно посмотреть новые транзакции и выписанные счета
        $sql = $this->pdo->query("SELECT * FROM `purses`");
        while ($purse = $sql->fetch(PDO::FETCH_ASSOC)) {
            # заносим обновлённые данные в слепок
            $this->purses[$purse['id']] = $purse;
            $this->wallets[$purse['pursename']] = $purse;

            /*
                Синхронизация транзакций
            */
            // временная отметка, которую нужно сохранить
            $savetime = null;
            // временная отметка последнего элемента
            $lasttime = null;

            // запрашиваем новые транзакции
            $time = ($times[$purse['pursename']][3]) ? new DateTime('@'.$times[$purse['pursename']][3]) : null;
            try {
                $wmxml = $this->wmxml->xml3($purse['pursename'], $time);
                echo ' '.$time->format("Ymd H:i:s").' ';
                if ($wmxml['is_error']) {
                    throw new Exception($wmxml['error_message'], $wmxml['error_code']);
                }
                $list = $wmxml['data'];
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }

            foreach ($list as $element) {
                // в транзакциях: следим за измением операций по протекции
                if (
                    $element['opertype'] == WMXml::OPERTYPE_PROTECTION
                    &&
                    empty($savetime)
                ) {
                    // если это не первая итерация, то берём временной отсчёт с прошлого элемента
                    $savetime = empty($lasttime) ? $element['datecrt']->getTimestamp() : $lasttime;
                }

                // время последнего элемента
                $lasttime = $element['datecrt']->getTimestamp();

                // такая транзакция уже есть в базе и её статус не изменился
                if (
                    isset($cache['transactions'][$purse['pursename']][$element['id']])
                    &&
                    $cache['transactions'][$purse['pursename']][$element['id']]['opertype'] == $element['opertype']
                ) {
                    continue;
                }

                // такая транзакция уже есть в базе, но её статус изменился
                if (
                    isset($cache['transactions'][$purse['pursename']][$element['id']])
                    &&
                    $cache['transactions'][$purse['pursename']][$element['id']]['opertype'] != $element['opertype']
                ) {
                    // скрываем событие
                    $prepare = $this->pdo->prepare("
                        UPDATE `events` SET
                            `is_hidden` = '1'
                        WHERE
                            `id` = :id
                    ");
                    $bind = [
                        "id"        => $element['id'],
                    ];
                    $prepare->execute($bind);

                    // обновляем саму транзакцию
                    $prepare = $this->pdo->prepare("
                        UPDATE `transactions` SET
                            `dateupd` = :dateupd,
                            `opertype` = :opertype
                        WHERE
                            `id` = :id
                    ");
                    $bind = [
                        "id"        => $element['id'],
                        "dateupd"   => $element['dateupd']->getTimestamp(),
                        "opertype"  => $element['opertype'],
                    ];
                    $prepare->execute($bind);

                    continue;
                }

                // получается, что это новая транзакция - заносим её
                $insert = $this->pdo->prepare("
                    INSERT INTO `transactions` (
                        `id`,
                        `pursesrc`,
                        `pursedest`,
                        `type`,
                        `purse`,
                        `corrpurse`,
                        `amount`,
                        `comiss`,
                        `opertype`,
                        `wminvid`,
                        `orderid`,
                        `tranid`,
                        `period`,
                        `description`,
                        `datecrt`,
                        `dateupd`,
                        `corrwm`,
                        `rest`
                    ) VALUES (
                        :id,
                        :pursesrc,
                        :pursedest,
                        :type,
                        :purse,
                        :corrpurse,
                        :amount,
                        :comiss,
                        :opertype,
                        :wminvid,
                        :orderid,
                        :tranid,
                        :period,
                        :desc,
                        :datecrt,
                        :dateupd,
                        :corrwm,
                        :rest
                    )
                ");
                $bind = [
                    "id"        => $element['id'],
                    "pursesrc"  => $element['pursesrc'],
                    "pursedest" => $element['pursedest'],
                    "type"      => $element['type'],
                    "purse"     => $purse['pursename'],
                    "corrpurse" => $element['corrpurse'],
                    "amount"    => $element['amount'],
                    "comiss"    => $element['comiss'],
                    "opertype"  => $element['opertype'],
                    "wminvid"   => $element['wminvid'],
                    "orderid"   => $element['orderid'],
                    "tranid"    => $element['tranid'],
                    "period"    => $element['period'],
                    "desc"      => $element['desc'],
                    "datecrt"   => $element['datecrt']->getTimestamp(),
                    "dateupd"   => $element['dateupd']->getTimestamp(),
                    "corrwm"    => $element['corrwm'],
                    "rest"      => $element['rest'],
                ];
                $insert->execute($bind);

                // если это приходная операция - создаём событие
                if ($element['type'] == WMXml::TRANSAC_IN) {
                    $type = ($element['opertype'] == WMXml::OPERTYPE_PROTECTION) ? self::EVENT_PROTECTION : self::EVENT_TRANSFER;
                    $this->addEvent($element['id'], $element['datecrt']->getTimestamp(), $element['period'], $element['desc'], $type, $element['amount'], $element['pursesrc']);
                }
            }

            // если не нашли за чем следить, то дело за последней итерацией
            if (empty($savetime) && !empty($lasttime)) {
                $savetime = $lasttime;
            }
            // вообще не было итераций
            else if (empty($savetime) && empty($lasttime)) {
                $savetime = (new DateTime)->getTimestamp();
            }

            // сохраняем временную отметку, если она изменилась
            if ($savetime != $times[$purse['pursename']][3]) {
                $prepare = $this->pdo->prepare("
                    UPDATE `purses_times` SET
                        `time` = :time
                    WHERE
                        `pursename` = :pursename
                            AND
                        `xml_id` = '3'
                ");
                $prepare->bindValue(":time", $savetime);
                $prepare->bindValue(":pursename", $purse['pursename']);
                $prepare->execute();
            }

            /*
                синхронизируем выписанные счета
            */
            // временная отметка, которую нужно сохранить
            $savetime = null;
            // временная отметка последнего элемента
            $lasttime = null;

            // запрашиваем новые счета
            $time = ($times[$purse['pursename']][4]) ? new DateTime('@'.$times[$purse['pursename']][4]) : null;
            try {
                $wmxml = $this->wmxml->xml4($purse['pursename'], $time);
                if ($wmxml['is_error']) {
                    throw new Exception($wmxml['error_message'], $wmxml['error_code']);
                }
                $list = $wmxml['data'];
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }

            foreach ($list as $element) {
                // в выписанных счетах: следим за неоплаченными счетами
                if (
                    $element['state'] == WMXml::STATE_NOPAY
                    &&
                    ($element['datecrt']->getTimestamp() + $element['expiration'] * 24 * 60 * 60) > (new DateTime)->getTimestamp()
                    &&
                    empty($savetime)
                ) {
                    // если это не первая итерация, то берём временной отсчёт с прошлого элемента
                    $savetime = empty($lasttime) ? $element['datecrt']->getTimestamp() : $lasttime;
                }

                // время последнего элемента
                $lasttime = $element['datecrt']->getTimestamp();

                // такой счёт уже есть в базе и его статус не изменился
                if (
                    isset($cache['outvoices'][$purse['pursename']][$element['id']])
                    &&
                    $cache['outvoices'][$purse['pursename']][$element['id']]['state'] == $element['state']
                ) {
                    continue;
                }

                // такой счёт уже есть в базе, но его статус изменился
                if (
                    isset($cache['outvoices'][$purse['pursename']][$element['id']])
                    &&
                    $cache['outvoices'][$purse['pursename']][$element['id']]['state'] != $element['state']
                ) {
                    // обновляем сам счёт
                    $prepare = $this->pdo->prepare("
                        UPDATE `outvoices` SET
                            `dateupd` = :dateupd,
                            `state` = :state,
                            `wmtranid` = :wmtranid
                        WHERE
                            `id` = :id
                    ");
                    $prepare->bindValue(":id", $element['id']);
                    $prepare->bindValue(":dateupd", $element['dateupd']->getTimestamp());
                    $prepare->bindValue(":state", $element['state']);
                    $prepare->bindValue(":wmtranid", $element['wmtranid']);
                    $prepare->execute();

                    continue;
                }

                // получается, что это новый счёт - заносим его
                $insert = $this->pdo->prepare("
                    INSERT INTO `outvoices` (
                        `id`,
                        `orderid`,
                        `storepurse`,
                        `customerwmid`,
                        `customerpurse`,
                        `amount`,
                        `datecrt`,
                        `dateupd`,
                        `state`,
                        `address`,
                        `description`,
                        `period`,
                        `expiration`,
                        `wmtranid`
                    ) VALUES (
                        :id,
                        :orderid,
                        :storepurse,
                        :customerwmid,
                        :customerpurse,
                        :amount,
                        :datecrt,
                        :dateupd,
                        :state,
                        :address,
                        :desc,
                        :period,
                        :expiration,
                        :wmtranid
                    )
                ");
                $bind = [
                    "id"            => $element['id'],
                    "orderid"       => $element['orderid'],
                    "storepurse"    => $element['storepurse'],
                    "customerwmid"  => $element['customerwmid'],
                    "customerpurse" => $element['customerpurse'],
                    "amount"        => $element['amount'],
                    "datecrt"       => $element['datecrt']->getTimestamp(),
                    "dateupd"       => $element['dateupd']->getTimestamp(),
                    "state"         => $element['state'],
                    "address"       => $element['address'],
                    "desc"          => $element['desc'],
                    "period"        => $element['period'],
                    "expiration"    => $element['expiration'],
                    "wmtranid"      => $element['wmtranid'],
                ];
                $insert->execute($bind);
            }

            // если не нашли за чем следить, то дело за последней итерацией
            if (empty($savetime) && !empty($lasttime)) {
                $savetime = $lasttime;
            }
            // вообще не было итераций
            else if (empty($savetime) && empty($lasttime)) {
                $savetime = (new DateTime)->getTimestamp();
            }

            // сохраняем временную отметку, если она изменилась
            if ($savetime != $times[$purse['pursename']][4]) {
                $prepare = $this->pdo->prepare("
                    UPDATE `purses_times` SET
                        `time` = :time
                    WHERE
                        `pursename` = :pursename
                            AND
                        `xml_id` = 4
                ");
                $prepare->bindValue(":time", $savetime);
                $prepare->bindValue(":pursename", $purse['pursename']);
                $prepare->execute();
            }
        }

        /*
            синхронизируем счета, которые выписали нам
        */
        // временная отметка, которую нужно сохранить
        $savetime = null;
        // временная отметка последнего элемента
        $lasttime = null;

        // вытаскиваем список счетов, которые выписали нам
        $time = ($this->system['xml10time']) ? new DateTime('@'.$this->system['xml10time']) : null;
        try {
            $wmxml = $this->wmxml->xml10(null, 0, $time);
            if ($wmxml['is_error']) {
                throw new Exception($wmxml['error_message'], $wmxml['error_code']);
            }
            $list = $wmxml['data'];
        }
        catch (Exception $e) {
            throw new Exception("WMXml exception: " . $e->getMessage());
        }

        foreach ($list as $element) {
            // в полученных счетах: следим за неоплаченными счетами, которые не истекли по времени
            if (
                $element['state'] == WMXml::STATE_NOPAY
                &&
                ($element['datecrt']->getTimestamp() + $element['expiration'] * 24 * 60 * 60) > (new DateTime)->getTimestamp()
                &&
                empty($savetime)
            ) {
                // если это не первая итерация, то берём временной отсчёт с прошлого элемента
                $savetime = empty($lasttime) ? $element['datecrt']->getTimestamp() : $lasttime;
            }

            // время последнего элемента
            $lasttime = $element['datecrt']->getTimestamp();

            // такой счёт уже есть в базе и его статус не изменился
            if (
                isset($cache['invoices'][$element['id']])
                &&
                $cache['invoices'][$element['id']]['state'] == $element['state']
            ) {
                continue;
            }

            // такой счёт уже есть в базе, но его статус изменился
            if (
                isset($cache['invoices'][$element['id']])
                &&
                $cache['invoices'][$element['id']]['state'] != $element['state']
            ) {
                // обновляем сам счёт
                $prepare = $this->pdo->prepare("
                    UPDATE `invoices` SET
                        `dateupd` = :dateupd,
                        `state` = :state,
                        `wmtranid` = :wmtranid
                    WHERE
                        `id` = :id
                ");
                $prepare->bindValue(":id", $element['id']);
                $prepare->bindValue(":dateupd", $element['dateupd']->getTimestamp());
                $prepare->bindValue(":state", $element['state']);
                $prepare->bindValue(":wmtranid", $element['wmtranid']);
                $prepare->execute();

                // скрываем событие
                $prepare = $this->pdo->prepare("
                    UPDATE `events` SET
                        `is_hidden` = 1
                    WHERE
                        `id` = :id
                ");
                $prepare->bindValue(":id", $element['id']);
                $prepare->execute();

                continue;
            }

            // получается, что это новый счёт - заносим его
            $prepare = $this->pdo->prepare("
                INSERT INTO `invoices` (
                    `id`,
                    `orderid`,
                    `storewmid`,
                    `storepurse`,
                    `amount`,
                    `datecrt`,
                    `dateupd`,
                    `state`,
                    `address`,
                    `description`,
                    `period`,
                    `expiration`,
                    `wmtranid`
                ) VALUES (
                    :id,
                    :orderid,
                    :storewmid,
                    :storepurse,
                    :amount,
                    :datecrt,
                    :dateupd,
                    :state,
                    :address,
                    :desc,
                    :period,
                    :expiration,
                    :wmtranid
                )
            ");
            $bind = [
                "id"         => $element['id'],
                "orderid"    => $element['orderid'],
                "storewmid"  => $element['storewmid'],
                "storepurse" => $element['storepurse'],
                "amount"     => $element['amount'],
                "datecrt"    => $element['datecrt']->getTimestamp(),
                "dateupd"    => $element['dateupd']->getTimestamp(),
                "state"      => $element['state'],
                "address"    => $element['address'],
                "desc"       => $element['desc'],
                "period"     => $element['period'],
                "expiration" => $element['expiration'],
                "wmtranid"   => $element['wmtranid'],
            ];
            $prepare->execute($bind);

            // и создаём событие, если счёт не оплаченный и время его действия не истекло
            if (
                $element['state'] == WMXml::STATE_NOPAY
                &&
                ($element['datecrt']->getTimestamp() + $element['expiration'] * 24 * 60 * 60) > time()
            ) {
                $this->addEvent($element['id'], $element['datecrt']->getTimestamp(), $element['expiration'], $element['desc'], self::EVENT_INVOICE, $element['amount'], $element['storepurse']);
            }
        }

        // если не нашли за чем следить, то дело за последней итерацией
        if (empty($savetime) && !empty($lasttime)) {
            $savetime = $lasttime;
        }
        // вообще не было итераций
        else if (empty($savetime) && empty($lasttime)) {
            $savetime = (new DateTime)->getTimestamp();
        }

        // сохраняем временную отметку, если она изменилась
        if ($savetime != $this->system['xml10time']) {
            $prepare = $this->pdo->prepare("
                UPDATE `system` SET
                    `value` = :time
                WHERE
                    `name` = 'xml10time'
            ");
            $prepare->bindValue(":time", $savetime);
            $prepare->execute();

            $this->system['xml10time'] = $savetime;
        }
    }

    /**
     * initCommand(): первая инициализация состояния базы
     */
    public function initCommand()
    {
        // подготавливаем таблицы к массовому внесению данных
        $this->pdo->exec("DELETE FROM `bl`");
        $this->pdo->exec("DELETE FROM `events`");
        $this->pdo->exec("DELETE FROM `invoices`");
        $this->pdo->exec("DELETE FROM `outvoices`");
        $this->pdo->exec("DELETE FROM `purses`");
        $this->pdo->exec("DELETE FROM `purses_times`");
        $this->pdo->exec("DELETE FROM `tl`");
        $this->pdo->exec("DELETE FROM `transactions`");
        $this->pdo->exec("DELETE FROM `userinfo`");
        $this->pdo->exec("DELETE FROM `system`");

        $insert = $this->pdo->prepare("
            INSERT INTO `system` (
                `name`,
                `value`
            ) VALUES (
                :name,
                :value
            )
        ");

        $insert->bindValue(":name", "is_syncpurses");
        $insert->bindValue(":value", "0");
        $insert->execute();

        $insert->bindValue(":name", "xml10time");
        $insert->bindValue(":value", "0");
        $insert->execute();

        // собираем информацию о состоянии кошельков
        try {
            $purses = $this->wmxml->xml9();
        }
        catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        foreach ($purses as $purse) {
            $sql = $this->pdo->prepare("
                INSERT INTO `purses` (
                    `pursename`,
                    `amount`,
                    `description`,
                    `amount_last`
                )
                VALUES (
                    :pursename,
                    :amount,
                    :desc,
                    :amount_last
                )
            ");
            $sql->bindValue(":pursename", $purse['pursename']);
            $sql->bindValue(":amount", $purse['amount']);
            $sql->bindValue(":desc", $purse['desc']);
            $sql->bindValue(":amount_last", $purse['amount']);
            $sql->execute();

            // создаём структуру временных слепков
            $sql = $this->pdo->prepare("
                INSERT INTO `purses_times` (
                    `pursename`,
                    `xml_id`
                )
                VALUES (
                    :pursename,
                    3
                )
            ");
            $sql->bindValue(":pursename", $purse['pursename']);
            $sql->execute();
            $sql = $this->pdo->prepare("
                INSERT INTO `purses_times` (
                    `pursename`,
                    `xml_id`
                )
                VALUES (
                    :pursename,
                    4
                )
            ");
            $sql->bindValue(":pursename", $purse['pursename']);
            $sql->execute();
        }

        // собираем информацию о самом пользователе
        $passport = $this->wmxml->xml11();
        $userinfo = [
            'nickname'    => $passport['userinfo']['nickname'],
            'fname'       => $passport['userinfo']['fname'],
            'iname'       => $passport['userinfo']['iname'],
            'oname'       => $passport['userinfo']['oname'],
            'bdate'       => $passport['userinfo']['bdate']->format("Y-m-d"),
            'phone'       => $passport['userinfo']['phone'],
            'email'       => $passport['userinfo']['email'],
            'web'         => $passport['userinfo']['web'],
            'icq'         => $passport['userinfo']['icq'],
            'country'     => $passport['userinfo']['country'],
            'city'        => $passport['userinfo']['city'],
            'region'      => $passport['userinfo']['region'],
            'zipcode'     => $passport['userinfo']['zipcode'],
            'adres'       => $passport['userinfo']['adres'],
            'pnomer'      => $passport['userinfo']['pnomer'],
            'pdate'       => $passport['userinfo']['pdate']->format("Y-m-d"),
            'pcountry'    => $passport['userinfo']['pcountry'],
            'pcity'       => $passport['userinfo']['pcity'],
            'pcitid'      => $passport['userinfo']['pcitid'],
            'pbywhom'     => $passport['userinfo']['pbywhom'],
            'tid'         => $passport['attestat']['tid'],
            'datecrt'     => $passport['attestat']['datecrt']->format("Y-m-d"),
            'dateupd'     => $passport['attestat']['dateupd']->format("Y-m-d"),
            'regnickname' => $passport['attestat']['regnickname'],
            'regwmid'     => $passport['attestat']['regwmid'],
        ];

        foreach ($userinfo as $name => $value) {
            // сохраняем для себя
            $this->userinfo[$name] = $value;

            $sql = $this->pdo->prepare("
                INSERT INTO `userinfo` (
                    `name`,
                    `value`
                )
                VALUES (
                    :name,
                    :value
                )
            ");
            $sql->bindValue(":name", $name);
            $sql->bindValue(":value", $value);
            $sql->execute();
        }

        // говорим, что успешно произвели иницилизацию
        $this->system['is_syncpurses'] = "1";
        $this->pdo->exec("
            UPDATE `system` SET
                `value` = 1
            WHERE
                `name` = 'is_syncpurses'
        ");
    }

    /**
     * Оплата счёта
     *
     * @param  integer $wminvid номер счета (в системе WebMoney), по которому выполняется перевод
     * @param  string $at_purse номер кошелька с которого выполняется оплата счёта
     * @return array
     * @throws Exception
     */
    public function payCommand($wminvid, $at_purse)
    {
        // откапываем сокращения кошельков
        $at_purse = $this->shortPurse($at_purse);

        // по wminvid вытаскиваем счёт
        $invoice = $this->pdo->query("SELECT * FROM `invoices` WHERE `id` = '" . $wminvid . "'")->fetch(PDO::FETCH_ASSOC);

        // не указан кошелёк - ищем подходящий
        if (empty($at_purse)) {
            // пробегаемся по кошелькам: ищем кошелёк такого же типа и с нужной суммой
            foreach ($this->purses as $purses) {
                if (
                    $invoice['storepurse'] != $purses['pursename']
                    &&
                    self::isEquals($invoice['storepurse'], $purses['pursename'])
                    &&
                    $purses['amount'] >= $invoice['amount']
                ) {
                    $at_purse = $purses['pursename'];
                    break;
                }
            }
        }

        // проверяем на ошибки
        $data = null;
        $is_error = false;
        $message = "";

        if (empty($at_purse)) {
            $is_error = true;
            $message = "Не указан кошелёк, с которого будут переводиться средства";
        } else if (empty($invoice)) {
            $is_error = true;
            $message = "Данный счёт не найден";
        }

        if (!$is_error) {
            try {
                $wmxml = $this->wmxml->xml2(
                    $at_purse,
                    $invoice['storepurse'],
                    $invoice['amount'],
                    $invoice['description'],
                    0,
                    "",
                    $invoice['id']
                );

                if ($wmxml['is_error']) {
                    $is_error = true;
                    $message = $wmxml['error_message'];
                }
                else {
                    $data = $wmxml['data'];
                }
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }
        }

        return [
            'is_error' => $is_error,
            'message'  => $message,
            'data'     => $data,
        ];
    }

    /**
     * Вводим код протекции
     * @param integer $wmtranid уникальный номер платежа в системе учета WebMoney
     * @param $pcode код протекции сделки
     * @return array
     * @throws Exception
     */
    public function protectCommand($wmtranid, $pcode)
    {
        // по wmtranid вытаскиваем транзакцию
        $transac = $this->pdo->query("SELECT * FROM `transactions` WHERE `id` = '" . $wmtranid . "'")->fetch(PDO::FETCH_ASSOC);

        // проверяем на ошибки
        $data = null;
        $is_error = false;
        $message = "";

        if ($transac['opertype'] != WMXml::OPERTYPE_PROTECTION) {
            $is_error = true;
            $message = "Транзация не по протекции";
        } else if (empty($transac)) {
            $is_error = true;
            $message = "Данная тарназкция не найдена";
        }

        if (!$is_error) {
            try {
                $wmxml = $this->wmxml->xml5(
                    $wmtranid,
                    $pcode
                );

                if ($wmxml['is_error']) {
                    $is_error = true;
                    $message = $wmxml['error_message'];
                }
                else {
                    $data = $wmxml['data'];
                }
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }
        }

        return [
            'is_error' => $is_error,
            'message'  => $message,
            'data'     => $data,
        ];
    }

    /**
     * sendCommand(): отправляем деньги
     * @param  string $at_purse из кошелька
     * @param  string $to_purse на кошелёк
     * @param  double $amount сумма перевода
     * @param  string $desc описание перевода
     * @param  integer $protect_period срок протекции
     * @param  integer $protect_code код протекции
     * @return array
     */
    public function sendCommand($at_purse, $to_purse, $amount, $desc, $protect_period = null, $protect_code = null)
    {
        // откапываем сокращения кошельков
        $at_purse = $this->shortPurse($at_purse);
        $to_purse = $this->shortPurse($to_purse);

        // если не указана сумма и кошелёк получателя тоже наш, то переводим всю сумма, которая есть на кошельке
        if (empty($amount) && !empty($to_purse) && $this->wallets[$to_purse]) {
            $amount = $this->wallets[$at_purse]['amount'];
        }

        // нет описания? генерируем!
        if (empty($desc)) {
            $desc = "перевод средств с {$at_purse} на {$to_purse}";
        }

        // проверяем на ошибки
        $data = null;
        $is_error = false;
        $message = "";

        if (empty($at_purse)) {
            $is_error = true;
            $message = "Не указан кошелёк, с которого будут переводиться средства";
        }
        if (empty($to_purse)) {
            $is_error = true;
            $message = "Не указан кошелёк, на который будут переводиться средства";
        }
        if (empty($desc)) {
            $is_error = true;
            $message = "Не указано описание перевода";
        }
        if ($amount <= 0) {
            $is_error = true;
            $message = "Сумма перевода должна быть больше нуля";
        }
        if (!self::isEquals($at_purse, $to_purse)) {
            $is_error = true;
            $message = "Не совпадают типы кошельков";
        }
        if (!empty($protect_period) && $protect_period > 120) {
            $is_error = true;
            $message = "Срок протекции не может быть более 120 дней";
        }
        if (!empty($protect_code) && empty($protect_period)) {
            $is_error = true;
            $message = "Не указан период протекции в днях";
        }

        if (!$is_error) {
            try {
                $wmxml = $this->wmxml->xml2(
                    $at_purse,
                    $to_purse,
                    $amount,
                    $desc,
                    $protect_period,
                    $protect_code
                );

                if ($wmxml['is_error']) {
                    $is_error = true;
                    $message = $wmxml['error_message'];
                }
                else {
                    $data = $wmxml['data'];
                }
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }
        }

        return [
            'is_error' => $is_error,
            'message'  => $message,
            'data'     => $data,
        ];
    }

    /**
     * invoiceCommand(): выписывание счёта
     * @param $wmid
     * @param $purse
     * @param $amount
     * @param $desc
     * @return array
     * @throws Exception
     */
    public function invoiceCommand($wmid, $purse, $amount, $desc)
    {
        // откапываем сокращения кошельков
        $purse = $this->shortPurse($purse);

        // проверяем на ошибки
        $data = null;
        $is_error = false;
        $message = "";

        if (empty($purse)) {
            $is_error = true;
            $message = "Не указан кошелёк, на который предполагается оплата";
        }
        if (empty($desc)) {
            $is_error = true;
            $message = "Не указано описание счёта";
        }
        if ($amount <= 0) {
            $is_error = true;
            $message = "Сумма счёта должна быть больше нуля";
        }

        if (!$is_error) {
            try {
                $wmxml = $this->wmxml->xml1(
                    $wmid,
                    $purse,
                    $amount,
                    $desc
                );

                if ($wmxml['is_error']) {
                    $is_error = true;
                    $message = $wmxml['error_message'];
                }
                else {
                    $data = $wmxml['data'];
                }
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }
        }

        return [
            'is_error' => $is_error,
            'message'  => $message,
            'data'     => $data,
        ];
    }

    /**
     * eventsCommand(): возвращает список новых и текущих событий
     * @param  integer $nums если указано - количество возвращаемых событий (старых)
     * @param  boolean $nohide скрыть ли старые события после выборки?
     * @return array
     */
    public function eventsCommand($nums = null, $nohide = false)
    {
        // подчищаем просроченные события
        $sql = $this->pdo->prepare("
            UPDATE `events` SET
                `is_hidden` = 1
            WHERE
                `expiration` <= :time
                AND
                `expiration` != ''
                AND
                `is_hidden` = 0
        ");
        $sql->bindValue(":time", time());
        $sql->execute();

        $events = [];
        if (empty($nums)) {
            $sql = "
                SELECT * FROM `events`
                WHERE
                    `is_hidden` = '0'
                ORDER BY `time` DESC
            ";
        } else {
            $sql = "
                SELECT * FROM `events`
                ORDER BY `time` DESC
                LIMIT 0, " . $nums . "
            ";
        }

        $sql = $this->pdo->query($sql);
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $events[] = $row;
        }

        // скрываем обычные переводы, нас не просили их сохранить
        if (!$nohide) {
            $prepare = $this->pdo->prepare("
                UPDATE `events` SET
                    `is_hidden` = 1
                WHERE
                    `type` = :transfer
            ");
            $prepare->bindValue(":transfer", self::EVENT_TRANSFER);
            $prepare->execute();
        }

        return $events;
    }


    /**
     * passportCommand(): просмотр информации о кошельке или wmid
     * @param  string $search кошелёк или wmid
     * @return array
     */
    public function passportCommand($search)
    {
        // проверяем на ошибки
        $data = null;
        $is_error = false;
        $message = "";

        // у нас кошелёк, получаем WMID
        if (strlen($search) == 13) {
            $data = $this->wmxml->xml8(null, $search);

            if (!$data['purse']['exists']) {
                $is_error = true;
                $message = "Данный кошелёк не существует";
            } else {
                $search = $data['wmid']['wmid'];
            }
        }

        if (!$is_error) {
            try {
                $wmxml = $this->wmxml->xml11($search);

                if ($wmxml['is_error']) {
                    $is_error = true;
                    $message = $wmxml['error_message'];
                }
                else {
                    $data = $wmxml['data'];
                    // собираем BL и TL для каждого WMID, прикреплённого к аттестату
                    foreach ($data['wmids'] as $wmid => $array) {
                        try {
                            $data['wmids'][$wmid]['bl'] = $this->wmxml->getBl($wmid);
                            $data['wmids'][$wmid]['tl'] = $this->wmxml->getTl($wmid);
                        }
                        catch(Exception $e) {}
                    }
                }
            }
            catch (Exception $e) {
                throw new Exception("WMXml exception: " . $e->getMessage());
            }
        }

        return [
            'is_error' => $is_error,
            'message'  => $message,
            'data'     => $data,
        ];
    }


    public function searchHistory($type, array $params = [], array $operands = [], $order = "ASC", $limit = null)
    {
        /*
        id
        pursesrc
        pursedest
        purse
        corrpurse
        type
        amount
        comiss
        opertype
        wminvid
        orderid
        tranid
        period
        desc
        datecrt
        dateupd
        corrwm
        rest
        storepurse
        customerwmid
        customerpurse
        state
        address
        expiration
        wmtranid
        invoices
        storewmid
        */

        // поиск по транзакциям
        if ($type == self::SEARCH_TRANSACTIONS) {

        } // поиск по входящим счетам
        else if ($type == self::SEARCH_INVOICES) {

        } // поиск по исходящим счетам
        else if ($type == self::SEARCH_OUTVOICES) {

        }

        /*
                $sql = "SELECT * FROM `transactions` ";
        if ($purse) {
            $purse = isset($this->purses[(int) $purse]['pursename']) ? $this->purses[(int) $purse]['pursename'] : $purse;
            $sql .= "WHERE `purse` = '".$purse."' ";
        }
        $sql .= "ORDER BY `id` DESC LIMIT 0, 100";
        $sql = $this->pdo->prepare($sql);
        $sql->execute();
        while($transaction = $sql->fetch(PDO::FETCH_ASSOC)) {
            echo $transaction['datecrt'],
            "\t",$transaction['pursesrc'],
            "\t",$transaction['corrpurse'],
            "\t",$transaction['type'],
            "\t",$transaction['amount'],
            "\t",$transaction['comiss'],
            "\t",$transaction['wminvid'],
            "\t",$transaction['rest'],
            "\n";
        }
         */
    }

    /**
     * добавляет в базу новое событие
     * @param integer $id wmtranid или wminvid
     * @param string $time UnixTimeStamp создания
     * @param integer $period срок действия счёта или истечения протекции в днях
     * @param string $desc описание события
     * @param integer $type тип события
     * @param double $amount сумма
     * @param string $purse кошелёк
     */
    private function addEvent($id, $time, $period, $desc, $type, $amount, $purse)
    {
        // вычисляем дату истечение события
        $expiration = $period ? $time + $period * 24 * 60 * 60 : null;

        switch ($type) {
            case self::EVENT_TRANSFER:
                $desc = 'перевод на сумму ' . $amount . ' ' . self::typePurse($purse) . ' [' . $desc . ']';
                break;

            case self::EVENT_PROTECTION:
                $desc = 'перевод на сумму ' . $amount . ' ' . self::typePurse($purse) . ' [' . $desc . ']';
                break;

            case self::EVENT_INVOICE:
                $desc = 'счёт к оплате на сумму ' . $amount . ' ' . self::typePurse($purse) . ' [' . $desc . ']';
                break;
        }

        $prepare = $this->pdo->prepare("
            INSERT INTO `events` (
                `id`,
                `time`,
                `is_hidden`,
                `expiration`,
                `description`,
                `type`
            ) VALUES (
                :id,
                :time,
                0,
                :expiration,
                :desc,
                :type
            )
        ");

        $prepare->bindValue(":id", $id);
        $prepare->bindValue(":time", $time);
        $prepare->bindValue(":expiration", $expiration);
        $prepare->bindValue(":desc", $desc);
        $prepare->bindValue(":type", $type);
        $prepare->execute();
    }

    /**
     * по скоращённому идентификатору возвращает наш кошелёк
     * @param  integer $purse кошельковое сокращение
     * @return string        развёрнутый кошелёк
     */
    private function shortPurse($purse)
    {
        return isset($this->purses[(int)$purse]['pursename']) ? $this->purses[(int)$purse]['pursename'] : strtoupper($purse);
    }

    /**
     * проверка на схожесть типов кошельков
     * @param  string $first первый проверяемый кошелёк
     * @param  string $second второй проверяемый кошелёк
     * @return boolean         схожи ли типы
     */
    public static function isEquals($first, $second)
    {
        return (strtolower(substr($first, 0, 1)) == strtolower(substr($second, 0, 1)));
    }

    /**
     * возвращаем буквенное обозначение кошелька
     * @param  string $purse кошелёк типа Z123456789123, R123456789123,...
     * @param  boolean $symbol возвратить ли в мировом обозначении?
     * @return string          буквенное обозначение кошелька
     */
    public static function typePurse($purse, $symbol = false)
    {
        $types = [
            'G' => 'Au‰',
            'X' => 'BTC‰',
            'E' => 'EUR',
            'Z' => 'USD',
            'R' => 'RUR',
            'U' => 'UAH',
            'Y' => 'UZS',
            'B' => 'BYR',
            'C' => 'A/P',
            'D' => 'A/R',
        ];

        $letter = strtoupper(substr($purse, 0, 1));

        // возвращаем обозначение типа USD, RUR,...
        if ($symbol) {
            return $types[$letter];
        }

        // возвращаем обозначение типа WMZ, WMR,...
        return "WM" . $letter;
    }
}