<?php

declare(strict_types=1);

namespace OCA\AppVersions\Listener;

use OCA\AppVersions\Db\PatMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes any PATs owned by a deleted user so credentials don't outlive
 * the account that uploaded them.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(private PatMapper $mapper) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$this->mapper->deleteByOwner($event->getUser()->getUID());
	}
}
