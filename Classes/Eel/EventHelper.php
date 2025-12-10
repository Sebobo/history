<?php

declare(strict_types=1);

namespace AE\History\Eel;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\Html\HtmlArrayRenderer;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\EventLog\Domain\Model\Event;
use Neos\Neos\Service\UserService;

class EventHelper implements ProtectedContextAwareInterface
{

    /**
     * @var PersistenceManagerInterface
     */
    #[Flow\Inject]
    protected $persistenceManager;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected DomainUserService $domainUserService;

    #[Flow\Inject]
    protected NodeTypeManager $nodeTypeManager;

    public function identifier(?Event $event): string
    {
        return (string)$this->persistenceManager->getIdentifierByObject($event);
    }

    public function userName(?Event $event, $format = 'fullName'): string
    {
        if (!$event || !$event->getAccountIdentifier()) {
            return 'n/a';
        }
        $username = $event->getAccountIdentifier();
        $requestedUser = $this->domainUserService->getUser($username);
        if ($requestedUser === null || $requestedUser->getName() === null) {
            return $username;
        }

        return match ($format) {
            'initials' => mb_substr(
                    preg_replace('/[^[:alnum:][:space:]]/u', '', $requestedUser->getName()->getFirstName()),
                    0,
                    1
                ) . mb_substr(
                    preg_replace('/[^[:alnum:][:space:]]/u', '', $requestedUser->getName()->getLastName()),
                    0,
                    1
                ),
            'fullFirstName' => trim(
                $requestedUser->getName()->getFirstName() . ' ' . ltrim(
                    mb_substr($requestedUser->getName()->getLastName(), 0, 1) . '.',
                    '.'
                )
            ),
            default => $requestedUser->getName()->getFullName(),
        };
    }

    /**
     * @param array<Event> $events
     * @return array<string, array<Event>>
     */
    public function groupByTime(array $events): array
    {
        $groupedEvents = [];
        foreach ($events as $event) {
            $timestamp = $event->getTimestamp()->format('U');
            if (!isset($groupedEvents[$timestamp])) {
                $groupedEvents[$timestamp] = [$event];
            } else {
                $groupedEvents[$timestamp][] = $event;
            }
        }
        return $groupedEvents;
    }

    /**
     * @return array<string, array{diff: string, propertyLabel: string, changed: string, original: string, type: string}>
     * @throws NodeTypeNotFoundException
     */
    public function changes(?Event $event): array
    {
        $data = $event?->getData();
        if (!$data) {
            return [];
        }
        $old = $data['old'] ?? null;
        $new = $data['new'] ?? [];
        $nodeType = $this->nodeTypeManager->getNodeType($data['nodeType']);
        $changeNodePropertiesDefaults = $nodeType->getDefaultValuesForProperties();

        $renderer = new HtmlArrayRenderer();
        $changes = [];
        foreach ($new as $propertyName => $changedPropertyValue) {
            if (($old === null && empty($changedPropertyValue))
                || (isset($changeNodePropertiesDefaults[$propertyName])
                    && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName]
                )
            ) {
                continue;
            }

            $originalPropertyValue = ($old === null ? null : $old[$propertyName]);

            if (!is_object($originalPropertyValue) && !is_object($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($originalPropertyValue);
                $changedSlimmedDownContent = $this->renderSlimmedDownContent($changedPropertyValue);

                $diff = new Diff(
                    explode("\n", $originalSlimmedDownContent),
                    explode("\n", $changedSlimmedDownContent),
                    ['context' => 1]
                );
                /** @var array $diffArray */
                $diffArray = $diff->render($renderer);
                $this->postProcessDiffArray($diffArray);
                if ($diffArray !== []) {
                    $changes[$propertyName] = [
                        'diff' => $diffArray,
                        'propertyLabel' => $this->getPropertyLabel($propertyName, $nodeType),
                        'type' => 'text',
                    ];
                }
            } elseif ($originalPropertyValue instanceof ImageInterface
                || $changedPropertyValue instanceof ImageInterface
            ) {
                $changes[$propertyName] = [
                    'changed' => $changedPropertyValue,
                    'original' => $originalPropertyValue,
                    'propertyLabel' => $this->getPropertyLabel($propertyName, $nodeType),
                    'type' => 'image',
                ];
            } elseif ($originalPropertyValue instanceof AssetInterface
                || $changedPropertyValue instanceof AssetInterface
            ) {
                $changes[$propertyName] = [
                    'changed' => $changedPropertyValue,
                    'original' => $originalPropertyValue,
                    'propertyLabel' => $this->getPropertyLabel($propertyName, $nodeType),
                    'type' => 'asset',
                ];
            } elseif ($originalPropertyValue instanceof \DateTimeInterface
                && $changedPropertyValue instanceof \DateTimeInterface
            ) {
                if ($changedPropertyValue->getTimestamp() !== $originalPropertyValue->getTimestamp()) {
                    $changes[$propertyName] = [
                        'changed' => $changedPropertyValue,
                        'original' => $originalPropertyValue,
                        'propertyLabel' => $this->getPropertyLabel($propertyName, $nodeType),
                        'type' => 'datetime',
                    ];
                }
            }
        }
        return $changes;
    }

    /**
     * Tries to determine a label for the specified property
     */
    protected function getPropertyLabel(string $propertyName, NodeType $nodeType): string
    {
        return $nodeType->getProperties()[$propertyName]['ui']['label'] ?? $propertyName;
    }

    /**
     * Renders a slimmed down representation of a property of the given node. The output will be HTML, but does not
     * contain any markup from the original content.
     *
     * Note: It's clear that this method needs to be extracted and moved to a more universal service at some point.
     * However, since we only implemented diff-view support for this particular controller at the moment, it stays
     * here for the time being. Once we start displaying diffs elsewhere, we should refactor the diff rendering part.
     */
    protected function renderSlimmedDownContent(mixed $propertyValue): string
    {
        $content = '';
        if (is_string($propertyValue)) {
            $contentSnippet = str_replace('&nbsp;', ' ', $propertyValue);
            $contentSnippet = preg_replace('/<br[^>]*>/', "\n", $contentSnippet);
            $contentSnippet = preg_replace(['/<[^>]*>/', '/ {2,}/'], ' ', $contentSnippet);
            $content = trim($contentSnippet);
        }
        return $content;
    }

    /**
     * A workaround for some missing functionality in the Diff Renderer:
     *
     * This method will check if content in the given diff array is either completely new or has been completely
     * removed and wraps the respective part in <ins> or <del> tags, because the Diff Renderer currently does not
     * do that in these cases.
     */
    protected function postProcessDiffArray(array &$diffArray): void
    {
        foreach ($diffArray as $index => $blocks) {
            foreach ($blocks as $blockIndex => $block) {
                $baseLines = trim(implode('', $block['base']['lines']), " \t\n\r\0\xC2\xA0");
                $changedLines = trim(implode('', $block['changed']['lines']), " \t\n\r\0\xC2\xA0");
                if ($baseLines === '') {
                    foreach ($block['changed']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['changed']['lines'][$lineIndex] = '<ins>' . $line . '</ins>';
                    }
                }
                if ($changedLines === '') {
                    foreach ($block['base']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['base']['lines'][$lineIndex] = '<del>' . $line . '</del>';
                    }
                }
            }
        }
    }

    /**
     * @param string $methodName
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
