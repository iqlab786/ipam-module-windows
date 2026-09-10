<?php
declare(strict_types = 1);

use Modules\IPAMPro\Includes\IpamPro\CsvExporter;
use Modules\IPAMPro\Includes\IpamPro\Repository;
use Modules\IPAMPro\Includes\IpamPro\ZabbixSync;

require_once __DIR__.'/../includes/IpamPro/Cidr.php';
require_once __DIR__.'/../includes/IpamPro/Repository.php';
require_once __DIR__.'/../includes/IpamPro/ZabbixSync.php';
require_once __DIR__.'/../includes/IpamPro/CsvExporter.php';

class CControllerIpamPro extends CController {
	private Repository $repository;
	private array $messages = [];

	protected function init(): void {
		$this->disableCsrfValidation();
		$this->repository = new Repository();
	}

	protected function checkInput(): bool {
		$fields = [
			'tab'         => 'string',
			'task'        => 'string',
			'search'      => 'string',
			'subnetid'    => 'int32',
			'ipid'        => 'int32',
			'vlanid'      => 'int32',
			'subnet_cidr' => 'string',
			'status'      => 'string',
			'site'        => 'string',
			'description' => 'string',
			'hostname'    => 'string',
			'mac'         => 'string',
			'owner'       => 'string',
			'vlan'        => 'string',
			'reserved_by' => 'string',
			'device'      => 'string',
			'purpose'     => 'string',
			'notes'       => 'string',
			'vlan_number' => 'int32',
			'name'        => 'string',
			'report'      => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return true;
	}

	protected function doAction(): void {
		// ── Database setup check ─────────────────────────────────────────────
		if (!$this->repository->tablesExist()) {
			$data = [
				'title'          => 'IPAM Pro – Setup Required',
				'tab'            => 'dashboard',
				'search'         => '',
				'is_admin'       => $this->isAdmin(),
				'sid'            => CWebUser::$data['sessionid'] ?? '',
				'messages'       => [],
				'setup_required' => true,
				'dashboard'      => [
					'totals'       => ['subnets'=>0,'ips'=>0,'used'=>0,'free'=>0,'reserved'=>0,'utilization'=>0],
					'top_subnets'  => [],
					'recent_scans' => []
				],
				'subnets'        => [],
				'vlans'          => [],
				'selected_subnet'=> null,
				'queue_status'   => null,
				'ips'            => [],
				'scans'          => []
			];

			$response = new CControllerResponseData($data);
			$response->setTitle('IPAM');
			$this->setResponse($response);
			return;
		}
		// ─────────────────────────────────────────────────────────────────────

		$task     = (string) $this->getInput('task', '');
		$tab      = (string) $this->getInput('tab', 'dashboard');
		$search   = trim((string) $this->getInput('search', ''));
		$is_admin = $this->isAdmin();

		try {
			if ($task !== '') {
				if ($task !== 'export' && !$is_admin) {
					throw new \RuntimeException('IPAM Pro is read-only for non-admin Zabbix users.');
				}

				$this->handleTask($task);

				if ($task !== 'export') {
					$this->redirect($tab);
					return;
				}
			}
		} catch (\Throwable $exception) {
			$this->messages[] = ['type' => 'error', 'text' => $exception->getMessage()];
		}

		$subnetid = (int) $this->getInput('subnetid', 0);

		try { $dashboard = $this->repository->dashboard(); }
		catch (\Throwable $e) { $dashboard = ['totals' => ['subnets'=>0,'ips'=>0,'used'=>0,'free'=>0,'reserved'=>0,'utilization'=>0], 'top_subnets'=>[], 'recent_scans'=>[]]; }

		try { $subnets = $this->repository->subnets($search, 100); }
		catch (\Throwable $e) { $subnets = []; }

		try { $vlans = $this->repository->vlans(); }
		catch (\Throwable $e) { $vlans = []; }

		try { $selected_subnet = $subnetid > 0 ? $this->repository->subnet($subnetid) : null; }
		catch (\Throwable $e) { $selected_subnet = null; }

		try { $queue_status = $subnetid > 0 ? $this->repository->latestQueueStatus($subnetid) : null; }
		catch (\Throwable $e) { $queue_status = null; }

		try { $ips = $this->repository->ips($subnetid, $search, 2048); }
		catch (\Throwable $e) { $ips = []; }

		try { $scans = $this->repository->recentScans(30); }
		catch (\Throwable $e) { $scans = []; }

		$data = [
			'title'           => 'IPAM Pro',
			'tab'             => $tab,
			'search'          => $search,
			'is_admin'        => $is_admin,
			'sid'             => CWebUser::$data['sessionid'] ?? '',
			'messages'        => $this->messages,
			'setup_required'  => false,
			'dashboard'       => $dashboard,
			'subnets'         => $subnets,
			'vlans'           => $vlans,
			'selected_subnet' => $selected_subnet,
			'queue_status'    => $queue_status,
			'ips'             => $ips,
			'scans'           => $scans
		];

		$response = new CControllerResponseData($data);
		$response->setTitle('IPAM');
		$this->setResponse($response);
	}

	private function handleTask(string $task): void {
		switch ($task) {
			case 'save_subnet':
				$this->repository->saveSubnet($this->request());
				$this->messages[] = ['type' => 'success', 'text' => 'Subnet saved and host addresses generated.'];
				break;

			case 'delete_subnet':
				$this->repository->deleteSubnet((int) $this->getInput('subnetid'));
				break;

			case 'save_ip':
				$this->repository->saveIp($this->request());
				break;

			case 'reserve_ip':
				$this->repository->reserveIp($this->request());
				break;

			case 'save_vlan':
				$this->repository->saveVlan($this->request());
				break;

			case 'delete_vlan':
				$this->repository->deleteVlan((int) $this->getInput('vlanid'));
				break;

			case 'scan':
				$this->repository->queueScan((int) $this->getInput('subnetid'));
				$this->messages[] = ['type' => 'success', 'text' => 'Scan queued. The scanner container will pick it up within a few seconds — refresh this page to see results.'];
				break;

			case 'sync_zabbix':
				(new ZabbixSync())->sync();
				break;

			case 'export':
				$this->export();
				break;
		}
	}

	private function request(): array {
		$keys = [
			'subnetid', 'ipid', 'vlanid', 'subnet_cidr', 'site', 'description', 'status',
			'hostname', 'mac', 'owner', 'vlan', 'reserved_by', 'device', 'purpose', 'notes',
			'vlan_number', 'name'
		];
		$data = [];
		foreach ($keys as $key) {
			$data[$key] = $this->getInput($key, null);
		}

		return $data;
	}

	private function export(): void {
		$report = (string) $this->getInput('report', 'utilization');
		$rows   = [];

		if ($report === 'free') {
			$rows = array_values(array_filter($this->repository->ips(0, '', 70000), static fn($ip) => $ip['status'] === 'free'));
		} elseif ($report === 'reserved') {
			$rows = array_values(array_filter($this->repository->ips(0, '', 70000), static fn($ip) => $ip['status'] === 'reserved'));
		} else {
			$rows = $this->repository->subnets('', 10000, 'utilization_desc');
		}

		(new CsvExporter())->stream('ipam-pro-'.$report.'-report.csv', $rows);
		exit;
	}

	private function isAdmin(): bool {
		$user_type  = (int) (CWebUser::$data['type'] ?? 0);
		$admin_type = defined('USER_TYPE_ZABBIX_ADMIN') ? USER_TYPE_ZABBIX_ADMIN : 2;

		return $user_type >= $admin_type;
	}

	/**
	 * Redirect after a write task. Supports both old Zabbix (string URL) and
	 * new Zabbix (CUrl object) signatures of CControllerResponseRedirect.
	 */
	private function redirect(string $tab): void {
		$subnetid = (int) $this->getInput('subnetid', 0);

		// Build CUrl if the class supports it (Zabbix 6.2+), else plain string.
		if (class_exists('CUrl')) {
			$url = (new CUrl('zabbix.php'))
				->setArgument('action', 'ipampro.view')
				->setArgument('tab', $tab);

			if ($subnetid > 0) {
				$url->setArgument('subnetid', $subnetid);
			}

			$this->setResponse(new CControllerResponseRedirect($url));
		} else {
			$plain = 'zabbix.php?action=ipampro.view&tab='.rawurlencode($tab);
			if ($subnetid > 0) {
				$plain .= '&subnetid='.$subnetid;
			}
			$this->setResponse(new CControllerResponseRedirect($plain));
		}
	}
}
