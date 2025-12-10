<?php

declare(strict_types=1);

namespace AE\History\Eel;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;

class NodeHelper implements ProtectedContextAwareInterface
{

    #[Flow\Inject]
    protected NodeTypeManager $nodeTypeManager;

    public function icon(?NodeInterface $node) : string
    {
        if ($node) {
            try {
                return $this->nodeTypeManager
                    ->getNodeType($node->getNodeType()->getName())
                    ->getConfiguration('ui.icon');
            } catch (NodeTypeNotFoundException) {
            }
        }
        return 'question-circle';
    }

    /**
     * @param string $methodName
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
