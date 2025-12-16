<?php

declare(strict_types=1);

namespace AE\History\Service;

use AE\History\Domain\Dto\Change;
use AE\History\Domain\Dto\Changes;
use AE\History\Domain\Dto\ChangeType;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\AbstractRenderer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\EventLog\Domain\Model\Event;

class DiffService
{

    protected ?TranslationHelper $translationHelper = null;

    #[Flow\Inject]
    protected NodeTypeManager $nodeTypeManager;

    /**
     */
    public function generateDiffForEvent(
        ?Event $event,
        ?AbstractRenderer $renderer,
    ): Changes {
        $data = $event?->getData();
        if (!$data || !$renderer) {
            return Changes::empty();
        }
        $old = $data['old'] ?? null;
        $new = $data['new'] ?? [];

        try {
            $nodeType = $this->nodeTypeManager->getNodeType($data['nodeType']);
        } catch (NodeTypeNotFoundException) {
            // TODO: Create a change with a warning/error to give feedback that a change could not be rendered
            return Changes::empty();
        }
        $changeNodePropertiesDefaults = $nodeType->getDefaultValuesForProperties();

        $changes = [];
        foreach ($new as $propertyName => $changedPropertyValue) {
            if (($old === null && empty($changedPropertyValue))
                || (isset($changeNodePropertiesDefaults[$propertyName])
                    && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName]
                )
            ) {
                continue;
            }
            $diff = '';
            $originalPropertyValue = ($old === null ? null : $old[$propertyName]);

            if ($changedPropertyValue === $originalPropertyValue) {
                continue;
            }

            $originalType = $this->resolveChangeType($originalPropertyValue);
            $changedType = $this->resolveChangeType($changedPropertyValue);

            $serializedOriginalValue = $this->serializeValue($originalPropertyValue);
            $serializedChangedValue = $this->serializeValue($changedPropertyValue);

            if ($serializedOriginalValue === $serializedChangedValue) {
                continue;
            }

            if (is_string($originalPropertyValue) && is_string($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($serializedOriginalValue);
                $changedSlimmedDownContent = $this->renderSlimmedDownContent($serializedChangedValue);

                $rawDiff = new Diff(
                    explode("\n", $originalSlimmedDownContent),
                    explode("\n", $changedSlimmedDownContent),
                    ['context' => 1]
                );
                $diffArray = $rawDiff->render($renderer);

                if (is_array($diffArray)) {
                    $this->postProcessDiffArray($diffArray);
                }

                if ($diffArray) {
                    $diff = $diffArray;
                }
            }

            $changes[] = new Change(
                $this->getPropertyLabel($propertyName, $nodeType),
                $serializedOriginalValue,
                $serializedChangedValue,
                $originalType,
                $changedType,
                $diff,
            );
        }
        return new Changes($changes);
    }

    /**
     * Returns a usable label/value for the given property for the diff in the UI
     */
    protected function serializeValue(mixed $propertyValue, bool $simple = false): string
    {
        // TODO: Convert node id string to node if possible
        //if (is_string($propertyValue) && preg_match(
        //        NodeIdentifierValidator::PATTERN_MATCH_NODE_IDENTIFIER,
        //        $propertyValue
        //    ) !== 0) {
        //    $propertyValue = $contextNode->getContext()->getNodeByIdentifier($propertyValue);
        //}
        if (is_string($propertyValue)) {
            return $propertyValue;
        }

        if ($propertyValue instanceof AssetInterface) {
            $filename = $propertyValue->getResource()->getFilename();
            if ($simple) {
                return $filename;
            }

            try {
                $uri = $propertyValue->getAssetProxy()?->getThumbnailUri()?->__toString();
            } catch (\Exception) {
                $uri = '';
            }

            try {
                return json_encode([
                    'src' => $uri,
                    'alt' => $filename,
                    'title' => $propertyValue->getTitle() ?: $filename,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            } catch (\JsonException) {
                return '[unserializable value]';
            }
        }

        if ($propertyValue instanceof NodeInterface) {
            return $propertyValue->getLabel() . ' (' . $propertyValue->getIdentifier() . ')';
        }

        if (is_bool($propertyValue)) {
            return $propertyValue ? 'true' : 'false';
        }

        if (is_array($propertyValue)) {
            $propertyValue = array_map(function ($value) {
                return $this->serializeValue($value, true);
            }, $propertyValue);
        }

        try {
            return json_encode($propertyValue, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\JsonException) {
            return '[unserializable value]';
        }
    }

    protected function resolveChangeType($value): ChangeType
    {
        return match (true) {
            is_string($value) => ChangeType::TEXT,
            $value instanceof ImageInterface => ChangeType::IMAGE,
            $value instanceof AssetInterface => ChangeType::ASSET,
            $value instanceof NodeInterface => ChangeType::NODE,
            $value instanceof \DateTime => ChangeType::DATETIME,
            is_bool($value) => ChangeType::BOOLEAN,
            is_array($value) => ChangeType::ARRAY,
            default => ChangeType::OTHER,
        };
    }

    /**
     * Adapted from \Neos\Neos\Controller\Module\Management\WorkspacesController
     */
    protected function getPropertyLabel(string $propertyName, NodeType $nodeType): string
    {
        $properties = $nodeType->getProperties();
        if (isset($properties[$propertyName]['ui']['label'])) {
            return $this->translate($properties[$propertyName]['ui']['label']);
        }
        return $propertyName;
    }

    /**
     * Adapted from \Neos\Neos\Controller\Module\Management\WorkspacesController
     */
    protected function renderSlimmedDownContent($propertyValue, bool $stripTags = false): string
    {
        if (is_string($propertyValue)) {
            // Replace <br> with new lines
            $contentSnippet = preg_replace('/<br[^>]*>/', "\n", $propertyValue);
            if ($stripTags) {
                $contentSnippet = preg_replace('/<[^>]*>/', ' ', $contentSnippet);
            }
            // Replace multiple spaces and &nbsp; with a single space
            $contentSnippet = str_replace('&nbsp;', ' ', $contentSnippet);
            return trim(preg_replace('/ {2,}/', ' ', $contentSnippet));
        }
        return '';
    }

    /**
     * Copied from \Neos\Neos\Controller\Module\Management\WorkspacesController
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

    protected function translate(string $id): string
    {
        if (!$this->translationHelper) {
            $this->translationHelper = new TranslationHelper();
        }
        return $this->translationHelper->translate($id) ?? $id;
    }
}
