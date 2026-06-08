<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Listener;

use OCA\AppVersions\Db\PatMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes any PATs owned by a deleted user so credentials don't outlive
 * the account that uploaded them.
 *
 * @psalm-api
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private PatMapper $mapper,
	) {
	}

	/**
	 * On user deletion, sweeps every PAT owned by that uid; see "Admin removal cleans up PATs".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$this->mapper->deleteByOwner($event->getUser()->getUID());
	}
}
