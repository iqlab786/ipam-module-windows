<?php
declare(strict_types = 1);

namespace Modules\IPAMPro;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
	public function init(): void {
		if (method_exists($this, 'setWebAssets')) {
			$this->setWebAssets([
				'css' => ['assets/css/ipampro.css'],
				'js' => ['assets/js/ipampro.js']
			]);
		}

		$this->addMenuItem();
	}

	private function addMenuItem(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Inventory'))
				->getSubmenu()
				->add((new CMenuItem(_('IPAM')))
					->setAction('ipampro.view')
				);
	}
}
