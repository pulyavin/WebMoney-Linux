# WebMoney Keeper Classic (WinPro) for Linux (Console Edition)

Консольный WebMoney клиент для Linux-based дистрибутивов.
Расчитан для таких пользователей как я, у которых стоит Linux, но есть ключи от Windows-версии Keeper'а. И чтобы не перегружаться во вторую ось или не ставить виртуалку с Windows. Для работы моего клиента необходимо невозможное:
- *.kwm-файл
- персональный аттестат и выше
- (!) разрешение на использование WebMoney XML API
- (!) доступ с IP, с которого разрешён доступ к API (или отсутствие привязки API к IP)

Что умеет клиент
================================================================================
- принимать и обрабатывать данные, формируя из них события
- переводить средства и бескомиссионно возвращать
- выставлять счета
- оплачивать счета
- завершать переводы с протекцией
- искать по истории транзакций
- отправлять сообщения


Необходимо
================================================================================
* PHP >= 5.3
* PHP extensions:
  * curl
  * PDO
* SQLite or any PDO compatible SQL server





Для себя:
- отказ оплаты счёта (hide event)
- бескомиссионный возврат средств отправителю
- поиск по истории транзакций
- поиск по истории принятых счетов
- поиск по истории выставленных счетов
- отправка сообщения
- модули сделать в виде Composer-пакетов
- прикрутить альтернативную работу через 


вставка сертифика кипер лайта
сумма перевода больше нуля - обновлять при внутренних переводах кошельки
дописать логику для pem-сертификата
как компонент для yii (и вынести в отдельный репозиторий)
Интерфейс X16 — Создание кошелька
Интерфейс X19 — Проверка соответствия персональных данных владельца WM-идентификатора
Интерфейс X20 — Проведение транзакции в merchant.webmoney без ухода с сайта (ресурса, сервиса, приложения) продавца
Интерфейс X21 — Установка по СМС доверия на оплату в пользу продавца
Интерфейс X22 — Получение тикета предварительной регистрации формы запроса платежа в merchant.webmoney
приём данных-параметров в консоли
смещение курсора в консоли

