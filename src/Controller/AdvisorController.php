<?php

namespace App\Controller;

use App\Advisor\AdvisorRequest;
use App\Advisor\AdvisorUnavailableException;
use App\Advisor\InstrumentAdvisor;
use App\Repository\GuideRepository;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdvisorController extends AbstractController
{
    #[Route('/advisor', name: 'app_advisor', methods: ['GET'])]
    public function index(GuideRepository $guides, InstrumentAdvisor $advisor): Response
    {
        return $this->render('advisor/index.html.twig', [
            'categories' => $guides->findCategories(),
            'configured' => $advisor->isConfigured(),
        ]);
    }

    #[Route('/advisor/ask', name: 'app_advisor_ask', methods: ['POST'])]
    public function ask(Request $request, InstrumentAdvisor $advisor, LoggerInterface $logger): Response
    {
        $category = trim((string) $request->request->get('category'));
        $budgetRaw = trim((string) $request->request->get('budget'));
        $advisorRequest = new AdvisorRequest(
            category: '' !== $category ? $category : 'Beginners',
            budget: '' !== $budgetRaw ? (int) $budgetRaw : null,
            question: trim((string) $request->request->get('question')),
            experience: trim((string) $request->request->get('experience')) ?: null,
        );

        if (!$advisor->isConfigured()) {
            return $this->render('advisor/unavailable.html.twig');
        }

        $result = null;
        $error = null;
        try {
            $result = $advisor->advise($advisorRequest);
        } catch (AdvisorUnavailableException) {
            return $this->render('advisor/unavailable.html.twig');
        } catch (\Throwable $e) {
            $logger->error('Advisor request failed: {msg}', ['msg' => $e->getMessage(), 'exception' => $e]);
            $error = 'Der Berater konnte gerade keine Antwort erzeugen. Bitte versuche es erneut.';
        }

        return $this->render('advisor/result.html.twig', [
            'category' => $advisorRequest->category,
            // The model's summary is Markdown; render it to safe HTML.
            'summary' => $result ? $this->renderMarkdown($result->summary) : null,
            'products' => $result->products ?? [],
            'guides' => $result->guides ?? [],
            'articles' => $result->articles ?? [],
            'error' => $error,
        ]);
    }

    /**
     * Convert the model's Markdown reply to sanitized HTML: raw HTML in the
     * Markdown is escaped and unsafe link schemes (javascript:, etc.) are stripped.
     */
    private function renderMarkdown(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($markdown)->getContent();
    }
}
