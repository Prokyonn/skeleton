<?php

declare(strict_types=1);

namespace App\RequestWorkflow;

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationDecision;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * Tells the reviewer which pages and articles the draft selects that readers cannot see yet.
 *
 * Sulu does not render a link to unpublished content, so this is not a broken-link guard, it is a
 * heads-up that part of the page will come up empty.
 *
 * This is the shape a project validator should copy:
 *
 * - It is handed only the request (resource key, id, locale) and loads everything else itself. Nothing
 *   here reads the HTTP request, so the answer is the same whether it runs inline or on a worker.
 * - It rejects with one plain-text comment listing everything it found, because the comment is read
 *   next to the reviewers' comments.
 * - It stays quiet about what it cannot see. Only selection properties write reference rows, so a link
 *   pasted into a text editor is invisible here. An approval means "found nothing", never "there is
 *   nothing".
 */
final class UnpublishedReferencesValidator implements RequestWorkflowValidatorInterface
{
    public function __construct(
        private readonly ReferenceRepositoryInterface $referenceRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ArticleRepositoryInterface $articleRepository,
    ) {
    }

    public function check(ValidationContext $context): ValidationDecision
    {
        $request = $context->request;
        $locale = $request->getLocale();

        $unpublished = [];
        foreach ($this->findReferences($request->getResourceKey(), $request->getResourceId(), $locale) as [$resourceKey, $resourceId]) {
            if (!$this->isPublished($resourceKey, $resourceId, $locale)) {
                $unpublished[] = $resourceKey . ' ' . $resourceId;
            }
        }

        if ([] === $unpublished) {
            return ValidationDecision::approve();
        }

        return ValidationDecision::reject(\sprintf(
            '%d selected %s not published: %s',
            \count($unpublished),
            1 === \count($unpublished) ? 'item is' : 'items are',
            \implode(', ', $unpublished),
        ));
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    private function findReferences(string $resourceKey, string $resourceId, string $locale): iterable
    {
        /** @var iterable<array{resourceKey?: string, resourceId?: string}> $rows */
        $rows = $this->referenceRepository->findFlatBy(
            [
                'referenceResourceKey' => $resourceKey,
                'referenceResourceId' => $resourceId,
                'referenceLocale' => $locale,
                // The draft is what is under review. Filtering on `live` would only see the links the
                // already published version has, which is the opposite of the question being asked.
                'referenceContext' => DimensionContentInterface::STAGE_DRAFT,
            ],
            [],
            ['resourceKey', 'resourceId'],
            true,
        );

        foreach ($rows as $row) {
            $referencedResourceKey = $row['resourceKey'] ?? null;
            $referencedResourceId = $row['resourceId'] ?? null;

            if (null !== $referencedResourceKey && null !== $referencedResourceId) {
                yield [$referencedResourceKey, $referencedResourceId];
            }
        }
    }

    /**
     * Anything this validator has no publication concept for counts as published, so it stays silent
     * rather than reporting a finding it cannot stand behind. Media is the obvious case: it has no
     * published state at all.
     */
    private function isPublished(string $resourceKey, string $resourceId, string $locale): bool
    {
        $filters = [
            'uuid' => $resourceId,
            'stage' => DimensionContentInterface::STAGE_LIVE,
            'locale' => $locale,
        ];

        return match ($resourceKey) {
            PageInterface::RESOURCE_KEY => null !== $this->pageRepository->findOneBy($filters),
            ArticleInterface::RESOURCE_KEY => null !== $this->articleRepository->findOneBy($filters),
            default => true,
        };
    }
}
