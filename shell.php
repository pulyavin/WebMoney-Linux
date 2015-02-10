<?php
class shell {
	private static $sceleton;
	private $commands;
	private $console;

	private function __construct($commands, $console) {
		$this->commands = $commands;
		$this->console = $console;
	}

	public static function init(commands $commands, console $console) {
		if (!self::$sceleton) {
			self::$sceleton = new self($commands, $console);
		}

		return self::$sceleton;
	}

	public function start() {
		try {
			$this->refreshCommand();

			while(true) {
				$command = $this->console->stdin("Введите команду");
				$this->execute($command);
			}
		}
		catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	private function execute($command) {
		$expolode = explode(" ", $command);
		$command = $expolode[0];
		$params = isset($expolode[1]) ? $expolode[1] : null;

		switch ($command) {
			case '':
				$this->refreshCommand();
				break;

			case 'exit':
				exit;
				break;

			case 'clear':
				$this->console->clear();
				break;

			case 'help':
				$this->helpCommand();
				break;

			case 'history':
				$this->commandHistory($params);
				break;

			case 'send':
				$this->sendCommand();
				break;

			case 'events':
				$this->eventsCommand($params);
				break;

			case 'bill':
				$this->billCommand();
				break;

			case 'pay':
				$this->payCommand();
				break;

			case 'protect':
				$this->protectCommand();
				break;
			
			default:
				echo "Команда не найдена, используйте `help` для справки\n";
				break;
		}
	}

	private function helpCommand() {
		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("[Enter]")
			->style()
			->text(": ")
			->text("обновление статистики")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("events [nums]")
			->style()
			->text(": ")
			->text("просмотреть новые события или вывести nums-последних событий")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("send")
			->style()
			->text(": ")
			->text("совершить перевод")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("bill")
			->style()
			->text(": ")
			->text("выписать счёт")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("pay")
			->style()
			->text(": ")
			->text("оплатить некий счёт")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("protect")
			->style()
			->text(": ")
			->text("завершить перевод с протекцией")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("mail")
			->style()
			->text(": ")
			->text("отправить сообщение")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("clear")
			->style()
			->text(": ")
			->text("очистить экран")
			->stdout(null, true);

		$this->console
			->text("\t")
			->style('green', 'bold')
			->text("history [кошелёк]")
			->style()
			->text(": ")
			->text("выводит историю транзакций")
			->stdout(null, true);
	}

	/**
	 * обновление главного окна
	 */
	private function refreshCommand() {
		$this->console->stdout("Подождите. Обновляю...", true);

		# обновляемся в самом ядре
		$this->commands->refreshCommand();
		# получаем обновлённые данные кошельков
		$purses = $this->commands->pursesGet();
		# получаем обновлённые данные информации о пользователе
		$userinfo = $this->commands->userinfoGet();
		# получаем количество новых событий
		$events = count($this->commands->eventsCommand(null, true));

		# подчищаем консоль для вывода новых данных
		$this->console->clear();

    	# выводим шапку с данными о пользователе
		$this->console
			->text($userinfo['fname'])
			->text(" ")
			->text($userinfo['iname'])
			->text(" ")
			->text($userinfo['oname'])
			->text(" ")
			->text("(".$userinfo['nickname'].")")
			->stdout(null, true);
		$this->console
			->text("BL: ")
			->style(null, 'bold')
			->text($userinfo['bl'])
			->style()
			->text(", ")
			->text("TL: ")
			->style(null, 'bold')
			->text($userinfo['tl'])
			->style()
			->text(", ");

		# смотрим количество новых событий
		if ($events) {
			$this->console
				->style('green', 'bold')
				->text($events)
				->text(" новых событий")
				->style();
		}
		else {
			$this->console
				->text("нет новых событий");
		}

		$this->console
			->text("\n")
			->text(str_repeat("-", 60))
			->stdout(null, true);

		# выводим кошельки
		foreach ($purses as $purse) {
			# расхождение в поступлениях
			$this->console
				->text("[")
				->style(null, 'bold')
				->text($purse['id'])
				->style()
				->text("]")
				->column(6, "left")

				->style(null, 'bold')
				->text($purse['desc'])
				->style()
				->column(20, "left")

				->text($purse['pursename'])
				->column(20, "left");

			if ($purse['amount'] > 0) {
				$this->console
					->style('green', 'bold')
					->text($purse['amount'])
					->style();
			}
			else {
				$this->console
					->text($purse['amount']);
			}

			$last = $purse['amount'] - $purse['amount_last'];
			if ($last > 0) {
				$this->console
					->text(" (")
					->style('green', 'bold')
					->text("+".$last)
					->style()
					->text(")");
			}
			else if ($last < 0) {
				$this->console
					->text(" (")
					->style('red', 'bold')
					->text($last)
					->style()
					->text(")");
			}

			$this->console
				->text(" ")
				->text(commands::typePurse($purse['pursename'], true))
				->stdout(null, true);
		}
	}

	/**
	 * перевод средств
	 */
	private function sendCommand() {
		# получаем входящие данные
		$at_purse	= $this->console->stdin("Из кошелька");
		$to_purse	= $this->console->stdin("На кошелёк");
		$amount		= $this->console->stdin("Сумма перевода");
		$desc		= $this->console->stdin("Описание перевода");

		$protection = $this->console->stdin("С протекцией (y)");
		$protect_period = 0;
		$protect_code = null;

		if ($protection == "y") {
			$protect_period = $this->console->stdin("Срок протекции в днях");
			$protect_code = $this->console->stdin("Код протекции");
		}

		$command = $this->commands->sendCommand(
			$at_purse,
			$to_purse,
			$amount,
			$desc,
			$protect_period,
			$protect_code
		);

		if ($command['is_error']) {
			$this->printError($command['message']);
		}
		else {
			$this->console
				->text("\t")
				->style('green', 'bold')
				->text("Успешно!")
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Был выполнен перевод ")
				->style('green', 'bold')
				->text($command['data']['amount'])
				->text(" ")
				->text(commands::typePurse($command['data']['pursesrc'], true))
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("с кошелька ")
				->style(null, 'bold')
				->text($command['data']['pursesrc'])
				->style()
				->text(" на кошелёк ")
				->style(null, 'bold')
				->text($command['data']['pursedest'])
				->style()
				->text(", комиссия составила ")
				->style(null, 'bold')
				->text($command['data']['comiss'])
				->text(" ")
				->text(commands::typePurse($command['data']['pursesrc'], true))
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Описание: ")
				->style(null, 'bold')
				->text($command['data']['desc'])
				->style()
				->stdout(null, true);
		}
	}

	/**
	 * Выписывает счёт
	 */
	private function billCommand() {
		# получаем входящие данные
		$wmid	= $this->console->stdin("WMID платильщика");
		$purse	= $this->console->stdin("На кошелёк");
		$amount	= $this->console->stdin("Сумма счёта");
		$desc	= $this->console->stdin("Описание счёта");

		$command = $this->commands->invoiceCommand(
			$wmid,
			$purse,
			$amount,
			$desc
		);

		if ($command['is_error']) {
			$this->printError($command['message']);
		}
		else {
			$this->console
				->text("\t")
				->style('green', 'bold')
				->text("Успешно!")
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Был выписан счёт в ")
				->style('green', 'bold')
				->text($command['data']['amount'])
				->text(" ")
				->text(commands::typePurse($command['data']['storepurse'], true))
				->style()
				->text(" на ")
				->style('green', 'bold')
				->text($command['data']['customerwmid'])
				->style()
				->text(" для кошелька ")
				->style('green', 'bold')
				->text($command['data']['storepurse'])
				->style()
				->stdout(null, true);
		}
	}

	/**
	 * Оплачиваем счёт
	 */
	private function payCommand() {
		# получаем входящие данные
		$wminvid	= $this->console->stdin("Номер счёта (wminvid)");
		$at_purse	= $this->console->stdin("Из кошелька");

		$command = $this->commands->payCommand(
			$wminvid,
			$at_purse
		);

		if ($command['is_error']) {
			$this->printError($command['message']);
		}
		else {
			$this->console
				->text("\t")
				->style('green', 'bold')
				->text("Успешно!")
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Был выполнен перевод ")
				->style('green', 'bold')
				->text($command['data']['amount'])
				->text(" ")
				->text(commands::typePurse($command['data']['pursesrc'], true))
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("с кошелька ")
				->style(null, 'bold')
				->text($command['data']['pursesrc'])
				->style()
				->text(" на кошелёк ")
				->style(null, 'bold')
				->text($command['data']['pursedest'])
				->style()
				->text(", комиссия составила ")
				->style(null, 'bold')
				->text($command['data']['comiss'])
				->text(" ")
				->text(commands::typePurse($command['data']['pursesrc'], true))
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Описание: ")
				->style(null, 'bold')
				->text($command['data']['desc'])
				->style()
				->stdout(null, true);
		}
	}

	/**
	 * Вводим код протекции
	 */
	private function protectCommand() {
		# получаем входящие данные
		$wmtranid	= $this->console->stdin("Номер счёта (wmtranid)");
		$pcode	= $this->console->stdin("Код протекции");

		$command = $this->commands->protectCommand(
			$wmtranid,
			$pcode
		);

		if ($command['is_error']) {
			$this->printError($command['message']);
		}
		else {
			$this->console
				->text("\t")
				->style('green', 'bold')
				->text("Успешно!")
				->style()
				->stdout(null, true);
			$this->console
				->text("\t")
				->text("Транзакция успешно закрыта")
				->stdout(null, true);
		}
	}

	/**
	 * выводит события
	 */
	private function eventsCommand($nums = null) {
		$events = $this->commands->eventsCommand($nums);

		if (empty($events)) {
			$this->console
				->text("\t")
				->text("Нет новых событий...")
				->stdout(null, true);
		}

		foreach ($events as $event) {
			$this->console
				->style('white', 'bold', 'green')
				->text($event['id'])
				->style()
				->column(15)
				->text("[");

			if ($event['type'] == commands::EVENT_TRANSFER) {
				$this->console
					->style('green', 'bold')
					->text("перевод")
					->style();
			}
			else if ($event['type'] == commands::EVENT_PROTECTION) {
				$this->console
					->style('yellow', 'bold')
					->text("протекция")
					->style();
			}
			else {
				$this->console
					->style('red', 'bold')
					->text("счёт")
					->style();
			}

			$this->console
				->text("]")
				->column(15)
				->text($event['date'])
				->column(20)
				->text($event['desc'])
				->stdout(null, true);
		}
	}

	/**
	 * декоратор вывода ошибок
	 * @param  string $message текст ошибки
	 */
	private function printError($message) {
		$this->console
			->text("\t")
			->style('red', 'bold')
			->text("Возникла ошибка: ")
			->style()
			->text($message)
			->stdout(null, true);
	}
}
?>