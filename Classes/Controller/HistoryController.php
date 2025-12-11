<?php

declare(strict_types=1);

namespace AE\History\Controller;

use AE\History\Domain\Repository\NodeEventRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\EventLog\Domain\Model\EventsOnDate;
use Neos\Neos\EventLog\Domain\Model\NodeEvent;

/**
 * Controller for the history module of Neos, displaying the timeline of changes.
 */
class HistoryController extends AbstractModuleController
{
    use CreateContentContextTrait;

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected DomainRepository $domainRepository;

    #[Flow\Inject]
    protected NodeEventRepository $nodeEventRepository;

    #[Flow\Inject]
    protected Context $securityContext;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected UserService $userService;

    /**
     * Show event overview.
     */
    public function indexAction(
        int $offset = 0,
        int $limit = 25,
        string $siteIdentifier = null,
        string $nodeIdentifier = null,
        string $accountIdentifier = null,
        string $startDate = null,
        string $endDate = null
    ): void {
        if ($nodeIdentifier === '') {
            $nodeIdentifier = null;
        }

        $numberOfSites = 0;
        // In case a user can only access a single site, but more sites exists
        $this->securityContext->withoutAuthorizationChecks(function () use (&$numberOfSites) {
            $numberOfSites = $this->siteRepository->countAll();
        });
        $sites = $this->siteRepository->findOnline();
        if ($numberOfSites > 1 && $siteIdentifier === null) {
            $domain = $this->domainRepository->findOneByActiveRequest();
            if ($domain !== null) {
                $siteIdentifier = $this->persistenceManager->getIdentifierByObject($domain->getSite());
            }
        }

        /** @var array<string, string> $accounts */
        $accounts = [];
        $accountIdentifiers = $this->nodeEventRepository->findAccountIdentifiers(
            'live',
            $siteIdentifier ?: null,
            $nodeIdentifier ?: null
        );
        foreach ($accountIdentifiers as $identifier) {
            $user = $this->userService->getUser($identifier);
            $accounts[$identifier] = $user ? $user->getName()->getLastName() . ', ' . $user->getName()->getFirstName(
                ) : $identifier;
        }

        // Parse date strings to DateTime objects
        $startDateTime = null;
        $endDateTime = null;
        if ($startDate !== null && $startDate !== '') {
            try {
                $startDateTime = new \DateTime($startDate);
                $startDateTime->setTime(0, 0, 0);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }
        if ($endDate !== null && $endDate !== '') {
            try {
                $endDateTime = new \DateTime($endDate);
                $endDateTime->setTime(23, 59, 59);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }

        /** @var NodeEvent[] $events */
        $events = $this->nodeEventRepository
            ->findRelevantEventsByWorkspace(
                $offset,
                $limit + 1,
                'live',
                $siteIdentifier ?: null,
                $nodeIdentifier,
                $accountIdentifier ?: null,
                $startDateTime,
                $endDateTime
            )
            ->toArray();

        $nextPage = null;
        if (count($events) > $limit) {
            $events = array_slice($events, 0, $limit);

            $nextPage = $this->controllerContext
                ->getUriBuilder()
                ->setCreateAbsoluteUri(true)
                ->uriFor(
                    'Index',
                    [
                        'accountIdentifier' => $accountIdentifier,
                        'nodeIdentifier' => $nodeIdentifier,
                        'offset' => $offset + $limit,
                        'siteIdentifier' => $siteIdentifier,
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                    ]
                );
        }

        /** @var EventsOnDate[] $eventsByDate */
        $eventsByDate = [];
        foreach ($events as $event) {
            if ($event->getChildEvents()->count() === 0) {
                continue;
            }
            $timestamp = $event->getTimestamp();
            $day = $timestamp->format('Y-m-d');
            if (!isset($eventsByDate[$day])) {
                $eventsByDate[$day] = new EventsOnDate($timestamp);
            }

            $eventsOnThisDay = $eventsByDate[$day];
            $eventsOnThisDay->add($event);
        }

        $firstEvent = current($events);
        if ($firstEvent === false) {
            $node = $this->createContentContext('live')->getNodeByIdentifier($nodeIdentifier);
            if ($node !== null) {
                $firstEvent = [
                    'data' => [
                        'documentNodeLabel' => $node->getLabel(),
                        'documentNodeType' => $node->getNodeType()->getName(),
                    ],
                    'node' => $node,
                    'nodeIdentifier' => $nodeIdentifier,
                ];
            }
        }

        $this->view->assignMultiple([
            'accountIdentifier' => $accountIdentifier,
            'eventsByDate' => $eventsByDate,
            'firstEvent' => $firstEvent,
            'nextPage' => $nextPage,
            'nodeIdentifier' => $nodeIdentifier,
            'siteIdentifier' => $siteIdentifier,
            'sites' => $sites,
            'accounts' => $accounts,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Simply sets the Fusion path pattern on the view.
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://AE.History/Private/Fusion');
    }
}
