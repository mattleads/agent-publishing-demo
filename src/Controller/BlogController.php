<?php

declare(strict_types=1);

namespace App\Controller;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The site's own rendering of articles. The bundle never renders anything; it only stores, exposes and counts.
 */
final class BlogController extends AbstractController
{
    public function __construct(private readonly ArticleStorageInterface $articles, private readonly SectionRegistry $sections)
    {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        $bySection = [];
        foreach ($this->sections->all() as $section) {
            $bySection[$section->name] = $this->articles->list(new ArticleFilter($section->name, null, ArticleState::Published, 1, 20));
        }

        return $this->render('blog/index.html.twig', ['bySection' => $bySection]);
    }

    // The route default below is all the bundle needs to count views of this page.
    #[Route('/blog/{slug}', name: 'blog_show', defaults: ['_agent_publishing_section' => 'blog', '_locale' => 'en'])]
    #[Route('/de/blog/{slug}', name: 'blog_show_de', defaults: ['_agent_publishing_section' => 'blog', '_locale' => 'de'])]
    #[Route('/docs/{slug}', name: 'docs_show', defaults: ['_agent_publishing_section' => 'docs', '_locale' => 'en'])]
    public function show(string $slug, string $_agent_publishing_section, string $_locale): Response
    {
        $article = $this->articles->findBySlug($_agent_publishing_section, $_locale, $slug);
        if (null === $article || ArticleState::Published !== $article->getState()) {
            throw $this->createNotFoundException();
        }
        $html = 'html' === $article->getFormat() ? $article->getBody() : (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'strip']))->convert($article->getBody());

        return $this->render('blog/show.html.twig', ['article' => $article, 'html' => $html]);
    }
}
